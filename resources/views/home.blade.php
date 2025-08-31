@extends('layouts.app')

@section('content')
@php
function displayMediaType($item) {
    // Normalize type and genres to uppercase/lowercase for consistency
    $type = strtoupper($item['type'] ?? '');
    $genres = array_map('strtolower', $item['genres'] ?? []);
    
    if ($type === 'ANIME') {
        // If it's an anime and has the hentai genre, show as "Hentai"
        if (in_array('hentai', $genres)) {
            return 'Hentai';
        }
        return 'Anime';
    } elseif ($type === 'MANGA') {
        // If it's a manga and has the hentai genre, show as "H-manga"
        if (in_array('hentai', $genres)) {
            return 'H-manga';
        }
        // Check countryOfOrigin if provided (AniList usually returns a two-letter code)
        if (isset($item['countryOfOrigin'])) {
            $origin = strtoupper($item['countryOfOrigin']);
            if ($origin === 'KR') {
                return 'Manwha';
            } elseif ($origin === 'JP') {
                return 'Manga';
            }
        }
        return 'Manga';
    }
    return ucfirst(strtolower($type));
}
$isOnFirstPage = ($paginatedMedia->currentPage() === 1);

if (!function_exists('shortTitle')) {
    function shortTitle($title, $maxLen = 25) {
        if (strlen($title) <= $maxLen) {
            return $title;
        }
        return substr($title, 0, $maxLen - 1) . '…';
    }
}
@endphp

<div id="preContent" class="py-[4.5rem] {{ !$isOnFirstPage ? 'hidden' : '' }}">
    <!-- Scrollable Section -->
    <section class="bg-black text-white py-6">
        <div class="container mx-auto px-6">
            <div class="flex justify-between items-center">
                <h2 class="text-2xl font-bold px-24">Series that I've dropped!</h2>
                <span class="text-lg font-bold mr-[7rem]">Should i pick'em up again?</span>
            </div>
            <div class="relative group">
                <!-- Left Scroll Button -->
                <button id="scrollLeft"
                        class="transition-opacity duration-300 opacity-100 bg-opacity-80 absolute left-[7.5rem] top-[13rem] -translate-y-1/2 transform -translate-x-10 bg-gray-600 text-white w-12 h-12 flex items-center justify-center rounded-full shadow-lg focus:outline-none z-10 text-[1.4rem] pr-1 transition-transform duration-300 scale-100 group-hover:scale-110">
                    &#10094;
                </button>
                <div class="w-[1286px] ml-[5.65rem] overflow-x-auto custom-scrollbar">
                    <div class="flex space-x-0 h-[433px] text-sm">
                        @foreach($dropped as $item)
                            @php
                                // Determine if this AniList item is marked adult (hentai/pornographic)
                                $isNsfw     = $item['isAdult'] ?? false;
                                // Only guests see the blur:
                                $shouldBlur = $isNsfw && ! Auth::check();
                                
                                // Title logic:
                                $fullTitle = $item['title']['english'] 
                                            ?? $item['title']['romaji'] 
                                            ?? 'No Title';
                            @endphp

                            <div onclick="window.location.href='/media/{{ $item['id'] }}'"
                                 class="cursor-pointer w-[270px] flex-shrink-0 mt-7">
                                {{-- 1) Image container: relative so we can overlay the “18+” badge --}}
                                <div class="relative w-[256px] h-[360px] mx-auto rounded shadow-lg overflow-hidden">
                                    @if($shouldBlur)
                                        <img
                                            src="{{ asset('images/18-plus.png') }}"
                                            alt="18+"
                                            class="absolute top-2 right-2 w-8 h-8 z-10"
                                        >
                                    @endif
                                    <img 
                                        src="{{ $item['coverImage']['extraLarge'] ?? asset('images/6.jpg') }}"
                                        alt="Image"
                                        class="w-full h-full object-cover {{ $shouldBlur ? 'filter blur-2xl' : '' }}"
                                    >
                                </div>

                                <p class="mt-2 ml-2 text-left">
                                    {{ shortTitle($fullTitle, 30) }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </div>  
                <button id="scrollRight"
                        class="transition-opacity duration-300 opacity-100 bg-opacity-80 absolute right-[8.5rem] top-[13rem] -translate-y-1/2 transform translate-x-10 bg-gray-600 text-white w-12 h-12 flex items-center justify-center rounded-full shadow-lg focus:outline-none z-10 text-[1.4rem] pl-1 transition-transform duration-300 scale-100 group-hover:scale-110">
                    &#10095;
                </button>
            </div>
        </div>
    </section>

    <section class="mt-5 ml-4 flex flex-col items-center">
        <!-- Title aligned to the left -->
        <div class="w-[1300px]">
            <h2 class="text-2xl text-black mb-6">My most highly Rated</h2>
        </div>
        <!-- Centered Card Container -->
        <div class="w-[1300px] grid grid-cols-4 gap-2">
            <!-- Show top 4 series you rated the highest -->
            @foreach($highestRated4 as $item)
                @php
                    $isNsfw     = $item['isAdult'] ?? false;
                    $shouldBlur = $isNsfw && ! Auth::check();

                    $fullTitle = $item['title']['english'] 
                              ?? $item['title']['romaji'] 
                              ?? 'No Title';
                @endphp
                <div onclick="window.location.href='/media/{{ $item['id'] }}'"
                     class="cursor-pointer flex-shrink-0 overflow-hidden">
                    {{-- 1) Image container with blur logic --}}
                    <div class="relative w-[302px] h-[424px] rounded-lg shadow-lg overflow-hidden">
                        @if($shouldBlur)
                            <img
                                src="{{ asset('images/18-plus.png') }}"
                                alt="18+"
                                class="absolute top-2 right-2 w-8 h-8 z-10"
                            >
                        @endif
                        <img 
                            src="{{ $item['coverImage']['extraLarge'] ?? asset('images/no-image.jpg') }}"
                            alt="Cover Image"
                            class="w-full h-full object-cover {{ $shouldBlur ? 'filter blur-2xl' : '' }}"
                        >
                    </div>

                    <div class="py-3 mr-10">
                        <p class="text-red-600">
                            {{ shortTitle($fullTitle, 30) }}
                        </p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</div>      

