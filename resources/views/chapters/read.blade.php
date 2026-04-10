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
        $isFixedView = in_array($view, ['one', 'double'], true) && !$isManwha;

        // Header link points back to page 1 of the same chapter + current view
        $headerLink = route('chapters.page', [
            'media'   => $chapter->item_id,
            'chapter' => $chapter->chapter_number,
            'page'    => 1,
            'view'    => $view,
        ]);

        // View switcher base params
        $baseParams = ['media' => $chapter->item_id, 'chapter' => $chapter->chapter_number];

        // Build landscape-aware spreads for double-page mode.
        $pageObjectsByNumber = $chapter->pages->sortBy('page_number')->keyBy('page_number');
        $pageNums = $pageObjectsByNumber->keys()->values();
        $nums = $pageNums->all();

        $getChapterPageDimensions = static function (?string $filePath): ?array {
            if (!$filePath) {
                return null;
            }

            $absolutePath = public_path('storage/' . ltrim($filePath, '/'));
            if (!is_file($absolutePath)) {
                return null;
            }

            $size = @getimagesize($absolutePath);
            if ($size === false || empty($size[0]) || empty($size[1])) {
                return null;
            }

            return [(int) $size[0], (int) $size[1]];
        };

        $pageIsLandscape = [];
        foreach ($pageObjectsByNumber as $number => $page) {
            $dimensions = $getChapterPageDimensions($page->file_path ?? null);
            $pageIsLandscape[$number] = $dimensions ? ($dimensions[0] > $dimensions[1]) : false;
        }

        $spreads = [];
        for ($i = 0; $i < count($nums);) {
            $currentNum = $nums[$i] ?? null;
            if ($currentNum === null) {
                break;
            }

            if ($pageIsLandscape[$currentNum] ?? false) {
                $spreads[] = ['pages' => [$currentNum]];
                $i++;
                continue;
            }

            $nextNum = $nums[$i + 1] ?? null;
            if ($nextNum !== null && !($pageIsLandscape[$nextNum] ?? false)) {
                $spreads[] = ['pages' => [$currentNum, $nextNum]];
                $i += 2;
                continue;
            }

            $spreads[] = ['pages' => [$currentNum]];
            $i++;
        }

        $currentSpreadIndex = 0;
        foreach ($spreads as $index => $spread) {
            if (in_array($pageNumber, $spread['pages'], true)) {
                $currentSpreadIndex = $index;
                break;
            }
        }

        $currentSpreadPages = $spreads[$currentSpreadIndex]['pages'] ?? [$pageNumber];
        $spreadA = $currentSpreadPages[0] ?? null;
        $spreadB = $currentSpreadPages[1] ?? null;

        // Right-to-left style swap (larger page left, smaller right)
        if ($spreadA !== null && $spreadB !== null && $spreadA < $spreadB) {
            $leftNum = $spreadB;
            $rightNum = $spreadA;
        } else {
            $leftNum = $spreadA;
            $rightNum = $spreadB;
        }

        $prevSpread = $currentSpreadIndex > 0 ? ($spreads[$currentSpreadIndex - 1] ?? null) : null;
        $nextSpread = ($currentSpreadIndex + 1) < count($spreads) ? ($spreads[$currentSpreadIndex + 1] ?? null) : null;

        $prevPairPage = $prevSpread['pages'][0] ?? null;
        $nextPairPage = $nextSpread['pages'][0] ?? null;

        // Pair links (fallback to controller links if needed)
        $prevPairLink = $prevPairPage
            ? route('chapters.page', ['media' => $chapter->item_id, 'chapter' => $chapter->chapter_number, 'page' => $prevPairPage, 'view' => 'double'])
            : ($prevChapterLink ?? null);

        $nextPairLink = $nextPairPage
            ? route('chapters.page', ['media' => $chapter->item_id, 'chapter' => $chapter->chapter_number, 'page' => $nextPairPage, 'view' => 'double'])
            : ($nextChapterLink ?? null);

        $chapterDisplay = rtrim(rtrim((string)$chapter->chapter_number, '0'), '.');
        $readerTitleWithChapter = $itemTitle.' - Chapter '.$chapterDisplay;
    @endphp

    <style>
        body.reader-mode {
            background: #ffffff;
            color: #111827;
        }
        body.reader-mode.reader-fixed {
            overflow: hidden;
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
            font-size: 1.125rem;
            font-weight: 700;
            color: #ffffff;
            text-decoration: none;
        }
        .reader-title:hover {
            text-decoration: underline;
        }
        .reader-title-chapter {
            font-size: 1rem;
            font-weight: 700;
            margin-left: 0.6rem;
        }
        .reader-shell {
            min-height: 100vh;
            padding: 0;
        }
        .reader-shell-fixed {
            min-height: 100dvh;
            height: 100dvh;
            overflow: hidden;
        }
        .reader-content {
            width: min(1200px, calc(100vw - 24px));
            margin: 0 auto;
        }
        .reader-content-fixed {
            width: 100vw;
            max-width: none;
            height: 100dvh;
            margin: 0;
        }
        .reader-content-fixed.space-y-6 > :not([hidden]) ~ :not([hidden]) {
            margin-top: 0;
        }
        .reader-scroll-stack {
            display: flex;
            flex-direction: column;
            gap: 0;
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
        .reader-content-fixed .reader-full {
            min-height: 100dvh;
            height: 100dvh;
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
        .reader-content-fixed .reader-full .reader-img {
            height: 100dvh;
            max-height: 100dvh;
            max-width: 100vw;
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
        .reader-content-fixed .dual-full {
            height: 100dvh;
        }
        .dual-page > div {
            flex: 0 0 50%;
            max-width: 50%;
        }
        .reader-content-fixed .dual-page > div {
            height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
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
        .reader-content-fixed .dual-full img {
            height: 100dvh;
            max-height: 100dvh;
            max-width: 50vw;
        }
        .reader-bottom-panel {
            position: fixed;
            left: 50%;
            bottom: 24px;
            transform: translate(-50%, 24px);
            padding: 0;
            background: transparent;
            border-radius: 0;
            box-shadow: none;
            opacity: 0;
            transition: opacity 0.18s ease, transform 0.18s ease;
            pointer-events: none;
            z-index: 60;
        }
        .reader-bottom-inner {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0;
        }
        .reader-bottom-dock {
            display: flex;
            align-items: center;
            justify-content: center;
            filter: drop-shadow(0 12px 24px rgba(0, 0, 0, 0.25));
        }
        .reader-arrow-square {
            width: 58px;
            height: 60px;
            background: #ab2328;
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 1.55rem;
            font-weight: 400;
            line-height: 1;
            transition: none;
            border: none;
            position: relative;
            z-index: 1;
            -webkit-tap-highlight-color: transparent;
            user-select: none;
        }
        .reader-arrow-icon {
            width: 33px;
            height: 33px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2.9;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .reader-arrow-icon.is-right {
            transform: scaleX(-1);
            transform-origin: center;
        }
        .reader-arrow-square.left {
            border-radius: 10px 0 0 10px;
            border-right: none;
        }
        .reader-arrow-square.right {
            border-radius: 0 10px 10px 0;
            border-left: none;
        }
        .reader-arrow-square:not(.disabled):hover,
        .reader-arrow-square:not(.disabled):active,
        .reader-arrow-square:not(.disabled):focus,
        .reader-arrow-square:not(.disabled):focus-visible {
            background: #ab2328;
            color: #ffffff;
            text-decoration: none;
            box-shadow: none;
            outline: none;
        }
        .reader-arrow-square.disabled {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        .reader-count-square {
            min-width: 138px;
            width: max-content;
            height: 88px;
            background: #ab2328;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 0.35rem 0.85rem 0.25rem;
            line-height: 1;
            flex: 0 0 auto;
            /* Outside side shading to visually separate center block from arrow blocks */
            box-shadow: -8px 0 10px -8px rgba(0, 0, 0, 0.38),
                        8px 0 10px -8px rgba(0, 0, 0, 0.38);
            position: relative;
            z-index: 2;
        }
        .reader-count-label {
            font-size: 0.64rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            opacity: 0.9;
            margin-bottom: 0.1rem;
            transform: translateY(-0.42rem);
        }
        .reader-count-value {
            font-size: 2.35rem;
            font-weight: 400;
            letter-spacing: -0.02em;
            white-space: nowrap;
        }
        .reader-count-pair {
            display: inline-flex;
            align-items: baseline;
            gap: 0.92rem;
            font-size: 2.35rem;
            font-weight: 400;
            letter-spacing: -0.02em;
            white-space: nowrap;
        }
        .reader-count-sep {
            display: inline-block;
            transform: translateY(-0.14em);
            font-size: 1em;
            line-height: 1;
        }
        .reader-count-sub {
            margin-top: 0.2rem;
            font-size: 0.56rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            opacity: 0.9;
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.body.classList.add('reader-mode');
            if (@json($isFixedView)) {
                document.body.classList.add('reader-fixed');
            }
        });
    </script>

    <div class="reader-hover-zone"></div>
    <div class="reader-float-nav">
        <div class="reader-nav-panel">
            <div class="reader-nav-inner">
                <div class="flex items-center space-x-2">
                    <a href="{{ route('home') }}" class="reader-logo uppercase">DORDIELIST</a>
                    <span class="h-5 w-px bg-white/40"></span>
                    <div class="flex items-center">
                        <a href="{{ $itemUrl }}" class="reader-title" title="{{ $itemTitle }}">
                            {{ shortTitle($itemTitle, 40) }}
                        </a>
                        <span class="reader-title-chapter text-gray-300">Chapter {{ $chapterDisplay }}</span>
                    </div>
                </div>
                <div class="flex items-center space-x-2 text-white text-sm">
                    @if(!$isManwha)
                        <a href="{{ route('chapters.page', array_merge($baseParams, ['page' => $pageNumber, 'view' => 'scroll'])) }}"
                           class="control-btn {{ $view === 'scroll' ? 'active' : '' }}"
                           aria-label="Scroll view">
                            <span class="sr-only">Scroll</span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="butt" stroke-linejoin="miter">
                                <path d="M7 4v5M7 9h10M17 4v5M7 20v-5M7 15h10M17 20v-5" />
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
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 6v12" />
                                <path d="M12 7c-2-1.4-4.5-2-7-2v12c2.5 0 5 0.6 7 2" />
                                <path d="M12 7c2-1.4 4.5-2 7-2v12c-2.5 0-5 0.6-7 2" />
                            </svg>
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="reader-shell {{ $isFixedView ? 'reader-shell-fixed' : '' }}">
        <div class="reader-content space-y-6 {{ $isFixedView ? 'reader-content-fixed' : '' }}">
            {{-- ============== SCROLL MODE ============== --}}
            @if($view === 'scroll')
                <div class="reader-scroll-stack">
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
                    $hasSingleSpreadPage = ($isLeftImg xor $isRightImg);
                    $singleSpreadUrl = $isLeftImg ? $leftUrl : $rightUrl;
                    $singleSpreadNum = $isLeftImg ? $leftNum : $rightNum;

                    // Pair-aware targets for this view
                    $doubleNext = $nextPairLink ?? null;
                    $doublePrev = $prevPairLink ?? null;

                    $hasDoubleNext = !empty($doubleNext);
                    $hasDoublePrev = !empty($doublePrev);
                    $doubleJustify = $hasDoubleNext && $hasDoublePrev ? 'between' : ($hasDoubleNext ? 'start' : 'end');
                @endphp

                <div class="reader-page reader-full">
                    @if($hasSingleSpreadPage)
                        <img
                            src="{{ $singleSpreadUrl }}"
                            alt="Page {{ $singleSpreadNum }}"
                            class="zoomable reader-img z-10"
                        >
                    @else
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
                    @endif

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
        $bottomLeftAction = null;
        $bottomRightAction = null;

        if ($view === 'scroll') {
            $leftBottom  = $nextChapterLink;
            $rightBottom = $prevChapterLink;
            if ($isManwha) {
                $leftBottom  = $prevChapterLink;
                $rightBottom = $nextChapterLink;
            }
            $bottomLeftLink  = $leftBottom;
            $bottomRightLink = $rightBottom;
            $bottomLeftAction = $isManwha ? 'prev' : 'next';
            $bottomRightAction = $isManwha ? 'next' : 'prev';
        } elseif ($view === 'one' && !$isManwha) {
            $bottomLeftLink  = $nextLink;
            $bottomRightLink = $prevLink;
            $bottomLeftAction = 'next';
            $bottomRightAction = 'prev';
        } elseif ($view === 'double' && !$isManwha) {
            $doubleNext = $nextPairLink ?? null;
            $doublePrev = $prevPairLink ?? null;
            $bottomLeftLink  = $doubleNext;
            $bottomRightLink = $doublePrev;
            $bottomLeftAction = 'next';
            $bottomRightAction = 'prev';
        }
        $nextArrowClass = $isManwha ? 'is-right' : 'is-left';
        $prevArrowClass = $isManwha ? 'is-left' : 'is-right';
        $bottomInfoLink = route('media.show', ['id' => $chapter->item_id]);

        if ($view === 'one' && !$isManwha) {
            $bottomMainLabel = 'Page';
            $bottomMainValue = (string)$pageNumber;
            $bottomSub = null;
        } elseif ($view === 'double' && !$isManwha) {
            $bottomMainLabel = 'Page';
            $bottomMainValue = $rightNum ? ($leftNum.' | '.$rightNum) : (string)$leftNum;
            $bottomSub = null;
        } else {
            $bottomMainLabel = 'Chapter';
            $bottomMainValue = $chapterDisplay;
            $bottomSub = null;
        }
    @endphp

    <div class="reader-bottom-hover"></div>
    <div class="reader-bottom-float">
        <div class="reader-bottom-panel">
            <div class="reader-bottom-inner">
                <div class="reader-bottom-dock">
                @if($bottomLeftLink)
                    <a href="{{ $bottomLeftLink }}" class="reader-arrow-square left" aria-label="{{ $bottomLeftAction === 'next' ? 'Next' : 'Previous' }}">
                        <svg class="reader-arrow-icon {{ $bottomLeftAction === 'next' ? $nextArrowClass : $prevArrowClass }}" viewBox="0 0 24 24" aria-hidden="true">
                            <polyline points="15 4 7 12 15 20"></polyline>
                        </svg>
                    </a>
                @else
                    <span class="reader-arrow-square left disabled" aria-hidden="true">
                        <svg class="reader-arrow-icon {{ $bottomLeftAction === 'next' ? $nextArrowClass : $prevArrowClass }}" viewBox="0 0 24 24">
                            <polyline points="15 4 7 12 15 20"></polyline>
                        </svg>
                    </span>
                @endif

                @if($view === 'scroll')
                    <a href="{{ $bottomInfoLink }}" class="reader-count-square" aria-label="View item info" title="View item info">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-[#2f4858]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8h.01M11 12h1v4h1m-1 5a9 9 0 100-18 9 9 0 000 18z" />
                        </svg>
                    </a>
                @else
                    <div class="reader-count-square">
                        <span class="reader-count-label">{{ $bottomMainLabel }}</span>
                        @if($view === 'double' && !$isManwha && $rightNum)
                            <span class="reader-count-pair">
                                <span>{{ $leftNum }}</span>
                                <span class="reader-count-sep">|</span>
                                <span>{{ $rightNum }}</span>
                            </span>
                        @else
                            <span class="reader-count-value">{{ $bottomMainValue }}</span>
                        @endif
                        @if($bottomSub)
                            <span class="reader-count-sub">{{ $bottomSub }}</span>
                        @endif
                    </div>
                @endif

                @if($bottomRightLink)
                    <a href="{{ $bottomRightLink }}" class="reader-arrow-square right" aria-label="{{ $bottomRightAction === 'next' ? 'Next' : 'Previous' }}">
                        <svg class="reader-arrow-icon {{ $bottomRightAction === 'next' ? $nextArrowClass : $prevArrowClass }}" viewBox="0 0 24 24" aria-hidden="true">
                            <polyline points="15 4 7 12 15 20"></polyline>
                        </svg>
                    </a>
                @else
                    <span class="reader-arrow-square right disabled" aria-hidden="true">
                        <svg class="reader-arrow-icon {{ $bottomRightAction === 'next' ? $nextArrowClass : $prevArrowClass }}" viewBox="0 0 24 24">
                            <polyline points="15 4 7 12 15 20"></polyline>
                        </svg>
                    </span>
                @endif
                </div>
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

            if (e.key === 'ArrowLeft'  && leftTarget)  window.location.replace(leftTarget);
            if (e.key === 'ArrowRight' && rightTarget) window.location.replace(rightTarget);
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
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a[href]');
            if (!link) return;
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            if (link.target && link.target !== '_self') return;

            let url;
            try {
                url = new URL(link.href, window.location.origin);
            } catch (err) {
                return;
            }

            const isSameOrigin = url.origin === window.location.origin;
            const hasReaderView = url.searchParams.has('view');
            const isReaderPath = /\/chapters\//.test(url.pathname) || /\/page\/\d+/.test(url.pathname);
            if (!(isSameOrigin && hasReaderView && isReaderPath)) return;

            e.preventDefault();
            window.location.replace(url.toString());
        });

    </script>
@endsection
