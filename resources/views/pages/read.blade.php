@extends('layouts.app')

@section('content')
@php
    use Illuminate\Support\Facades\Storage;
    use Illuminate\Support\Facades\URL;

    function shortTitle($title, $maxLen = 25) {
        if (strlen($title) <= $maxLen) {
            return $title;
        }
        return substr($title, 0, $maxLen - 1) . '…';
    }

    $pages = $doujin->pages->pluck('page_number')->sort()->values();
    $totalPages = $pages->count();

    $prev = null;
    $next = null;

    $prevCandidate = $pageNumber - 1;
    $nextCandidate = $pageNumber + 1;

    if ($prevCandidate >= 1 && $pages->contains($prevCandidate)) {
        $prev = $prevCandidate;
    }
    if ($nextCandidate <= $totalPages && $pages->contains($nextCandidate)) {
        $next = $nextCandidate;
    }

    $nums = $pages->all();
    $currentIndex = array_search($pageNumber, $nums);

    $pairStart = ($currentIndex % 2 === 0) ? $currentIndex : ($currentIndex - 1);

    $nums = $pages->all();
    $currentIndex = array_search($pageNumber, $nums);

    $pairStart = ($currentIndex % 2 === 0) ? $currentIndex : ($currentIndex - 1);

    $a = $nums[$pairStart] ?? null;
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
        $prevPairPage = $nums[$prevPairStart];
    }

    $nextPairStart = $pairStart + 2;
    if ($nextPairStart < count($nums)) {
        $nextPairPage = $nums[$nextPairStart];
    }

    $view = request('view', 'one');
    $allowedExts = ['jpg','jpeg','png','gif','webp'];
    $isFixedView = in_array($view, ['one', 'double'], true);
    $readerImageExpiresAt = now()->addHours($view === 'scroll' ? 8 : 2);
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
  body.reader-mode,
  body.reader-mode .doujin-reader,
  body.reader-mode .doujin-scroll-stack,
  body.reader-mode .doujin-fixed-page,
  body.reader-mode img {
    -webkit-user-select: none;
    -moz-user-select: none;
    user-select: none;
    -webkit-touch-callout: none;
  }
  body.reader-mode img {
    -webkit-user-drag: none;
    user-drag: none;
  }
  body.reader-mode.reader-cursor-hidden,
  body.reader-mode.reader-cursor-hidden * {
    cursor: none !important;
  }
  body.reader-mode.reader-fixed {
    overflow: hidden;
  }
  .doujin-reader.fixed-mode {
    width: 100vw;
    height: 100dvh;
    min-height: 100dvh;
    overflow: hidden;
    position: relative;
  }
  .doujin-reader.fixed-mode .doujin-reader-frame {
    width: 100vw;
    max-width: none;
    height: 100dvh;
    margin: 0;
    padding: 0;
    border-radius: 0;
    box-shadow: none;
  }
  .doujin-reader.fixed-mode .doujin-topbar {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 40;
    margin: 0;
    padding: 0.75rem 1rem;
    background: rgba(171, 35, 40, 0.92);
  }
  .doujin-reader.fixed-mode .doujin-topbar .text-red-600,
  .doujin-reader.fixed-mode .doujin-topbar a {
    color: #ffffff !important;
  }
  .doujin-reader.fixed-mode .doujin-fixed-page {
    position: relative;
    width: 100vw;
    height: 100dvh;
    min-height: 100dvh;
    overflow: hidden;
  }
  .doujin-reader.fixed-mode .doujin-fixed-img {
    width: auto;
    height: 100dvh;
    max-height: 100dvh;
    max-width: 100vw;
    object-fit: contain;
    margin: 0 auto;
    display: block;
  }
  .doujin-reader.fixed-mode .doujin-fixed-double {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 0;
    height: 100dvh;
  }
  .doujin-reader.fixed-mode .doujin-fixed-double > div {
    flex: 0 0 50%;
    max-width: 50%;
    height: 100dvh;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
  }
  .doujin-reader.fixed-mode .doujin-fixed-double img {
    width: auto;
    height: 100dvh;
    max-height: 100dvh;
    max-width: 50vw;
    object-fit: contain;
    margin: 0 auto;
  }
  .doujin-reader.fixed-mode .doujin-bottom-dock-wrap {
    position: fixed;
    left: 50%;
    bottom: 18px;
    transform: translateX(-50%);
    z-index: 45;
  }
  .doujin-reader.fixed-mode .doujin-bottom-dock {
    display: flex;
    align-items: center;
    justify-content: center;
    filter: drop-shadow(0 12px 24px rgba(0, 0, 0, 0.25));
  }
  .doujin-reader.fixed-mode .doujin-arrow-square {
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
  .doujin-reader.fixed-mode .doujin-arrow-icon {
    width: 33px;
    height: 33px;
    stroke: currentColor;
    fill: none;
    stroke-width: 2.9;
    stroke-linecap: round;
    stroke-linejoin: round;
  }
  .doujin-reader.fixed-mode .doujin-arrow-icon.is-right {
    transform: scaleX(-1);
    transform-origin: center;
  }
  .doujin-reader.fixed-mode .doujin-arrow-square.left {
    border-radius: 10px 0 0 10px;
    border-right: none;
  }
  .doujin-reader.fixed-mode .doujin-arrow-square.right {
    border-radius: 0 10px 10px 0;
    border-left: none;
  }
  .doujin-reader.fixed-mode .doujin-arrow-square:not(.disabled):hover,
  .doujin-reader.fixed-mode .doujin-arrow-square:not(.disabled):active,
  .doujin-reader.fixed-mode .doujin-arrow-square:not(.disabled):focus,
  .doujin-reader.fixed-mode .doujin-arrow-square:not(.disabled):focus-visible {
    background: #ab2328;
    color: #ffffff;
    text-decoration: none;
    box-shadow: none;
    outline: none;
  }
  .doujin-reader.fixed-mode .doujin-arrow-square.disabled {
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
  }
  .doujin-reader.fixed-mode .doujin-count-square {
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
    line-height: 1;
    padding: 0.35rem 0.85rem 0.25rem;
    flex: 0 0 auto;
    box-shadow: -8px 0 10px -8px rgba(0, 0, 0, 0.38),
                8px 0 10px -8px rgba(0, 0, 0, 0.38);
    position: relative;
    z-index: 2;
  }
  .doujin-reader.fixed-mode .doujin-count-label {
    font-size: 0.64rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    opacity: 0.9;
    margin-bottom: 0.1rem;
    transform: translateY(-0.42rem);
  }
  .doujin-reader.fixed-mode .doujin-count-value {
    font-size: 2.35rem;
    font-weight: 400;
    letter-spacing: -0.02em;
    white-space: nowrap;
  }
  .doujin-reader.fixed-mode .doujin-count-pair {
    display: inline-flex;
    align-items: baseline;
    gap: 0.92rem;
    font-size: 2.35rem;
    font-weight: 400;
    letter-spacing: -0.02em;
    white-space: nowrap;
  }
  .doujin-reader.fixed-mode .doujin-count-sep {
    display: inline-block;
    transform: translateY(-0.14em);
    font-size: 1em;
    line-height: 1;
  }
  .doujin-scroll-stack {
    display: flex;
    flex-direction: column;
    gap: 0;
  }
  .doujin-scroll-stack img {
    display: block;
  }
  .reader-lazy-page {
    min-height: min(100vh, 1200px);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    background: #f8fafc;
  }
  .reader-lazy-page::before {
    content: "";
    width: 28px;
    height: 28px;
    border: 3px solid #e5e7eb;
    border-top-color: #ab2328;
    border-radius: 9999px;
    animation: reader-lazy-spin 0.9s linear infinite;
  }
  .reader-lazy-page.reader-lazy-pending > img {
    display: none;
  }
  .reader-lazy-page.reader-lazy-error::before {
    content: "";
    width: 64px;
    height: 48px;
    border: 2px solid #94a3b8;
    border-top-color: #94a3b8;
    border-radius: 6px;
    animation: none;
    background:
      radial-gradient(circle at 74% 26%, #94a3b8 0 4px, transparent 5px),
      linear-gradient(135deg, transparent 46%, #94a3b8 47%, #94a3b8 53%, transparent 54%) left 9px bottom 10px / 30px 22px no-repeat,
      linear-gradient(45deg, transparent 46%, #94a3b8 47%, #94a3b8 53%, transparent 54%) right 10px bottom 10px / 30px 20px no-repeat;
  }
  .reader-lazy-page.reader-lazy-error::after {
    content: "Image could not be loaded";
    position: absolute;
    left: 50%;
    top: calc(50% + 48px);
    color: #64748b;
    font-size: 0.95rem;
    font-weight: 500;
    transform: translateX(-50%);
    white-space: nowrap;
  }
  .reader-lazy-page:not(.reader-lazy-pending) {
    min-height: 0;
    background: transparent;
  }
  .reader-lazy-page:not(.reader-lazy-pending)::before {
    display: none;
  }
  .reader-image-error {
    min-height: min(100vh, 1200px);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    background: #f8fafc;
  }
  .reader-image-error > img,
  .reader-image-error .doujin-fixed-double,
  .reader-image-error .flex.justify-center {
    display: none;
  }
  .reader-image-error::before {
    content: "";
    width: 64px;
    height: 48px;
    border: 2px solid #94a3b8;
    border-radius: 6px;
    background:
      radial-gradient(circle at 74% 26%, #94a3b8 0 4px, transparent 5px),
      linear-gradient(135deg, transparent 46%, #94a3b8 47%, #94a3b8 53%, transparent 54%) left 9px bottom 10px / 30px 22px no-repeat,
      linear-gradient(45deg, transparent 46%, #94a3b8 47%, #94a3b8 53%, transparent 54%) right 10px bottom 10px / 30px 20px no-repeat;
  }
  .reader-image-error::after {
    content: "Image could not be loaded";
    position: absolute;
    left: 50%;
    top: calc(50% + 48px);
    color: #64748b;
    font-size: 0.95rem;
    font-weight: 500;
    transform: translateX(-50%);
    white-space: nowrap;
  }
  @keyframes reader-lazy-spin {
    to {
      transform: rotate(360deg);
    }
  }
  .reader-fullscreen-btn [data-fullscreen-exit] {
    display: none;
  }
  .reader-fullscreen-btn.is-fullscreen [data-fullscreen-enter] {
    display: none;
  }
  .reader-fullscreen-btn.is-fullscreen [data-fullscreen-exit] {
    display: block;
  }
</style>

<div
  data-reader-root
  data-reader-protected
  data-reader-kind="doujin"
  data-reader-fixed="{{ $isFixedView ? '1' : '0' }}"
  data-reader-view="{{ $view }}"
  class="doujin-reader {{ $isFixedView ? 'fixed-mode' : 'flex flex-col items-center py-[4rem] mt-12' }}"
>
  <div class="{{ $isFixedView ? 'doujin-reader-frame bg-white space-y-6' : 'w-[1280px] bg-white shadow-sm rounded-md p-6 ml-[0.5rem] space-y-6' }}">
    <div class="{{ $isFixedView ? 'doujin-topbar relative flex items-center' : 'relative flex items-center mb-4' }}">
      <div class="flex-1">
        @if($view === 'one')
            <h1 class="text-2xl font-bold text-red-600">
                <a href="{{ route('media.doujin', ['doujin' => $doujin->id]) }}"
                    class="hover:underline">
                    {{ shortTitle($doujin->doujin_name, 30) }}
                </a>
                - Page {{ $pageNumber }}
            </h1>
        @elseif($view === 'double')
          <h1 class="text-2xl font-bold text-red-600">
                <a href="{{ route('media.doujin', ['doujin' => $doujin->id]) }}"
                    class="hover:underline">
                    {{ shortTitle($doujin->doujin_name, 30) }}
                </a>
            @if($rightNum)
              - Pages {{ $leftNum }} &amp; {{ $rightNum }}
            @else
              - Page {{ $leftNum }}
            @endif
          </h1>
        @else
          <h1 class="text-2xl font-bold text-red-600">
            <a href="{{ route('media.doujin', ['doujin' => $doujin->id]) }}"
                class="hover:underline">
                {{ shortTitle($doujin->doujin_name, 30) }}
            </a>
          </h1>
        @endif
      </div>
      @if($view === 'scroll')
        <div class="absolute inset-x-0 flex justify-center pointer-events-none">
          <button 
            onclick="zoomOut()" 
            class="pointer-events-auto px-3 py-1 bg-gray-200 text-gray-700 rounded transition"
            title="Zoom Out"
          >-</button>
          <button 
            onclick="zoomIn()" 
            class="pointer-events-auto ml-2 px-3 py-1 bg-gray-200 text-gray-700 rounded transition"
            title="Zoom In"
          >+</button>
        </div>
      @endif
      @php
        $baseParams = ['doujin' => $doujin->id];
      @endphp
      <div class="flex-1 flex justify-end space-x-4">
        <button
          type="button"
          class="reader-fullscreen-btn mr-6 p-2 rounded bg-gray-200 text-gray-700 transition hover:bg-white hover:text-black"
          data-fullscreen-toggle
          aria-label="Enter fullscreen"
          title="Fullscreen"
        >
          <span class="sr-only">Fullscreen</span>
          <svg data-fullscreen-enter xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M8 3H3v5" />
            <path d="M16 3h5v5" />
            <path d="M21 16v5h-5" />
            <path d="M3 16v5h5" />
          </svg>
          <svg data-fullscreen-exit xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M9 3v6H3" />
            <path d="M15 3v6h6" />
            <path d="M15 21v-6h6" />
            <path d="M9 21v-6H3" />
          </svg>
        </button>
        <a
          href="{{ route('media.doujin.page', array_merge($baseParams, ['page' => $pageNumber, 'view' => 'scroll'])) }}"
          class="p-2 rounded {{ $view === 'scroll' ? 'bg-gray-800 text-white' : 'bg-gray-200 text-gray-700' }} transition hover:bg-white hover:text-black"
          title="Scroll Mode"
        >
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="butt" stroke-linejoin="miter">
            <path d="M7 4v5M7 9h10M17 4v5M7 20v-5M7 15h10M17 20v-5" />
          </svg>
        </a>
        <a
          href="{{ route('media.doujin.page', array_merge($baseParams, ['page' => $pageNumber, 'view' => 'one'])) }}"
          class="p-2 rounded {{ $view === 'one' ? 'bg-gray-800 text-white' : 'bg-gray-200 text-gray-700' }} transition hover:bg-white hover:text-black"
          title="One Page Mode"
        >
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
            <path d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V8.414A2 2 0 0015.586 7L12 3.414A2 2 0 0010.586 3H4zM12 4.414L15.586 8H12V4.414z" />
          </svg>
        </a>
        <a
          href="{{ route('media.doujin.page', array_merge($baseParams, ['page' => $pageNumber, 'view' => 'double'])) }}"
          class="p-2 rounded {{ $view === 'double' ? 'bg-gray-800 text-white' : 'bg-gray-200 text-gray-700' }} transition hover:bg-white hover:text-black"
          title="Double Page Mode"
        >
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 6v12" />
            <path d="M12 7c-2-1.4-4.5-2-7-2v12c2.5 0 5 0.6 7 2" />
            <path d="M12 7c2-1.4 4.5-2 7-2v12c-2.5 0-5 0.6-7 2" />
          </svg>
        </a>

      </div>
    </div>
    @if($view === 'scroll')
    <div class="doujin-scroll-stack">
        @foreach($doujin->pages as $p)
        @php
            $ext = strtolower(pathinfo($p->file_path, PATHINFO_EXTENSION) ?? '');
            $isImage = in_array($ext, $allowedExts, true);
            $url = $isImage
                ? URL::temporarySignedRoute('reader.page.image', $readerImageExpiresAt, ['page' => $p->id])
                : null;
        @endphp

        @if($isImage)
            <div class="relative w-full overflow-hidden reader-lazy-page reader-lazy-pending" data-reader-lazy-frame>
            <img
                data-reader-lazy
                data-src="{{ $url }}"
                alt="Page {{ $p->page_number }}"
                class="zoomable block w-full h-auto object-contain mx-auto"
                loading="lazy"
                decoding="async"
                draggable="false"
            >
            <noscript>
                <img
                    src="{{ $url }}"
                    alt="Page {{ $p->page_number }}"
                    class="block w-full h-auto object-contain mx-auto"
                    draggable="false"
                >
            </noscript>
            </div>
        @endif
        @endforeach
    </div>

    @elseif($view === 'one')
        @php
            $singlePage = $doujin->pages->where('page_number', $pageNumber)->first();
            $extOne = strtolower(pathinfo(optional($singlePage)->file_path, PATHINFO_EXTENSION) ?? '');
            $isSingleImage = in_array($extOne, $allowedExts, true);
            $singleUrl = $isSingleImage
                ? URL::temporarySignedRoute('reader.page.image', $readerImageExpiresAt, ['page' => $singlePage->id])
                : null;
        @endphp

        @if($isSingleImage)
            <div class="{{ $isFixedView ? 'doujin-fixed-page' : 'relative w-full overflow-hidden' }}">
                <img
                    src="{{ $singleUrl }}"
                    alt="Page {{ $pageNumber }}"
                    class="{{ $isFixedView ? 'doujin-fixed-img z-10' : 'zoomable w-full h-auto object-contain mx-auto z-10' }}"
                    draggable="false"
                >

                @if($next)
                <a href="{{ route('media.doujin.page', ['doujin' => $doujin->id, 'page' => $next, 'view' => 'one']) }}">
                    <div class="absolute inset-y-0 left-0 w-1/2 z-20" style="cursor:pointer;"></div>
                </a>
                @endif
                @if($prev)
                <a
                    href="{{ route('media.doujin.page', ['doujin' => $doujin->id, 'page' => $prev, 'view' => 'one']) }}"
                >
                    <div class="absolute inset-y-0 right-0 w-1/2 z-20" style="cursor:pointer;"></div>
                </a>
                @endif
        
            </div>
        @endif
   @elseif($view === 'double')
    @php
        $extLeft  = $leftNum  !== null
                    ? strtolower(pathinfo(
                        $doujin->pages->where('page_number', $leftNum)->first()->file_path,
                        PATHINFO_EXTENSION
                    ) ?? '')
                    : '';
        $extRight = $rightNum !== null
                    ? strtolower(pathinfo(
                        $doujin->pages->where('page_number', $rightNum)->first()->file_path,
                        PATHINFO_EXTENSION
                    ) ?? '')
                    : '';
        $isLeftImage  = in_array($extLeft,  $allowedExts, true);
        $isRightImage = in_array($extRight, $allowedExts, true);
        $leftPage = $leftNum !== null ? $doujin->pages->where('page_number', $leftNum)->first() : null;
        $rightPage = $rightNum !== null ? $doujin->pages->where('page_number', $rightNum)->first() : null;
        $leftUrl = $isLeftImage
                    ? URL::temporarySignedRoute('reader.page.image', $readerImageExpiresAt, ['page' => $leftPage->id])
                    : null;
        $rightUrl = $isRightImage
                    ? URL::temporarySignedRoute('reader.page.image', $readerImageExpiresAt, ['page' => $rightPage->id])
                    : null;
        $hasSingleSpreadPage = ($isLeftImage xor $isRightImage);
        $singleSpreadUrl = $isLeftImage ? $leftUrl : $rightUrl;
        $singleSpreadNum = $isLeftImage ? $leftNum : $rightNum;
    @endphp

    <div class="{{ $isFixedView ? 'doujin-fixed-page' : 'relative w-full overflow-hidden' }}">
        @if($hasSingleSpreadPage)
          <img
            src="{{ $singleSpreadUrl }}"
            alt="Page {{ $singleSpreadNum }}"
            class="{{ $isFixedView ? 'doujin-fixed-img z-10' : 'zoomable w-full h-auto object-contain mx-auto z-10' }}"
            draggable="false"
          >
        @else
          <div class="{{ $isFixedView ? 'doujin-fixed-double' : 'flex justify-center space-x-2' }}">
          @if($isLeftImage)
              <div class="relative overflow-hidden">
              <img
                  src="{{ $leftUrl }}"
                  alt="Page {{ $leftNum }}"
                  class="{{ $isFixedView ? 'doujin-fixed-img' : 'zoomable h-auto object-contain mx-auto' }}"
                  draggable="false"
              >
              </div>
          @endif
          @if($isRightImage)
              <div class="relative overflow-hidden">
              <img
                  src="{{ $rightUrl }}"
                  alt="Page {{ $rightNum }}"
                  class="{{ $isFixedView ? 'doujin-fixed-img' : 'zoomable h-auto object-contain mx-auto' }}"
                  draggable="false"
              >
              </div>
          @endif
          </div>
        @endif
        @if($nextPairPage)
        <a
            href="{{ route('media.doujin.page', ['doujin' => $doujin->id, 'page' => $nextPairPage, 'view' => 'double']) }}"
        >
            <div class="absolute inset-y-0 left-0 w-1/2 z-20" style="cursor:pointer;"></div>
        </a>
        @endif
        @if($prevPairPage)
        <a
            href="{{ route('media.doujin.page', ['doujin' => $doujin->id, 'page' => $prevPairPage, 'view' => 'double']) }}"
        >
            <div class="absolute inset-y-0 right-0 w-1/2 z-20" style="cursor:pointer;"></div>
        </a>
        @endif
    </div>
    @endif
    @php
      $dockLeftLink = null;
      $dockRightLink = null;
      $dockLeftAction = 'next';
      $dockRightAction = 'prev';

      if ($view === 'one') {
        $dockLeftLink = $next ? route('media.doujin.page', ['doujin' => $doujin->id, 'page' => $next, 'view' => 'one']) : null;
        $dockRightLink = $prev ? route('media.doujin.page', ['doujin' => $doujin->id, 'page' => $prev, 'view' => 'one']) : null;
      } elseif ($view === 'double') {
        $dockLeftLink = $nextPairPage ? route('media.doujin.page', ['doujin' => $doujin->id, 'page' => $nextPairPage, 'view' => 'double']) : null;
        $dockRightLink = $prevPairPage ? route('media.doujin.page', ['doujin' => $doujin->id, 'page' => $prevPairPage, 'view' => 'double']) : null;
      }

      $dockLabel = 'Page';
      if ($view === 'double') {
        $dockValue = $rightNum ? ($leftNum.' | '.$rightNum) : (string)$leftNum;
      } else {
        $dockValue = (string)$pageNumber;
      }
    @endphp

    @if($isFixedView)
      <div class="doujin-bottom-dock-wrap">
        <div class="doujin-bottom-dock">
          @if($dockLeftLink)
            <a href="{{ $dockLeftLink }}" class="doujin-arrow-square left" aria-label="{{ $dockLeftAction === 'next' ? 'Next' : 'Previous' }}">
              <svg class="doujin-arrow-icon {{ $dockLeftAction === 'next' ? 'is-left' : 'is-right' }}" viewBox="0 0 24 24" aria-hidden="true">
                <polyline points="15 4 7 12 15 20"></polyline>
              </svg>
            </a>
          @else
            <span class="doujin-arrow-square left disabled" aria-hidden="true">
              <svg class="doujin-arrow-icon {{ $dockLeftAction === 'next' ? 'is-left' : 'is-right' }}" viewBox="0 0 24 24">
                <polyline points="15 4 7 12 15 20"></polyline>
              </svg>
            </span>
          @endif

          <div class="doujin-count-square">
            <span class="doujin-count-label">{{ $dockLabel }}</span>
            @if($view === 'double' && $rightNum)
              <span class="doujin-count-pair">
                <span>{{ $leftNum }}</span>
                <span class="doujin-count-sep">|</span>
                <span>{{ $rightNum }}</span>
              </span>
            @else
              <span class="doujin-count-value">{{ $dockValue }}</span>
            @endif
          </div>

          @if($dockRightLink)
            <a href="{{ $dockRightLink }}" class="doujin-arrow-square right" aria-label="{{ $dockRightAction === 'next' ? 'Next' : 'Previous' }}">
              <svg class="doujin-arrow-icon {{ $dockRightAction === 'next' ? 'is-left' : 'is-right' }}" viewBox="0 0 24 24" aria-hidden="true">
                <polyline points="15 4 7 12 15 20"></polyline>
              </svg>
            </a>
          @else
            <span class="doujin-arrow-square right disabled" aria-hidden="true">
              <svg class="doujin-arrow-icon {{ $dockRightAction === 'next' ? 'is-left' : 'is-right' }}" viewBox="0 0 24 24">
                <polyline points="15 4 7 12 15 20"></polyline>
              </svg>
            </span>
          @endif
        </div>
      </div>
    @endif

    @if(!$isFixedView && $view === 'one')
        @php
            if ($next && $prev) {          // both buttons
                $footerJustify = 'justify-between';
            } elseif ($next) {             // only “Next” → left
                $footerJustify = 'justify-start';
            } else {                       // only “Prev” → right
                $footerJustify = 'justify-end';
            }
        @endphp

        <div class="flex {{ $footerJustify }}">
        @if($next)
            <a
            href="{{ route('media.doujin.page', ['doujin' => $doujin->id, 'page' => $next, 'view' => 'one']) }}"
            class="px-4 py-2 flatGreen text-white rounded-[0.19rem] transition"
            >Next</a>
        @endif

        @if($prev)
            <a
            href="{{ route('media.doujin.page', ['doujin' => $doujin->id, 'page' => $prev, 'view' => 'one']) }}"
            class="px-4 py-2 flatGreen text-white rounded-[0.19rem] transition"
            >Prev</a>
        @endif
        </div>
    @elseif(!$isFixedView && $view === 'double')
        @php
            if ($next && $prev) {          // both buttons
                $footerJustify = 'justify-between';
            } elseif ($next) {             // only “Next” → left
                $footerJustify = 'justify-start';
            } else {                       // only “Prev” → right
                $footerJustify = 'justify-end';
            }
        @endphp

        <div class="flex {{ $footerJustify }}">
        @if($nextPairPage)
        <a
           class="px-4 py-2 flatGreen text-white rounded-[0.19rem] transition" href="{{ route('media.doujin.page', ['doujin' => $doujin->id, 'page' => $nextPairPage, 'view' => 'double']) }}"
        >
            Next
        </a>
        @endif
        @if($prevPairPage)
        <a
           class="px-4 py-2 flatGreen text-white rounded-[0.19rem] transition" href="{{ route('media.doujin.page', ['doujin' => $doujin->id, 'page' => $prevPairPage, 'view' => 'double']) }}"
        >
            Prev
        </a>
        @endif
        </div>
    @endif

  </div>
</div>

<script>
  (() => {
    const fullscreenElement = () => document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement;
    const supportsFullscreen = () => document.documentElement.requestFullscreen || document.documentElement.webkitRequestFullscreen || document.documentElement.msRequestFullscreen;
    const readerRoot = () => document.querySelector('[data-reader-root]');
    const isReaderUrl = (url) => url.origin === window.location.origin
      && url.searchParams.has('view')
      && (/\/chapters\//.test(url.pathname) || /\/page\/\d+/.test(url.pathname));
    const zoomEnabled = () => {
      const root = readerRoot();
      return root && root.dataset.readerView === 'scroll';
    };

    let zoomLevel = parseFloat(localStorage.getItem('doujinZoom')) || 1.0;
    let navigating = false;
    let lazyReaderObserver = null;
    let cursorIdleTimer = null;
    const cursorIdleDelay = 1400;

    function updateZoom() {
      if (!zoomEnabled()) return;
      document.querySelectorAll('.zoomable').forEach(img => {
        if (img.dataset.readerLazy && img.dataset.readerLazyLoaded !== '1') return;
        if (!img.complete || !img.naturalHeight || !img.clientHeight) return;
        if (!img.dataset.originalHeight) {
          img.dataset.originalHeight = img.clientHeight;
        }
        const originalPx = parseFloat(img.dataset.originalHeight);
        const newMaxPx = originalPx * zoomLevel;
        img.style.maxHeight = newMaxPx + 'px';
        img.style.width = 'auto';
      });
      localStorage.setItem('doujinZoom', zoomLevel);
    }

    function zoomIn() {
      if (!zoomEnabled()) return;
      zoomLevel = Math.min(zoomLevel + 0.05, 1.0);
      updateZoom();
    }

    function zoomOut() {
      if (!zoomEnabled()) return;
      zoomLevel = Math.max(zoomLevel - 0.05, 0.2);
      updateZoom();
    }

    const syncFullscreenButtons = () => {
      const isFullscreen = Boolean(fullscreenElement());
      document.querySelectorAll('[data-fullscreen-toggle]').forEach((button) => {
        button.hidden = !supportsFullscreen();
        button.classList.toggle('is-fullscreen', isFullscreen);
        button.setAttribute('aria-label', isFullscreen ? 'Exit fullscreen' : 'Enter fullscreen');
        button.setAttribute('title', isFullscreen ? 'Exit fullscreen' : 'Fullscreen');
      });
    };

    const toggleFullscreen = async () => {
      try {
        if (fullscreenElement()) {
          const exitFullscreen = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen;
          if (exitFullscreen) {
            await exitFullscreen.call(document);
          }
        } else {
          const enterFullscreen = document.documentElement.requestFullscreen || document.documentElement.webkitRequestFullscreen || document.documentElement.msRequestFullscreen;
          if (enterFullscreen) {
            await enterFullscreen.call(document.documentElement);
          }
        }
      } catch (error) {
        console.warn('Fullscreen toggle failed', error);
      }
    };

    const markLazyImageFinished = (img, failed = false) => {
      const frame = img.closest('[data-reader-lazy-frame]');
      if (frame) {
        if (failed) {
          frame.classList.add('reader-lazy-error', 'reader-image-error');
          frame.setAttribute('role', 'img');
          frame.setAttribute('aria-label', `${img.alt || 'Reader page'} failed to load`);
        } else {
          frame.classList.remove('reader-lazy-pending', 'reader-lazy-error', 'reader-image-error');
          frame.removeAttribute('role');
          frame.removeAttribute('aria-label');
        }
      }
      if (failed) return;

      if (img.classList.contains('zoomable')) {
        img.dataset.originalHeight = '';
        requestAnimationFrame(updateZoom);
      }
    };

    const markReaderImageFailed = (img) => {
      const frame = img.closest('.doujin-fixed-page, .relative.w-full.overflow-hidden') || img.parentElement;
      if (!frame) return;

      frame.classList.add('reader-image-error');
      frame.setAttribute('role', 'img');
      frame.setAttribute('aria-label', `${img.alt || 'Reader page'} failed to load`);
    };

    const initReaderImageErrors = () => {
      document.querySelectorAll('img.zoomable:not([data-reader-lazy])').forEach((img) => {
        if (img.dataset.readerErrorBound === '1') return;

        img.dataset.readerErrorBound = '1';
        img.addEventListener('error', () => markReaderImageFailed(img), { once: true });

        if (img.complete && !img.naturalWidth) {
          markReaderImageFailed(img);
        }
      });
    };

    const loadLazyReaderImage = (img) => {
      if (!img || img.dataset.readerLazyLoaded === '1') return;

      const src = img.dataset.src;
      if (!src) return;

      img.dataset.readerLazyLoaded = '1';
      img.src = src;
      img.removeAttribute('data-src');
      img.loading = 'eager';
    };

    const initLazyReaderImages = () => {
      const lazyImages = Array.from(document.querySelectorAll('img[data-reader-lazy][data-src]'));

      if (lazyReaderObserver) {
        lazyReaderObserver.disconnect();
        lazyReaderObserver = null;
      }

      if (!lazyImages.length) return;

      lazyImages.forEach((img) => {
        if (img.dataset.readerLazyEventsBound === '1') return;

        img.dataset.readerLazyEventsBound = '1';
        img.addEventListener('load', () => markLazyImageFinished(img), { once: true });
        img.addEventListener('error', () => markLazyImageFinished(img, true), { once: true });
      });

      if (!('IntersectionObserver' in window)) {
        lazyImages.forEach(loadLazyReaderImage);
        return;
      }

      lazyReaderObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;

          const img = entry.target.matches('img')
            ? entry.target
            : entry.target.querySelector('img[data-reader-lazy][data-src]');

          loadLazyReaderImage(img);
          lazyReaderObserver.unobserve(entry.target);
        });
      }, {
        rootMargin: '1200px 0px',
        threshold: 0.01,
      });

      lazyImages.forEach((img) => {
        lazyReaderObserver.observe(img.closest('[data-reader-lazy-frame]') || img);
      });
    };

    const setReaderMode = () => {
      const root = readerRoot();
      document.body.classList.add('reader-mode');
      document.body.classList.toggle('reader-fixed', root && root.dataset.readerFixed === '1');
    };

    const supportsPointerCursor = () => window.matchMedia
      ? window.matchMedia('(hover: hover) and (pointer: fine)').matches
      : true;

    const clearReaderCursorIdle = () => {
      if (cursorIdleTimer) {
        clearTimeout(cursorIdleTimer);
        cursorIdleTimer = null;
      }
      document.body.classList.remove('reader-cursor-hidden');
    };

    const scheduleReaderCursorIdle = () => {
      clearReaderCursorIdle();

      if (!readerRoot() || !supportsPointerCursor()) return;

      cursorIdleTimer = setTimeout(() => {
        if (readerRoot()) {
          document.body.classList.add('reader-cursor-hidden');
        }
      }, cursorIdleDelay);
    };

    const bindReaderCursorIdle = () => {
      if (window.__readerCursorIdleBound) {
        scheduleReaderCursorIdle();
        return;
      }

      window.__readerCursorIdleBound = true;

      document.addEventListener('mousemove', () => {
        if (!readerRoot()) {
          clearReaderCursorIdle();
          return;
        }

        scheduleReaderCursorIdle();
      }, { passive: true });

      document.addEventListener('mouseleave', clearReaderCursorIdle);
      document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
          clearReaderCursorIdle();
        } else if (readerRoot()) {
          scheduleReaderCursorIdle();
        }
      });

      scheduleReaderCursorIdle();
    };

    const bindProtectedReader = () => {
      const protectedReader = document.querySelector('[data-reader-protected]');
      if (!protectedReader || protectedReader.dataset.readerProtectedBound === '1') return;
      protectedReader.dataset.readerProtectedBound = '1';

      protectedReader.addEventListener('contextmenu', (e) => {
        e.preventDefault();
      }, { capture: true });

      protectedReader.addEventListener('dragstart', (e) => {
        if (e.target instanceof Element && e.target.closest('img')) {
          e.preventDefault();
        }
      }, { capture: true });
    };

    const initZoom = () => {
      if (!zoomEnabled()) return;
      document.querySelectorAll('.zoomable').forEach(img => {
        if (img.dataset.readerLazy && img.dataset.readerLazyLoaded !== '1') return;
        if (!img.complete || !img.naturalHeight || !img.clientHeight) return;
        img.dataset.originalHeight = img.clientHeight;
        img.style.removeProperty('max-height');
        img.style.width = 'auto';
      });
      updateZoom();
    };

    const initReaderPage = () => {
      setReaderMode();
      bindReaderCursorIdle();
      bindProtectedReader();
      syncFullscreenButtons();
      initLazyReaderImages();
      initReaderImageErrors();
      initZoom();
    };

    const navigateReader = async (targetUrl) => {
      if (!fullscreenElement()) {
        window.location.replace(targetUrl);
        return;
      }

      if (navigating) return;
      navigating = true;

      try {
        const response = await fetch(targetUrl, {
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) {
          throw new Error(`Reader navigation failed: ${response.status}`);
        }

        const html = await response.text();
        const nextDocument = new DOMParser().parseFromString(html, 'text/html');
        if (!nextDocument.querySelector('[data-reader-root]')) {
          throw new Error('Response was not a reader page.');
        }

        document.title = nextDocument.title;
        document.body.className = nextDocument.body.className;
        document.body.innerHTML = nextDocument.body.innerHTML;
        history.replaceState(null, '', response.url || targetUrl);
        window.scrollTo(0, 0);
        initReaderPage();
      } catch (error) {
        console.warn('Reader fullscreen navigation fell back to a page load', error);
        window.location.replace(targetUrl);
      } finally {
        navigating = false;
      }
    };

    if (!window.__readerGlobalEventsBound) {
      window.__readerGlobalEventsBound = true;

      document.addEventListener('fullscreenchange', syncFullscreenButtons);
      document.addEventListener('webkitfullscreenchange', syncFullscreenButtons);
      document.addEventListener('MSFullscreenChange', syncFullscreenButtons);

      document.addEventListener('contextmenu', (e) => {
        if (!readerRoot()) return;

        e.preventDefault();
        e.stopPropagation();
      }, { capture: true });

      document.addEventListener('dragstart', (e) => {
        if (!readerRoot()) return;

        if (e.target instanceof Element && e.target.closest('img')) {
          e.preventDefault();
          e.stopPropagation();
        }
      }, { capture: true });

      document.addEventListener('click', (e) => {
        const fullscreenButton = e.target.closest('[data-fullscreen-toggle]');
        if (fullscreenButton) {
          e.preventDefault();
          toggleFullscreen();
          return;
        }

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

        if (!isReaderUrl(url)) return;

        e.preventDefault();
        navigateReader(url.toString());
      });

      document.addEventListener('keydown', (e) => {
        const key = e.key.toLowerCase();
        const commandKey = e.ctrlKey || e.metaKey;

        if (
          key === 'f12'
          || key === 'contextmenu'
          || (e.shiftKey && key === 'f10')
          || (commandKey && ['s', 'u', 'p'].includes(key))
          || (commandKey && e.shiftKey && ['i', 'j', 'c', 'k'].includes(key))
        ) {
          e.preventDefault();
          e.stopPropagation();
        }
      });
    }

    window.zoomIn = zoomIn;
    window.zoomOut = zoomOut;
    window.readerInitPage = initReaderPage;
    window.readerNavigate = navigateReader;

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initReaderPage, { once: true });
    } else {
      initReaderPage();
    }
  })();
</script>
@endsection