<section class="ml-4 flex flex-col items-center {{ !$isOnFirstPage ? 'py-[6rem]' : '' }}">
    <!-- Title and Toggle Buttons -->
    <div class="w-[1300px] flex justify-between items-center">
        <h2 class="text-2xl text-black mb-[1.5rem]">Most recent</h2>

        {{-- Toggle Buttons --}}
        @php
            // Decide which button is "active" based on $selectedView
            $isGrid = ($selectedView === 'grid');
            $isList = ($selectedView === 'list');
        @endphp
        <div class="flex flex-row border-2 border-gray-200 bg-gray-100
                    h-fit rounded-lg overflow-hidden mr-[1rem] mb-[1.5rem]">
            <!-- List View Button -->
            <a href="{{ $paginatedMedia->url($paginatedMedia->currentPage()) }}&view=list"
              id="listViewBtn"
              class="rounded-l-md inline-flex items-center px-3 py-[.4rem]
                      cursor-pointer border-r-2
                      {{ $isList ? 'bg-white' : 'bg-gray-300' }}
                      transition duration-300">
                <img src="{{ asset('images/list.png') }}"
                    alt="List View"
                    class="w-6 h-6
                    {{ $isList ? 'opacity-100' : 'opacity-50 hover:opacity-100' }}">
            </a>

            <!-- Grid View Button -->
            <a href="{{ $paginatedMedia->url($paginatedMedia->currentPage()) }}&view=grid"
              id="gridViewBtn"
              class="rounded-r-md inline-flex items-center px-3 py-[.4rem]
                      cursor-pointer
                      {{ $isGrid ? 'bg-white' : 'bg-gray-300' }}
                      transition duration-300">
                <img src="{{ asset('images/grid.png') }}"
                    alt="Grid View"
                    class="w-5 h-5
                    {{ $isGrid ? 'opacity-100' : 'opacity-50 hover:opacity-100' }}">
            </a>
        </div>
    </div>


    <!-- Content Section -->
    <div id="contentContainer"
        class="w-[1300px]
                {{ $isGrid ? 'grid grid-cols-4 gap-2' : 'flex flex-col gap-6' }}">
        @foreach ($paginatedMedia as $item)
            @php
                $fullTitle = $item['title']['english']
                            ?? $item['title']['romaji']
                            ?? 'No Title';

                // Blur logic for AniList:
                $isNsfw     = $item['isAdult'] ?? false;
                $shouldBlur = $isNsfw && ! Auth::check();
            @endphp

            <!-- Grid version -->
            <div onclick="window.location.href='/media/{{ $item['id'] }}'"
                 class="cursor-pointer card grid-view
                        w-[305px] flex-shrink-0 overflow-hidden
                        {{ $isGrid ? '' : 'hidden' }}">
                {{-- Image container --}}
                <div class="relative w-[302px] h-[424px] rounded-lg shadow-lg overflow-hidden">
                    @if($shouldBlur)
                        <img
                            src="{{ asset('images/18-plus.png') }}"
                            alt="18+"
                            class="absolute top-2 right-2 w-8 h-8 z-10"
                        >
                    @endif
                    <img 
                        src="{{ $item['coverImage']['extraLarge'] ?? asset('images/no-image.jpg') }}"
                        alt="Cover Image"
                        class="w-full h-full object-cover {{ $shouldBlur ? 'filter blur-2xl' : '' }}"
                    >
                </div>
                <div class="py-3">
                    <p class="text-red-600 font-bold">
                        {{ shortTitle($fullTitle, 30) }}
                    </p>
                    <p class="text-gray-600 font-medium">
                        {{ displayMediaType($item) }}
                    </p>  
                </div>
            </div>

            <!-- List version -->
            <div class="card list-view
                        flex w-[1284px] bg-white rounded-lg
                        shadow-lg overflow-hidden
                        {{ $isList ? '' : 'hidden' }}">
                    <div onclick="window.location.href='/media/{{ $item['id'] }}'" class="cursor-pointer flex">
                        @if($shouldBlur)
                            <img
                                src="{{ asset('images/18-plus.png') }}"
                                alt="18+"
                                class="absolute top-2 right-2 w-8 h-8 z-10"
                            >
                        @endif
                        <img src="{{ $item['coverImage']['extraLarge'] ?? asset('images/no-image.jpg') }}"
                            alt="Cover Image"
                            class="w-[256px] h-[360px] object-cover {{ $shouldBlur ? 'filter blur-2xl' : '' }}">
                        <img
                        src="{{ $item['bannerImage'] ?? asset('images/no-image.jpg') }}"
                        alt="Banner Image"
                        class="w-[256px] h-[360px] object-cover {{ $shouldBlur ? 'filter blur-2xl' : '' }}"
                        >
                    </div>


                <div class="w-1/2 p-6 flex flex-col items-start text-left ml-[0.6rem] mt-[0.5rem]">
                    <div>
                        @php
                            $shortT = (strlen($fullTitle) > 90)
                                      ? substr($fullTitle, 0, 87).'...'
                                      : $fullTitle;
                        @endphp
                        <p class="text-red-600 font-bold text-2xl hover:underline cursor-pointer">
                            {{ $shortT }}
                        </p>
                        <p class="text-gray-900 font-medium text-sm mt-[0.8rem]">
                            Genre:
                            @foreach($item['genres'] ?? [] as $genre)
                                <a class="text-blue-600 hover:underline cursor-pointer">
                                    {{ $genre }}
                                </a>
                            @endforeach
                        </p>
                        @php
                            $syn = $item['description'] ?? 'No synopsis available.';
                            $shortSyn = strlen($syn) > 150
                                        ? substr($syn, 0, 147) . '...'
                                        : $syn;
                        @endphp
                        <p class="text-gray-900 font-medium text-sm mt-[1rem]">
                            {{ $shortSyn }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 mt-auto text-black font-medium text-sm mb-[0.6rem]">
                        @php
                            $maxTags = 10;
                            $tagsToShow = array_slice($item['tags'], 0, $maxTags);
                        @endphp
                        @foreach($tagsToShow as $tag)
                            <span class="px-4 py-2 bg-gray-100
                                        rounded-sm hover:bg-gray-200
                                        transition duration-200 cursor-pointer">
                                {{ $tag }}
                            </span>
                        @endforeach
                        @if(count($item['tags']) > $maxTags)
                            <span class="px-3 py-1 bg-gray-200 rounded-sm">...</span>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>

@php
    $currentPage = $paginatedMedia->currentPage();
    $lastPage    = $paginatedMedia->lastPage();
@endphp

<div id="pagination"
     class="flex items-center justify-center space-x-2 mt-6 mb-6
            {{ $lastPage > 1 ? '' : 'hidden' }}">
    <span class="text-gray-600 text-lg font-medium">Pages</span>

    <!-- “<<” first page -->
    @if($currentPage > 1)
        <a href="{{ $paginatedMedia->url(1) }}&view={{ $selectedView }}"
          id="firstPage"
          class="pagination-arrow mb-1">&laquo;</a>
        <a href="{{ $paginatedMedia->previousPageUrl() }}&view={{ $selectedView }}"
          id="prevPage"
          class="pagination-arrow mb-1">&lsaquo;</a>
    @endif

    <div id="pageNumbers" class="flex space-x-2 text-lg">
        @php
            $maxVisible = 7;
            $start = max(1, $currentPage - intdiv($maxVisible,2));
            $end   = min($lastPage, $start + $maxVisible - 1);

            if($end - $start + 1 < $maxVisible) {
                $start = max(1, $end - $maxVisible + 1);
            }
        @endphp
        @for ($i = $start; $i <= $end; $i++)
            @if ($i == $currentPage)
                <span class="pagination-btn pagination-active">{{ $i }}</span>
            @else
                <a href="{{ $paginatedMedia->url($i) }}&view={{ $selectedView }}"
                  class="pagination-btn non-selected-page-number">
                  {{ $i }}
                </a>
            @endif
        @endfor
    </div>

    @if($currentPage < $lastPage)
        <a href="{{ $paginatedMedia->nextPageUrl() }}&view={{ $selectedView }}"
          id="nextPage"
          class="pagination-arrow mb-1">&rsaquo;</a>
        <a href="{{ $paginatedMedia->url($lastPage) }}&view={{ $selectedView }}"
          id="lastPage"
          class="pagination-arrow mb-1">&raquo;</a>
    @endif
</div>
@endsection

<script>
document.addEventListener("DOMContentLoaded", function () {
    // -- A) Horizontal scroll for “Dropped”
    const scrollContainer = document.querySelector(".custom-scrollbar");
    const scrollLeft = document.getElementById("scrollLeft");
    const scrollRight = document.getElementById("scrollRight");
    if (scrollContainer && scrollLeft && scrollRight) {
        const scrollAmount = scrollContainer.scrollWidth / 3;
        function updateScrollButtons() {
            if (scrollContainer.scrollLeft <= 0) {
                scrollLeft.style.opacity = "0";
                scrollLeft.style.pointerEvents = "none";
            } else {
                scrollLeft.style.opacity = "1";
                scrollLeft.style.pointerEvents = "auto";
            }
            if (scrollContainer.scrollLeft + scrollContainer.clientWidth >= scrollContainer.scrollWidth - 5) {
                scrollRight.style.opacity = "0";
                scrollRight.style.pointerEvents = "none";
            } else {
                scrollRight.style.opacity = "1";
                scrollRight.style.pointerEvents = "auto";
            }
        }
        scrollLeft.addEventListener("click", () => {
            scrollContainer.scrollBy({ left: -scrollAmount, behavior: "smooth" });
        });
        scrollRight.addEventListener("click", () => {
            scrollContainer.scrollBy({ left: scrollAmount, behavior: "smooth" });
        });
        scrollContainer.addEventListener("scroll", updateScrollButtons);
        updateScrollButtons();
    }

    // -- B) Hover brightness for toggle buttons (List, Grid)
    const gridViewBtn = document.getElementById("gridViewBtn");
    const listViewBtn = document.getElementById("listViewBtn");

    if (gridViewBtn) {
        const gridViewImg = gridViewBtn.querySelector("img");
        gridViewBtn.addEventListener("mouseenter", () => {
            if (!gridViewBtn.classList.contains("bg-white")) {
                gridViewImg.classList.remove("brightness-75");
                gridViewImg.classList.add("brightness-90");
            }
        });
        gridViewBtn.addEventListener("mouseleave", () => {
            if (!gridViewBtn.classList.contains("bg-white")) {
                gridViewImg.classList.remove("brightness-90");
                gridViewImg.classList.add("brightness-75");
            }
        });
    }

    if (listViewBtn) {
        const listViewImg = listViewBtn.querySelector("img");
        listViewBtn.addEventListener("mouseenter", () => {
            if (!listViewBtn.classList.contains("bg-white")) {
                listViewImg.classList.remove("brightness-75");
                listViewImg.classList.add("brightness-90");
            }
        });
        listViewBtn.addEventListener("mouseleave", () => {
            if (!listViewBtn.classList.contains("bg-white")) {
                listViewImg.classList.remove("brightness-90");
                listViewImg.classList.add("brightness-75");
            }
        });
    }
});
</script>
