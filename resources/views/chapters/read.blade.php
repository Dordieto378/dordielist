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
        $shouldBlur  = ! Auth::check();
        $allowedExts = ['jpg','jpeg','png','gif','webp'];

        $view = request('view', 'one');
        $isManwha = $isManwha ?? false;
        if ($isManwha) {
            $view = 'scroll';
        }

        $headerLink = route('chapters.page', [
            'media'   => $chapter->item_id,
            'chapter' => $chapter->chapter_number,
            'page'    => 1,
            'view'    => $view,
        ]);

        $baseParams = ['media' => $chapter->item_id, 'chapter' => $chapter->chapter_number];

        $pageNums      = $chapter->pages->pluck('page_number')->sort()->values();
        $nums          = $pageNums->all();
        $currentIndex  = array_search($pageNumber, $nums, true);
        $pairStart     = ($currentIndex % 2 === 0) ? $currentIndex : ($currentIndex - 1);

        $a = $nums[$pairStart]     ?? null;
        $b = $nums[$pairStart + 1] ?? null;

        if ($a !== null && $b !== null && $a < $b) {
            $leftNum  = $b;
            $rightNum = $a;
        } else {
            $leftNum  = $a;
            $rightNum = $b;
        }

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

        $prevPairLink = $prevPairPage
            ? route('chapters.page', ['media' => $chapter->item_id, 'chapter' => $chapter->chapter_number, 'page' => $prevPairPage, 'view' => 'double'])
            : ($prevLink ?? null);

        $nextPairLink = $nextPairPage
            ? route('chapters.page', ['media' => $chapter->item_id, 'chapter' => $chapter->chapter_number, 'page' => $nextPairPage, 'view' => 'double'])
            : ($nextLink ?? null);
    @endphp

    <div class="flex flex-col items-center py-[4rem] mt-12">
        <div class="w-[1280px] bg-white shadow-sm rounded-md p-6 ml-[0.5rem] space-y-6">
            <div class="relative flex items-center mb-4">
                <div class="flex-1">
                    <h1 class="text-2xl font-bold text-red-600">
                        <a href="{{ $itemUrl }}" class="hover:underline">
                            {{ shortTitle($itemTitle, 20) }}
                        </a>
                        - {{ shortTitle($chapter->chapter_title, 20) }}
                    </h1>
                </div>

                <div class="absolute inset-x-0 flex justify-center pointer-events-none">
                    <button onclick="zoomOut()" class="pointer-events-auto px-3 py-1 bg-gray-200 text-gray-700 rounded transition" title="Zoom Out">-</button>
                    <button onclick="zoomIn()"  class="pointer-events-auto ml-2 px-3 py-1 bg-gray-200 text-gray-700 rounded transition" title="Zoom In">+</button>
                </div>

                <div class="flex-1 flex justify-end space-x-4">
                    @if(!$isManwha)
                        <a href="{{ route('chapters.page', array_merge($baseParams, ['page' => $pageNumber, 'view' => 'scroll'])) }}"
                           class="p-2 rounded {{ $view === 'scroll' ? 'bg-gray-800 text-white' : 'bg-gray-200 text-gray-700' }}"
                           title="Scroll Mode">Scroll</a>
                        <a href="{{ route('chapters.page', array_merge($baseParams, ['page' => $pageNumber, 'view' => 'one'])) }}"
                           class="p-2 rounded {{ $view === 'one' ? 'bg-gray-800 text-white' : 'bg-gray-200 text-gray-700' }}"
                           title="One Page Mode">One</a>
                        <a href="{{ route('chapters.page', array_merge($baseParams, ['page' => $pageNumber, 'view' => 'double'])) }}"
                           class="p-2 rounded {{ $view === 'double' ? 'bg-gray-800 text-white' : 'bg-gray-200 text-gray-700' }}"
                           title="Double Page Mode">Double</a>
                    @endif
                </div>
            </div>

            @if($view === 'scroll')
                @php
                    $hasNextCh = !empty($nextChapterLink);
                    $hasPrevCh = !empty($prevChapterLink);

                    // Default (Manga): left=NEXT, right=PREV
                    $leftLink  = $nextChapterLink;
                    $rightLink = $prevChapterLink;

                    // Manwha: flip (left=PREV, right=NEXT)
                    if ($isManwha) {
                        $leftLink  = $prevChapterLink;
                        $rightLink = $nextChapterLink;
                    }

                    $scrollTopJustify = ($leftLink && $rightLink) ? 'justify-between'
                                        : ($leftLink ? 'justify-start'
                                        : 'justify-end');
                @endphp
                {{-- Top chapter jump buttons --}}
                <div class="flex {{ $scrollTopJustify }} mb-4">
                    @if($leftLink)
                        <a href="{{ $leftLink }}" class="px-4 py-2 flatGreen text-white rounded-[0.19rem] transition">
                            {{ $isManwha ? 'Prev Chapter' : 'Next Chapter' }}
                        </a>
                    @endif
                    @if($rightLink)
                        <a href="{{ $rightLink }}" class="px-4 py-2 flatGreen text-white rounded-[0.19rem] transition">
                            {{ $isManwha ? 'Next Chapter' : 'Prev Chapter' }}
                        </a>
                    @endif
                </div>

                <div>
                    @foreach($chapter->pages as $p)
                        @php
                            $ext = strtolower(pathinfo($p->file_path, PATHINFO_EXTENSION) ?? '');
                            $isImage = in_array($ext, $allowedExts, true);
                            $url = $isImage ? asset('storage/'.$p->file_path) : null;
                        @endphp

                        @if($isImage)
                            <div class="relative w-full overflow-hidden">
                                @if($shouldBlur)
                                    <img src="{{ asset('images/18-plus.png') }}" alt="18+" class="absolute top-4 right-4 w-10 h-10 z-30">
                                @endif
                                <img
                                    src="{{ $url }}"
                                    alt="Page {{ $p->page_number }}"
                                    class="zoomable w-full h-auto object-contain mx-auto {{ $shouldBlur ? 'filter blur-2xl' : '' }}"
                                >
                            </div>
                        @endif
                    @endforeach
                </div>

                @php
                    $leftBottom  = $nextChapterLink;
                    $rightBottom = $prevChapterLink;
                    if ($isManwha) {
                        $leftBottom  = $prevChapterLink;
                        $rightBottom = $nextChapterLink;
                    }
                    $scrollBottomJustify = ($leftBottom && $rightBottom) ? 'justify-between'
                                            : ($leftBottom ? 'justify-start'
                                            : 'justify-end');
                @endphp
                <div class="flex {{ $scrollBottomJustify }} mt-4">
                    @if($leftBottom)
                        <a href="{{ $leftBottom }}" class="px-4 py-2 flatGreen text-white rounded-[0.19rem] transition">
                            {{ $isManwha ? 'Prev Chapter' : 'Next Chapter' }}
                        </a>
                    @endif
                    @if($rightBottom)
                        <a href="{{ $rightBottom }}" class="px-4 py-2 flatGreen text-white rounded-[0.19rem] transition">
                            {{ $isManwha ? 'Next Chapter' : 'Prev Chapter' }}
                        </a>
                    @endif
                </div>
            @elseif($view === 'one' && !$isManwha)
                @php
                    $current   = $chapter->pages->firstWhere('page_number', $pageNumber);
                    $extOne    = strtolower(pathinfo(optional($current)->file_path ?? '', PATHINFO_EXTENSION));
                    $isImage   = in_array($extOne, $allowedExts, true);
                    $singleUrl = $isImage ? $pageUrl : null;
                @endphp

                @if($isImage)
                    <div class="relative w-full overflow-hidden">
                        @if($shouldBlur)
                            <img src="{{ asset('images/18-plus.png') }}" alt="18+" class="absolute top-4 right-4 w-10 h-10 z-30">
                        @endif

                        <img
                            src="{{ $singleUrl }}"
                            alt="Page {{ $pageNumber }}"
                            class="zoomable w-full h-auto object-contain mx-auto {{ $shouldBlur ? 'filter blur-2xl' : '' }} z-10"
                        >

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

                @php
                    $hasNext = !empty($nextLink);
                    $hasPrev = !empty($prevLink);
                    $oneJustify = $hasNext && $hasPrev ? 'justify-between' : ($hasNext ? 'justify-start' : 'justify-end');
                @endphp
                <div class="flex {{ $oneJustify }}">
                    @if($nextLink)
                        <a href="{{ $nextLink }}" class="px-4 py-2 flatGreen text-white rounded-[0.19rem] transition">Next</a>
                    @endif
                    @if($prevLink)
                        <a href="{{ $prevLink }}" class="px-4 py-2 flatGreen text-white rounded-[0.19rem] transition">Prev</a>
                    @endif
                </div>
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
                    $doubleJustify = $hasDoubleNext && $hasDoublePrev ? 'justify-between' : ($hasDoubleNext ? 'justify-start' : 'justify-end');
                @endphp

                <div class="relative w-full overflow-hidden">
                    <div class="flex justify-center space-x-2">
                        @if($isLeftImg)
                            <div class="relative overflow-hidden">
                                @if($shouldBlur)
                                    <img src="{{ asset('images/18-plus.png') }}" alt="18+" class="absolute top-4 right-4 w-10 h-10 z-30">
                                @endif
                                <img
                                    src="{{ $leftUrl }}"
                                    alt="Page {{ $leftNum }}"
                                    class="zoomable h-auto object-contain mx-auto {{ $shouldBlur ? 'filter blur-2xl' : '' }}"
                                >
                            </div>
                        @endif

                        @if($isRightImg)
                            <div class="relative overflow-hidden">
                                @if($shouldBlur)
                                    <img src="{{ asset('images/18-plus.png') }}" alt="18+" class="absolute top-4 right-4 w-10 h-10 z-30">
                                @endif
                                <img
                                    src="{{ $rightUrl }}"
                                    alt="Page {{ $rightNum }}"
                                    class="zoomable h-auto object-contain mx-auto {{ $shouldBlur ? 'filter blur-2xl' : '' }}"
                                >
                            </div>
                        @endif
                    </div>

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

                <div class="flex {{ $doubleJustify }}">
                    @if($doubleNext)
                        <a href="{{ $doubleNext }}" class="px-4 py-2 flatGreen text-white rounded-[0.19rem] transition">Next</a>
                    @endif
                    @if($doublePrev)
                        <a href="{{ $doublePrev }}" class="px-4 py-2 flatGreen text-white rounded-[0.19rem] transition">Prev</a>
                    @endif
                </div>
            @endif

        </div>
    </div>

    <script>
        let zoomLevel = parseFloat(localStorage.getItem('chapterZoom')) || 1.0;

        function applyZoom(img) {
            const natural = img.naturalHeight || img.clientHeight || 1200;
            const newMax  = Math.max(1, natural * zoomLevel);
            img.style.maxHeight = newMax + 'px';
            img.style.width = 'auto';
        }

        function updateZoomAll() {
            document.querySelectorAll('.zoomable').forEach(img => applyZoom(img));
            localStorage.setItem('chapterZoom', zoomLevel);
        }

        function hookImage(img) {
            if (img.complete && img.naturalHeight > 0) {
                applyZoom(img);
            } else {
                img.addEventListener('load', () => applyZoom(img), { once: true });
                setTimeout(() => { if (!img.style.maxHeight) applyZoom(img); }, 300);
            }
        }

        function zoomIn()  { zoomLevel = Math.min(zoomLevel + 0.05, 1.0); updateZoomAll(); }
        function zoomOut() { zoomLevel = Math.max(zoomLevel - 0.05, 0.2); updateZoomAll(); }

        window.zoomIn = zoomIn;
        window.zoomOut = zoomOut;

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.zoomable').forEach(img => {
                img.style.removeProperty('max-height');
                img.style.width = 'auto';
                hookImage(img);
            });
            updateZoomAll();
        });

        document.addEventListener('keydown', (e) => {
            const tag = (document.activeElement && document.activeElement.tagName) || '';
            if (tag === 'INPUT' || tag === 'TEXTAREA') return;

            const isDouble = @json($view === 'double');
            const nextPair = @json($nextPairLink ?? null);
            const prevPair = @json($prevPairLink ?? null);
            const nextPage = @json($nextLink ?? null);
            const prevPage = @json($prevLink ?? null);
            const isManwha = @json($isManwha);

            let leftTarget  = isDouble ? (nextPair || nextPage) : nextPage;
            let rightTarget = isDouble ? (prevPair || prevPage) : prevPage;

            if (isManwha) {
                const tmp = leftTarget;
                leftTarget  = rightTarget;
                rightTarget = tmp;
            }

            if (e.key === 'ArrowLeft'  && leftTarget)  window.location.href = leftTarget;
            if (e.key === 'ArrowRight' && rightTarget) window.location.href = rightTarget;
        });
    </script>
@endsection
