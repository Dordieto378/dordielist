@extends('layouts.app')

@section('content')
@php
    use Illuminate\Support\Facades\Storage;
    function shortTitle($title, $maxLen = 25) {
        if (strlen($title) <= $maxLen) {
            return $title;
        }
        return substr($title, 0, $maxLen - 1) . '…';
    }

    $shouldBlur = true && ! Auth::check();

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
@endphp

<div class="flex flex-col items-center py-[4rem] mt-12">
  <div class="w-[1280px] bg-white shadow-sm rounded-md p-6 ml-[0.5rem] space-y-6">
    <div class="relative flex items-center mb-4">
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
      @php
        $baseParams = ['doujin' => $doujin->id];
      @endphp
      <div class="flex-1 flex justify-end space-x-4">
        <a
          href="{{ route('media.doujin.page', array_merge($baseParams, ['page' => $pageNumber, 'view' => 'scroll'])) }}"
          class="p-2 rounded {{ $view === 'scroll' ? 'bg-gray-800 text-white' : 'bg-gray-200 text-gray-700' }} transition hover:bg-white hover:text-black"
          title="Scroll Mode"
        >
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm5-4a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1zm-2 8a1 1 0 011-1h6a1 1 0 110 2H8a1 1 0 01-1-1z" clip-rule="evenodd" />
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
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
            <path d="M2 3a1 1 0 011-1h7a1 1 0 011 1v2h4V3a1 1 0 011-1h7a1 1 0 011 1v15a2 2 0 01-2 2H4a2 2 0 01-2-2V3zm2 3v12h3V6H4zm5 0v12h3V6H9zm5 0v12h5V6h-5z" />
          </svg>
        </a>

      </div>
    </div>
    @if($view === 'scroll')
    <div>
        @foreach($doujin->pages as $p)
        @php
            $ext = strtolower(pathinfo($p->file_path, PATHINFO_EXTENSION) ?? '');
            $isImage = in_array($ext, $allowedExts, true);
            $url = $isImage ? Storage::disk('b2')->url($p->file_path) : null;
        @endphp

        @if($isImage)
            <div class="relative w-full overflow-hidden">
            @if($shouldBlur)
                <img
                src="{{ asset('images/18-plus.png') }}"
                alt="18+"
                class="absolute top-4 right-4 w-10 h-10 z-30"
                >
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

    @elseif($view === 'one')
        @php
            $extOne = strtolower(pathinfo($doujin->pages->where('page_number', $pageNumber)->first()->file_path, PATHINFO_EXTENSION) ?? '');
            $isSingleImage = in_array($extOne, $allowedExts, true);
            $singleUrl = $isSingleImage ? $pageUrl : null;
        @endphp

        @if($isSingleImage)
            <div class="relative w-full overflow-hidden">
                @if($shouldBlur)
                    <img
                        src="{{ asset('images/18-plus.png') }}"
                        alt="18+"
                        class="absolute top-4 right-4 w-10 h-10 z-30"
                    >
                @endif

                <img
                    src="{{ $singleUrl }}"
                    alt="Page {{ $pageNumber }}"
                    class="zoomable w-full h-auto object-contain mx-auto {{ $shouldBlur ? 'filter blur-2xl' : '' }} z-10"
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
        $leftUrl  = $isLeftImage  ? Storage::disk('b2')->url(
                    $doujin->pages->where('page_number', $leftNum)->first()->file_path
                    ) : null;
        $rightUrl = $isRightImage ? Storage::disk('b2')->url(
                    $doujin->pages->where('page_number', $rightNum)->first()->file_path
                    ) : null;
    @endphp

    <div class="relative w-full overflow-hidden">
        <div class="flex justify-center space-x-2">
        @if($isLeftImage)
            <div class="relative overflow-hidden">
            @if($shouldBlur)
                <img
                src="{{ asset('images/18-plus.png') }}"
                alt="18+"
                class="absolute top-4 right-4 w-10 h-10 z-30"
                >
            @endif
            <img
                src="{{ $leftUrl }}"
                alt="Page {{ $leftNum }}"
                class="zoomable h-auto object-contain mx-auto {{ $shouldBlur ? 'filter blur-2xl' : '' }}"
            >
            </div>
        @endif
        @if($isRightImage)
            <div class="relative overflow-hidden">
            @if($shouldBlur)
                <img
                src="{{ asset('images/18-plus.png') }}"
                alt="18+"
                class="absolute top-4 right-4 w-10 h-10 z-30"
                >
            @endif
            <img
                src="{{ $rightUrl }}"
                alt="Page {{ $rightNum }}"
                class="zoomable h-auto object-contain mx-auto {{ $shouldBlur ? 'filter blur-2xl' : '' }}"
            >
            </div>
        @endif
        </div>
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
    @if($view === 'one')
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
    @elseif($view === 'double')
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
  let zoomLevel = parseFloat(localStorage.getItem('doujinZoom')) || 1.0;

  function updateZoom() {
    document.querySelectorAll('.zoomable').forEach(img => {
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
    zoomLevel = Math.min(zoomLevel + 0.05, 1.0);
    updateZoom();
  }

  function zoomOut() {
    zoomLevel = Math.max(zoomLevel - 0.05, 0.2);
    updateZoom();
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.zoomable').forEach(img => {
      img.dataset.originalHeight = img.clientHeight;
      img.style.removeProperty('max-height');
      img.style.width = 'auto';
    });
    updateZoom();
  });
</script>
@endsection
