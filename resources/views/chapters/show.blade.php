@extends('layouts.app')

@section('content')
@php
    use Illuminate\Support\Facades\Storage;

    // Page title
    $title       = "Chapter {$chapter->chapter_number}";
    $isGuest     = ! Auth::check();
    $shouldBlur  = $isGuest && true;            // or whatever your NSFW logic is
    $pageRows    = $pages->chunk(4);
    $allowedExts = ['jpg','jpeg','png','gif','webp'];
@endphp

<div class="flex flex-col items-center py-[4rem] mt-12">
  <div class="w-[1280px] bg-white shadow-sm rounded-md p-6 ml-[0.5rem] space-y-6">

    {{-- header (identical to doujin header) --}}
    <div class="relative flex items-center mb-4">
      <h1 class="flex-1 text-2xl font-bold text-red-600">
        {{ $title }}
      </h1>
      {{-- zoom buttons, view switches, etc. (copy your doujin controls here if needed) --}}
    </div>

    {{-- page grid (exact same as your doujin grid) --}}
    @if($pages->isNotEmpty())
      <div class="flex justify-center mb-12 mt-[-100px]">
        <div
          id="pagesGrid"
          class="w-[1278px] grid grid-cols-4 gap-4
                 transition-opacity duration-500 ease-in-out"
        >
          @foreach($pageRows as $rowIndex => $row)
            @php
              // snake order: even rows reverse
              $cells = $rowIndex % 2
                        ? $row->values()
                        : $row->reverse()->values();
              $pad   = 4 - $cells->count();
            @endphp

            {{-- pad empty slots on even rows --}}
            @if($rowIndex % 2 === 0 && $pad)
              @for($i=0; $i<$pad; $i++)<div></div>@endfor
            @endif

            @foreach($cells as $page)
              @php
                $ext = strtolower(pathinfo($page->file_path, PATHINFO_EXTENSION));
                if (! in_array($ext, $allowedExts, true)) continue;
                $url = Storage::disk('b2')->url($page->file_path);
              @endphp

              <div
                onclick="window.location.href='{{ route('chapters.page', [
                    'media'   => $chapter->item_id,
                    'chapter' => $chapter->chapter_number,
                    'page'    => $page->chapter_number
                ]) }}'"
                class="cursor-pointer"
              >
                <div class="relative w-full rounded-lg overflow-hidden shadow-lg">
                  @if($shouldBlur)
                    <img
                      src="{{ asset('images/18-plus.png') }}"
                      alt="18+"
                      class="absolute top-2 right-2 w-6 h-6 z-10"
                    >
                  @endif

                  <img
                    src="{{ $url }}"
                    alt="Page {{ $page->chapter_number }}"
                    class="w-full h-auto object-contain {{ $shouldBlur ? 'filter blur-2xl' : '' }}"
                  >
                </div>
              </div>
            @endforeach
          @endforeach
        </div>
      </div>
    @else
      <p class="text-center text-gray-500 font-medium mb-12">
        No pages found for this chapter.
      </p>
    @endif

  </div>
</div>
@endsection
