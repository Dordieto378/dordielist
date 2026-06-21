@extends('layouts.app')

@section('content')
    @php
        $shortTitle = fn ($title, $maxLen = 25) => strlen((string) $title) <= $maxLen
            ? (string) $title
            : substr((string) $title, 0, $maxLen - 1).'...';

        $displayMediaType = function (array $item): string {
            $type = strtoupper((string) ($item['type'] ?? ''));
            $genres = array_map('strtolower', $item['genres'] ?? []);

            if ($type === 'ANIME') {
                return in_array('hentai', $genres, true) ? 'Hentai' : 'Anime';
            }

            if ($type === 'MANGA') {
                if (in_array('hentai', $genres, true)) {
                    return 'H-manga';
                }

                return strtoupper((string) ($item['countryOfOrigin'] ?? '')) === 'KR' ? 'Manhwa' : 'Manga';
            }

            if ($type === 'MANHWA') {
                return 'Manhwa';
            }

            if ($type === 'HENTAI') {
                return 'Hentai';
            }

            if ($type === 'DOUJIN') {
                return 'Doujin';
            }

            if ($type === 'VN') {
                return 'Visual Novel';
            }

            return ucfirst(strtolower($type));
        };

        $isGrid = ($selectedView === 'grid');
        $isList = ($selectedView === 'list');
        $currentPage = $paginatedMedia->currentPage();
        $lastPage = $paginatedMedia->lastPage();
        $viewUrl = fn (string $view) => request()->fullUrlWithQuery(['view' => $view, 'page' => $currentPage]);
        $pageUrl = fn (int $page) => request()->fullUrlWithQuery(['page' => $page, 'view' => $selectedView]);
    @endphp

    <style>
        .thumb-wrapper {
            width: 302px;
            height: 424px;
        }
        .thumb-wrapper.thumb-landscape {
            height: 190px;
        }
        .thumb-img {
            object-fit: cover;
        }
    </style>

    <section class="ml-4 flex flex-col items-center py-[6rem]">
        <div class="w-[1300px] flex justify-between items-start">
            <div class="mb-[1.5rem]">
                <h2 class="text-2xl text-red-600">{{ $heading }}</h2>
                @if(!empty($socialRows))
                    <p class="mt-2 text-sm text-gray-700 font-medium normal-case">
                        Socials:
                        @foreach($socialRows as $row)
                            <a href="{{ $row['url'] }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="text-blue-600 cursor-pointer">
                                {{ $row['label'] }}
                            </a>@if(!$loop->last), @endif
                        @endforeach
                    </p>
                @endif
            </div>

            <div class="flex flex-row border-2 border-gray-200 bg-gray-100 h-fit rounded-lg overflow-hidden mr-[1rem]">
                <a href="{{ $viewUrl('list') }}"
                   id="listViewBtn"
                   class="rounded-l-md inline-flex items-center px-3 py-[.4rem] cursor-pointer border-r-2 {{ $isList ? 'bg-white' : 'bg-gray-300' }} transition duration-300">
                    <img src="{{ asset('images/list.png') }}"
                         alt="List View"
                         class="w-6 h-6 {{ $isList ? 'opacity-100' : 'opacity-50 hover:opacity-100' }}">
                </a>

                <a href="{{ $viewUrl('grid') }}"
                   id="gridViewBtn"
                   class="rounded-r-md inline-flex items-center px-3 py-[.4rem] cursor-pointer {{ $isGrid ? 'bg-white' : 'bg-gray-300' }} transition duration-300">
                    <img src="{{ asset('images/grid.png') }}"
                         alt="Grid View"
                         class="w-5 h-5 {{ $isGrid ? 'opacity-100' : 'opacity-50 hover:opacity-100' }}">
                </a>
            </div>
        </div>

        <div id="contentContainer"
             class="w-[1300px] {{ $isGrid ? 'grid grid-cols-4 gap-2' : 'flex flex-col gap-6' }}">
            @forelse ($paginatedMedia as $item)
                @php
                    $fullTitle = $item['title']['english']
                        ?? $item['title']['romaji']
                        ?? $item['title']['native']
                        ?? 'No Title';

                    $href = $item['type'] === 'DOUJIN'
                        ? "/doujin/{$item['id']}"
                        : ($item['type'] === 'VN'
                            ? "/vn/{$item['id']}"
                            : "/media/{$item['id']}");
                @endphp

                <div class="card grid-view w-[305px] flex-shrink-0 overflow-hidden {{ $isGrid ? '' : 'hidden' }}">
                    <a href="{{ $href }}" class="block">
                        <div class="relative thumb-wrapper thumb-portrait rounded-lg shadow-lg overflow-hidden">
                            <img
                                src="{{ $item['coverImage']['extraLarge'] ?? asset('images/no-image.jpg') }}"
                                alt="Cover Image"
                                class="thumb-img w-full h-full object-cover"
                            >
                        </div>
                    </a>
                    <div class="py-3">
                        <a href="{{ $href }}" class="text-red-600 font-bold">
                            {{ $shortTitle($fullTitle, 30) }}
                        </a>
                        <p class="text-gray-600 font-medium">
                            {{ $displayMediaType($item) }}
                        </p>
                    </div>
                </div>

                <div class="card list-view flex w-[1284px] bg-white rounded-lg shadow-lg overflow-hidden {{ $isList ? '' : 'hidden' }}">
                    <a href="{{ $href }}" class="flex">
                        <img src="{{ $item['coverImage']['extraLarge'] ?? asset('images/no-image.jpg') }}"
                             alt="Cover Image"
                             class="w-[256px] h-[360px] object-cover">
                        <img
                            src="{{ $item['type'] === 'DOUJIN'
                                ? ($item['listPreviewImage'] ?? asset('images/no-image.jpg'))
                                : ($item['bannerImage'] ?? asset('images/no-image.jpg')) }}"
                            alt="{{ $item['type'] === 'DOUJIN' ? 'Preview Page' : 'Banner Image' }}"
                            class="w-[256px] h-[360px] object-cover"
                        >
                    </a>

                    <div class="w-1/2 p-6 flex flex-col items-start text-left ml-[0.6rem] mt-[0.5rem]">
                        <div>
                            <a href="{{ $href }}" class="text-red-600 font-bold text-2xl">
                                {{ strlen($fullTitle) > 90 ? substr($fullTitle, 0, 87).'...' : $fullTitle }}
                            </a>

                            @php
                                $metaItems = $item['type'] === 'DOUJIN'
                                    ? array_values(array_filter($item['authors'] ?? []))
                                    : array_values(array_filter($item['genres'] ?? []));
                                $metaLabel = $item['type'] === 'DOUJIN' ? 'Author' : 'Genre';
                                $metaFilter = $item['type'] === 'DOUJIN' ? 'author' : 'genre';
                                $metaCategory = match ($item['type']) {
                                    'ANIME' => 'animes',
                                    'HENTAI' => 'hentais',
                                    'MANHWA' => 'manhwas',
                                    'MANGA' => 'mangas',
                                    'DOUJIN' => 'doujins',
                                    default => null,
                                };
                            @endphp
                            @if ($metaItems)
                                <p class="text-gray-900 font-medium text-sm mt-[0.8rem]">
                                    {{ $metaLabel }}:
                                    @foreach($metaItems as $metaItem)
                                        @if($metaCategory)
                                            <a href="{{ category_filter_url($metaCategory, $metaFilter, $metaItem) }}" class="text-blue-600 cursor-pointer">
                                                {{ $metaItem }}
                                            </a>
                                        @endif
                                        @if(!$loop->last), @endif
                                    @endforeach
                                </p>
                            @endif

                            @php
                                $syn = $item['description'] ?? ' ';
                                $shortSyn = strlen($syn) > 150 ? substr($syn, 0, 147).'...' : $syn;
                            @endphp
                            <p class="text-gray-900 font-medium text-sm mt-[1rem] normal-case">
                                {{ $shortSyn }}
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2 mt-auto text-black font-medium text-sm mb-[0.6rem]">
                            @php
                                $tagsToShow = array_slice($item['tags'] ?? [], 0, 10);
                            @endphp
                            @foreach($tagsToShow as $tag)
                                @php
                                    $tagCategory = match ($item['type']) {
                                        'ANIME' => 'animes',
                                        'MANGA' => 'mangas',
                                        'MANHWA' => 'manhwas',
                                        'HENTAI' => 'hentais',
                                        'VN' => 'visual-novel',
                                        default => null,
                                    };
                                @endphp
                                @if($tagCategory)
                                    <span class="px-4 py-2 bg-gray-100 rounded-sm hover:bg-gray-200 transition duration-200 cursor-pointer">
                                        <a href="{{ category_filter_url($tagCategory, 'tags', $tag) }}">{{ $tag }}</a>
                                    </span>
                                @endif
                            @endforeach
                            @if(count($item['tags'] ?? []) > 10)
                                <span class="px-3 py-1 bg-gray-200 rounded-sm">...</span>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-span-4 py-20 text-center text-gray-600 font-medium">
                    No items found.
                </div>
            @endforelse
        </div>
    </section>

    <div id="pagination"
         class="flex items-center justify-center space-x-2 mt-6 mb-6 {{ $lastPage > 1 ? '' : 'hidden' }}">
        <span class="text-gray-600 text-lg font-medium">Pages</span>

        @if($currentPage > 1)
            <a href="{{ $pageUrl(1) }}" id="firstPage" class="pagination-arrow mb-1">&laquo;</a>
            <a href="{{ $pageUrl($currentPage - 1) }}" id="prevPage" class="pagination-arrow mb-1">&lsaquo;</a>
        @endif

        <div id="pageNumbers" class="flex space-x-2 text-lg">
            @php
                $maxVisible = 7;
                $start = max(1, $currentPage - intdiv($maxVisible, 2));
                $end = min($lastPage, $start + $maxVisible - 1);

                if ($end - $start + 1 < $maxVisible) {
                    $start = max(1, $end - $maxVisible + 1);
                }
            @endphp
            @for ($i = $start; $i <= $end; $i++)
                @if ($i == $currentPage)
                    <span class="pagination-btn pagination-active">{{ $i }}</span>
                @else
                    <a href="{{ $pageUrl($i) }}" class="pagination-btn non-selected-page-number">
                        {{ $i }}
                    </a>
                @endif
            @endfor
        </div>

        @if($currentPage < $lastPage)
            <a href="{{ $pageUrl($currentPage + 1) }}" id="nextPage" class="pagination-arrow mb-1">&rsaquo;</a>
            <a href="{{ $pageUrl($lastPage) }}" id="lastPage" class="pagination-arrow mb-1">&raquo;</a>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener("DOMContentLoaded", function () {
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

            document.querySelectorAll('.thumb-wrapper .thumb-img').forEach(img => {
                const apply = () => {
                    const wrap = img.closest('.thumb-wrapper');
                    if (!wrap) return;
                    if (img.naturalWidth > img.naturalHeight) {
                        wrap.classList.add('thumb-landscape');
                    } else {
                        wrap.classList.remove('thumb-landscape');
                    }
                };
                if (img.complete) apply();
                else img.addEventListener('load', apply, { once: true });
            });
        });
    </script>
@endpush
