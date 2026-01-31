@extends('layouts.app')

@section('content')
    @php

        if (!function_exists('shortTitle')) {
            function shortTitle($title, $maxLen = 25) {
                if (strlen($title) <= $maxLen) {
                    return $title;
                }
                return substr($title, 0, $maxLen - 1) . '…';
            }
        }
        $allowedExts = ['jpg','jpeg','png','gif','webp'];

        // --- FORCE VIEW FOR MANWHA ---
        $view = request('view', 'one');
        $isManwha = $isManwha ?? false; // passed from controller
        if ($isManwha) {
            $view = 'scroll';
        }

        // Header link points back to page 1 of the same chapter + current view
        $headerLink = route('chapters.page', [
            'media'   => $chapter->item_id,
            'chapter' => $chapter->chapter_number,
            'page'    => 1,
            'view'    => $view,
        ]);

        // View switcher base params
        $baseParams = ['media' => $chapter->item_id, 'chapter' => $chapter->chapter_number];

        // For Double view we still compute the pair (used only when !$isManwha)
        $pageNums      = $chapter->pages->pluck('page_number')->sort()->values();
        $nums          = $pageNums->all();
        $currentIndex  = array_search($pageNumber, $nums, true);
        $pairStart     = ($currentIndex % 2 === 0) ? $currentIndex : ($currentIndex - 1);

        $a = $nums[$pairStart]     ?? null;
        $b = $nums[$pairStart + 1] ?? null;

        // Right-to-left style swap (larger page left, smaller right)
        if ($a !== null && $b !== null && $a < $b) {
            $leftNum  = $b;
            $rightNum = $a;
        } else {
            $leftNum  = $a;
            $rightNum = $b;
        }

        // Pair nav targets (for double view)
        $prevPairPage = null;
        $nextPairPage = null;

        $prevPairStart = $pairStart - 2;
        if ($prevPairStart >= 0) {
            $prevPairPage = $nums[$prevPairStart] ?? null;
        }

        $nextPairStart = $pairStart + 2;
        if ($nextPairStart < count($nums)) {
            $nextPairPage = $nums[$nextPairStart] ?? null;
        }

        // Pair links (fallback to controller links if needed)
        $prevPairLink = $prevPairPage
            ? route('chapters.page', ['media' => $chapter->item_id, 'chapter' => $chapter->chapter_number, 'page' => $prevPairPage, 'view' => 'double'])
            : ($prevLink ?? null);

        $nextPairLink = $nextPairPage
            ? route('chapters.page', ['media' => $chapter->item_id, 'chapter' => $chapter->chapter_number, 'page' => $nextPairPage, 'view' => 'double'])
            : ($nextLink ?? null);
    @endphp

    <style>
        body.reader-mode {
            background: #ffffff;
            color: #111827;
        }
        body.reader-mode nav,
        body.reader-mode footer {
            display: none !important;
        }
        .reader-hover-zone {
            position: fixed;
            inset: 0 0 auto 0;
            height: 220px; /* even larger trigger so it shows when you're near the top */
            z-index: 55;
            pointer-events: auto;
        }
        .reader-bottom-hover {
            position: fixed;
            inset: auto 0 0 0;
            height: 160px;
            z-index: 55;
            pointer-events: auto;
        }
        .reader-float-nav {
            position: fixed;
            inset: 0 0 auto 0;
            z-index: 60;
            pointer-events: none;
        }
        .reader-nav-panel {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            padding: 0;
            background: #ab2328; /* tailwind flatRed */
            box-shadow: none;
            opacity: 0;
            transform: translateY(-10px);
            transition: opacity 0.18s ease, transform 0.18s ease;
            pointer-events: none;
        }
        .reader-nav-inner {
            width: 100%;
            margin: 0 auto;
            padding: 1rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .reader-hover-zone:hover + .reader-float-nav .reader-nav-panel,
        .reader-float-nav:hover .reader-nav-panel {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }
        .reader-bottom-hover:hover + .reader-bottom-float .reader-bottom-panel,
        .reader-bottom-float:hover .reader-bottom-panel {
            opacity: 1;
            transform: translate(-50%, 0);
            pointer-events: auto;
        }
        body.reader-bars-visible .reader-nav-panel {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }
        body.reader-bars-visible .reader-bottom-panel {
            opacity: 1;
            transform: translate(-50%, 0);
            pointer-events: auto;
        }
        .reader-logo {
            font-weight: 700;
            letter-spacing: normal;
            color: #ffffff;
            font-size: 1.5rem; /* text-2xl */
            padding-left: 0;
            padding-right: 0;
        }
        .reader-title {
            font-size: 1rem;
            font-weight: 700;
            color: #ffffff;
            text-decoration: none;
        }
        .reader-title:hover {
            text-decoration: underline;
        }
        .reader-shell {
            min-height: 100vh;
            padding: 0;
        }
        .reader-content {
            width: min(1200px, calc(100vw - 24px));
            margin: 0 auto;
        }
        .reader-controls {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.75rem; /* keep control groups near each other */
            margin-bottom: 1rem;
        }
        .control-group {
            display: flex;
            align-items: center;
            gap: 0.3rem; /* tighter button spacing */
        }
        .control-btn {
            padding: 0.35rem;
            border-radius: 9999px;
            border: none;
            background: transparent;
            color: #ffffff;
            transition: opacity 0.12s ease; /* remove hover lift animation */
            opacity: 0.75;
            line-height: 0; /* prevent icon height from affecting navbar */
        }
        .control-btn svg {
            width: 22px;  /* small box */
            height: 22px;
            stroke-width: 2.2; /* slightly slimmer lines */
            transform: scale(1.8); /* visually larger without changing layout height */
            transform-origin: center;
        }
        .control-btn:hover {
            transform: none;
            opacity: 1;
        }
        .control-btn.active {
            color: #ffffff;
            opacity: 1;
        }
        .reader-page {
            position: relative;
            width: 100%;
            overflow: hidden;
        }
        .reader-full {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }
        .reader-img {
            width: 100%;
            height: auto;
            object-fit: contain;
            display: block;
            margin: 0 auto;
            box-shadow: none;
            border-radius: 0;
        }
        .reader-full .reader-img {
            height: 100vh;
            width: auto;
            max-width: 100%;
            object-fit: contain;
        }
        .jump-row {
            display: flex;
            gap: 0.5rem;
        }
        .jump-row.start { justify-content: flex-start; }
        .jump-row.end   { justify-content: flex-end; }
        .jump-row.between { justify-content: space-between; }
        .jump-btn {
            padding: 0.55rem 1.1rem;
            border-radius: 8px;
            background: #ffffff;
            color: #ab2328;
            font-weight: 700;
            transition: transform 0.12s ease, box-shadow 0.12s ease, background 0.12s ease;
        }
        .jump-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.18);
            background: #f5f5f5;
        }
        .dual-page {
            display: flex;
            justify-content: center;
            gap: 0;
            align-items: center;
            flex-wrap: nowrap;
        }
        .dual-full {
            height: 100vh;
            align-items: center;
        }
        .dual-page > div {
            flex: 0 0 50%;
            max-width: 50%;
        }
        .dual-page img {
            width: 100%;
            height: auto;
        }
        .dual-full img {
            height: 100vh;
            width: auto;
            max-width: 100%;
            object-fit: contain;
        }
        .reader-bottom-panel {
            position: fixed;
            left: 50%;
            bottom: 24px;
            transform: translate(-50%, 24px);
            padding: 0.5rem 0.9rem;
            background: #ab2328; /* flatRed pill */
            border-radius: 12px;
            box-shadow: 0 18px 30px rgba(0, 0, 0, 0.18);
            opacity: 0;
            transition: opacity 0.18s ease, transform 0.18s ease;
            pointer-events: none;
            z-index: 60;
        }
        .reader-bottom-inner {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.65rem;
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.body.classList.add('reader-mode');
        });
    </script>

    <div class="reader-hover-zone"></div>
    <div class="reader-float-nav">
        <div class="reader-nav-panel">
            <div class="reader-nav-inner">
                <div class="flex items-center space-x-2">
                    <a href="{{ route('home') }}" class="reader-logo uppercase">DORDIELIST</a>
                    <span class="h-5 w-px bg-white/40"></span>
                    <a href="{{ $itemUrl }}" class="reader-title hover:underline" title="{{ $itemTitle }}">
                        {{ shortTitle($itemTitle, 40) }}
                    </a>
                </div>
                <div class="flex items-center space-x-2 text-white text-sm">
                    @if(!$isManwha)
                        <a href="{{ route('chapters.page', array_merge($baseParams, ['page' => $pageNumber, 'view' => 'scroll'])) }}"
                           class="control-btn {{ $view === 'scroll' ? 'active' : '' }}"
                           aria-label="Scroll view">
                            <span class="sr-only">Scroll</span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="6" y="4" width="12" height="16" rx="2" />
                                <path d="M12 8v8m-3-3h6" />
                            </svg>
                        </a>
                        <a href="{{ route('chapters.page', array_merge($baseParams, ['page' => $pageNumber, 'view' => 'one'])) }}"
                           class="control-btn {{ $view === 'one' ? 'active' : '' }}"
                           aria-label="Single page view">
                            <span class="sr-only">One</span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="6" y="4" width="12" height="16" rx="2" />
                            </svg>
                        </a>
                        <a href="{{ route('chapters.page', array_merge($baseParams, ['page' => $pageNumber, 'view' => 'double'])) }}"
                           class="control-btn {{ $view === 'double' ? 'active' : '' }}"
                           aria-label="Double page view">
                            <span class="sr-only">Double</span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="4" y="5" width="7" height="14" rx="2" />
                                <rect x="13" y="5" width="7" height="14" rx="2" />
                            </svg>
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="reader-shell">
        <div class="reader-content space-y-6">
            {{-- ============== SCROLL MODE ============== --}}
            @if($view === 'scroll')
                <div class="space-y-6">
                    @foreach($chapter->pages as $p)
                        @php
                            $ext = strtolower(pathinfo($p->file_path, PATHINFO_EXTENSION) ?? '');
                            $isImage = in_array($ext, $allowedExts, true);
                            $url = $isImage ? asset('storage/'.$p->file_path) : null;
                        @endphp

                        @if($isImage)
                            <div class="reader-page">
                                <img
                                    src="{{ $url }}"
                                    alt="Page {{ $p->page_number }}"
                                    class="zoomable reader-img"
                                >
                            </div>
                        @endif
                    @endforeach
                </div>

                {{-- ============== ONE PAGE MODE ============== --}}
            @elseif($view === 'one' && !$isManwha)
                @php
                    $current   = $chapter->pages->firstWhere('page_number', $pageNumber);
                    $extOne    = strtolower(pathinfo(optional($current)->file_path ?? '', PATHINFO_EXTENSION));
                    $isImage   = in_array($extOne, $allowedExts, true);
                    $singleUrl = $isImage ? $pageUrl : null; // from controller
                @endphp

                @if($isImage)
                    <div class="reader-page reader-full">
                        <img
                            src="{{ $singleUrl }}"
                            alt="Page {{ $pageNumber }}"
                            class="zoomable reader-img z-10"
                        >

                        {{-- Half-screen click zones: LEFT = NEXT, RIGHT = PREVIOUS --}}
                        @if($nextLink)
                            <a href="{{ $nextLink }}">
                                <div class="absolute inset-y-0 left-0 w-1/2 z-20" style="cursor:pointer;"></div>
                            </a>
                        @endif
                        @if($prevLink)
                            <a href="{{ $prevLink }}">
                                <div class="absolute inset-y-0 right-0 w-1/2 z-20" style="cursor:pointer;"></div>
                            </a>
                        @endif
                    </div>
                @endif

                {{-- ============== DOUBLE PAGE MODE ============== --}}
            @elseif($view === 'double' && !$isManwha)
                @php
                    $leftObj   = $leftNum  !== null ? $chapter->pages->firstWhere('page_number', $leftNum)  : null;
                    $rightObj  = $rightNum !== null ? $chapter->pages->firstWhere('page_number', $rightNum) : null;

                    $extLeft   = strtolower(pathinfo(optional($leftObj)->file_path ?? '', PATHINFO_EXTENSION));
                    $extRight  = strtolower(pathinfo(optional($rightObj)->file_path ?? '', PATHINFO_EXTENSION));

                    $isLeftImg  = in_array($extLeft,  $allowedExts, true);
                    $isRightImg = in_array($extRight, $allowedExts, true);

                    $leftUrl    = $isLeftImg  ? asset('storage/'.$leftObj->file_path)  : null;
                    $rightUrl   = $isRightImg ? asset('storage/'.$rightObj->file_path) : null;

                    // Pair-aware targets for this view
                    $doubleNext = $nextPairLink ?? $nextLink ?? null;
                    $doublePrev = $prevPairLink ?? $prevLink ?? null;

                    $hasDoubleNext = !empty($doubleNext);
                    $hasDoublePrev = !empty($doublePrev);
                    $doubleJustify = $hasDoubleNext && $hasDoublePrev ? 'between' : ($hasDoubleNext ? 'start' : 'end');
                @endphp

                <div class="reader-page reader-full">
                    <div class="dual-page dual-full">
                        @if($isLeftImg)
                            <div class="relative overflow-hidden">
                                <img
                                    src="{{ $leftUrl }}"
                                    alt="Page {{ $leftNum }}"
                                    class="zoomable reader-img"
                                >
                            </div>
                        @endif

                        @if($isRightImg)
                            <div class="relative overflow-hidden">
                                <img
                                    src="{{ $rightUrl }}"
                                    alt="Page {{ $rightNum }}"
                                    class="zoomable reader-img"
                                >
                            </div>
                        @endif
                    </div>

                    {{-- LEFT = NEXT (pair), RIGHT = PREVIOUS (pair) --}}
                    @if($doubleNext)
                        <a href="{{ $doubleNext }}">
                            <div class="absolute inset-y-0 left-0 w-1/2 z-20" style="cursor:pointer;"></div>
                        </a>
                    @endif
                    @if($doublePrev)
                        <a href="{{ $doublePrev }}">
                            <div class="absolute inset-y-0 right-0 w-1/2 z-20" style="cursor:pointer;"></div>
                        </a>
                    @endif
                </div>

            @endif

        </div>
    </div>

    @php
        $bottomLeftLink = null;
        $bottomRightLink = null;
        $bottomLeftLabel = null;
        $bottomRightLabel = null;

        if ($view === 'scroll') {
            $leftBottom  = $nextChapterLink;
            $rightBottom = $prevChapterLink;
            if ($isManwha) {
                $leftBottom  = $prevChapterLink;
                $rightBottom = $nextChapterLink;
            }
            $bottomLeftLink  = $leftBottom;
            $bottomRightLink = $rightBottom;
            $bottomLeftLabel = $isManwha ? 'Prev Chapter' : 'Next Chapter';
            $bottomRightLabel = $isManwha ? 'Next Chapter' : 'Prev Chapter';
        } elseif ($view === 'one' && !$isManwha) {
            $bottomLeftLink  = $nextLink;
            $bottomRightLink = $prevLink;
            $bottomLeftLabel = 'Next';
            $bottomRightLabel = 'Prev';
        } elseif ($view === 'double' && !$isManwha) {
            $doubleNext = $nextPairLink ?? $nextLink ?? null;
            $doublePrev = $prevPairLink ?? $prevLink ?? null;
            $bottomLeftLink  = $doubleNext;
            $bottomRightLink = $doublePrev;
            $bottomLeftLabel = 'Next';
            $bottomRightLabel = 'Prev';
        }
    @endphp

    <div class="reader-bottom-hover"></div>
    <div class="reader-bottom-float">
        <div class="reader-bottom-panel">
            <div class="reader-bottom-inner">
                @if($bottomLeftLink && $bottomLeftLabel)
                    <a href="{{ $bottomLeftLink }}" class="jump-btn">{{ $bottomLeftLabel }}</a>
                @endif
                <span class="text-white font-bold text-sm px-2">
                    Ch {{ rtrim(rtrim($chapter->chapter_number, '0'), '.') }}
                </span>
                @if($bottomRightLink && $bottomRightLabel)
                    <a href="{{ $bottomRightLink }}" class="jump-btn">{{ $bottomRightLabel }}</a>
                @endif
            </div>
        </div>
    </div>

    <script>
        // Arrow keys: flip for Manwha
        document.addEventListener('keydown', (e) => {
            const tag = (document.activeElement && document.activeElement.tagName) || '';
            if (tag === 'INPUT' || tag === 'TEXTAREA') return;

            const isDouble = @json($view === 'double');
            const nextPair = @json($nextPairLink ?? null);
            const prevPair = @json($prevPairLink ?? null);
            const nextPage = @json($nextLink ?? null);
            const prevPage = @json($prevLink ?? null);
            const isManwha = @json($isManwha);

            // Defaults (Manga): LEFT = NEXT, RIGHT = PREV
            let leftTarget  = isDouble ? (nextPair || nextPage) : nextPage;
            let rightTarget = isDouble ? (prevPair || prevPage) : prevPage;

            // Flip for Manwha: LEFT = PREV, RIGHT = NEXT
            if (isManwha) {
                const tmp = leftTarget;
                leftTarget  = rightTarget;
                rightTarget = tmp;
            }

            if (e.key === 'ArrowLeft'  && leftTarget)  window.location.href = leftTarget;
            if (e.key === 'ArrowRight' && rightTarget) window.location.href = rightTarget;
        });

        // Show top & bottom bars together when hovering either
        const hoverTargets = [
            document.querySelector('.reader-hover-zone'),
            document.querySelector('.reader-float-nav'),
            document.querySelector('.reader-bottom-hover'),
            document.querySelector('.reader-bottom-float'),
        ].filter(Boolean);

        let hoverCount = 0;
        const updateBars = (delta) => {
            hoverCount = Math.max(0, hoverCount + delta);
            if (hoverCount > 0) {
                document.body.classList.add('reader-bars-visible');
            } else {
                document.body.classList.remove('reader-bars-visible');
            }
        };

        hoverTargets.forEach(el => {
            el.addEventListener('mouseenter', () => updateBars(1));
            el.addEventListener('mouseleave', () => updateBars(-1));
        });

    </script>
@endsection
