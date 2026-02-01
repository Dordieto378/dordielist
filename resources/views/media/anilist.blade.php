@extends('layouts.app')

@section('content')
@php
    use App\Models\Chapter;
    use App\Models\Episode;
    use App\Models\Collection;
    use App\Models\CollectionItem;
    use App\Models\Favorite;
    $title = $item['title']['english']
          ?? $item['title']['romaji']
          ?? 'No Title';

    $type = strtoupper($item['type'] ?? '');

    $isEpisodeBased = in_array($type, ['ANIME', 'HENTAI']);
    $isChapterBased = in_array($type, ['MANGA', 'MANWHA']);

    $firstEpisode = null;
    if ($isEpisodeBased) {
        $firstEpisode = Episode::where('media_fk', $item['id'])
                               ->orderBy('episode_number')
                               ->first();
    }

    $firstChapter = null;
    if ($isChapterBased) {
        $firstChapter = Chapter::where('item_id', $item['id'])
                               ->orderBy('chapter_number')
                               ->first();
    }

    $releaseDate = 'N/A';
    if (isset($item['startDate']['year'])) {
        $day   = $item['startDate']['day'] ?? 1;
        $month = $item['startDate']['month'] ?? 1;
        $year  = $item['startDate']['year'];
        // Create a DateTime from the month number to format the abbreviated month name
        $dt    = DateTime::createFromFormat('!m', $month);
        $releaseDate = $day . ' ' . $dt->format('M') . ' ' . $year;
    }

    $averageScore = isset($item['averageScore']) ? $item['averageScore'] . '%' : 'N/A';
    $myScore = 'N/A';
    if (isset($item['mediaListEntry']) && !empty($item['mediaListEntry']['score']) && $item['mediaListEntry']['score'] > 0) {
        $myScore = $item['mediaListEntry']['score'] . '%';
    }

    $statusMapping = [
        'FINISHED' => 'Finished',
        'RELEASING' => 'Releasing',
        'NOT_YET_RELEASED' => 'Not Yet Released',
        'CANCELLED' => 'Cancelled',
    ];
    $status = $item['status'] ?? 'N/A';
    $status = $statusMapping[$status] ?? ucfirst(strtolower($status));

    $normalizedTypeFromItem = match (strtoupper($item['type'] ?? '')) {
        'ANIME'  => 'animes',
        'HENTAI' => 'hentais',
        'MANGA'  => 'mangas',
        'MANWHA' => 'manwhas',
        default  => 'animes',
    };

    $filterSlug = $normalizedTypeFromItem;

    $numericId = (int) ltrim((string) $id, 'v');

    // Recompute current state for this page render
    $isFavorited = Favorite::where('favoritable_type', $normalizedTypeFromItem)
                           ->where('favoritable_id',   $numericId)
                           ->exists();

    $attachedIds = CollectionItem::where('item_type', $normalizedTypeFromItem)
                                 ->where('item_id',   $numericId)
                                 ->pluck('collection_id')
                                 ->toArray();

    // Collections list for the modal
    $allCollections = Collection::orderBy('name')->get();

@endphp

<div class="flex flex-col items-center py-[8.5rem]">
    <div class="w-[1280px] h-auto bg-white shadow-sm rounded-md p-6 ml-[0.5rem]">
        <div class="flex flex-col md:flex-row">
            {{-- Left Column: Image & Buttons --}}
            <div class="flex flex-col items-center">
                <div class="thumb-wrapper thumb-portrait relative w-[325px] h-[450px] overflow-hidden rounded">
                    <img
                        src="{{ $item['coverImage']['extraLarge'] ?? asset('images/no-image.jpg') }}"
                        alt="Cover Image"
                        class="thumb-img w-full h-full">
                </div>
                @auth
                <div class="mt-4 flex flex-col space-y-3 w-[325px] font-bold">
                    @php
                        $enabled = $isEpisodeBased ? $firstEpisode : $firstChapter;

                        if ($isEpisodeBased) {
                            $url   = route('episodes.show', [
                                'media'   => $item['id'],
                                'episode' => $firstEpisode?->episode_number ?? 1
                            ]);
                            $label = 'Start Watching';
                        } else { // chapter-based
                            $url   = route('chapters.page', [
                                'media'   => $item['id'],
                                'chapter' => $firstChapter?->chapter_number ?? 1,
                                'page'    => 1,
                                'view'    => 'one'
                            ]);
                            $label = 'Start Reading';
                        }
                    @endphp

                    @if ($enabled)
                        <a href="{{ $url }}"
                           class="flex items-center justify-start w-full flatGreen text-white
              py-2 rounded-sm shadow-sm h-[50px] transition-200">
                            <svg class="ml-6 mb-[0.1rem]" width="15" height="15"
                                 viewBox="0 0 460.114 460.114" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                                <path d="M393.538 203.629L102.557 5.543c-9.793-6.666-22.468-7.372-32.94-1.832
                      -10.472 5.538-17.022 16.413-17.022 28.26v396.173c0 11.846 6.55
                      22.721 17.022 28.26 10.471 5.539 23.147 4.834 32.94-1.832l290.981-198.087
                      c8.746-5.954 13.98-15.848 13.98-26.428 0-10.58-5.234-20.475-13.981-26.428z"/>
                            </svg>
                            <span class="ml-3">{{ $label }}</span>
                        </a>
                    @endif
                    @php
                    $id       = $item['id'];
                    @endphp

                    <form action="{{ route('favorites.toggle') }}" method="POST" class="mt-2 w-full">
                        @csrf
                        <input type="hidden" name="favoritable_type" value="{{ $normalizedTypeFromItem }}">
                        <input type="hidden" name="favoritable_id"   value="{{ $id }}">
                        <button type="submit"
                                class="flex items-center w-full text-blue-950 py-2 rounded-sm
                                        hover:text-red-600 transition">

                            @if($isFavorited)
                            {{-- Filled heart --}}
                            <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" stroke="none"
                                class="ml-[1.4rem] h-[1.1rem] w-[1.1rem] mr-[0.5rem] mb-[0.1rem]"
                                viewBox="0 0 24 24">
                                <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5
                                        2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09
                                        C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5
                                        c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                            </svg>
                            @else
                            {{-- Outline heart --}}
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor"
                                class="ml-[1.4rem] h-[1.1rem] w-[1.1rem] mr-[0.5rem] mb-[0.1rem]"
                                stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5
                                        2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09
                                        C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5
                                        c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                            </svg>
                            @endif

                            {{ $isFavorited ? 'Remove from favorites' : 'Add to Favorites' }}
                        </button>
                    </form>

                    <button id="openAddToCollection" class="flex items-center justify-start w-full text-blue-950 py-2 rounded-sm hover:text-yellow-400">
                        <!-- folder icon (Heroicons outline) -->
                        <svg xmlns="http://www.w3.org/2000/svg"
                            class="ml-[1.4rem] h-[1.1rem] w-[1.1rem] mr-[0.5rem] mb-[0.1rem]"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M3 7a2 2 0 012-2h5l2 2h5a2 2 0 012 2v1H3V7z
                                    M3 11h18v7a2 2 0 01-2 2H5a2 2 0 01-2-2v-7z"/>
                        </svg>
                        <span class="ml-[0.2rem]">Add to Collection</span>
                    </button>
                    @if(optional(auth()->user()->role)->role === 'Admin')
                        @if($isEpisodeBased)
                            <form method="POST" action="{{ route('episodes.sync', ['media' => $item['id']]) }}">
                                @csrf
                                <button type="submit"
                                        class="flex items-center justify-start w-full text-blue-950 py-2 rounded-sm hover:text-[#08875b]">
                                    <svg xmlns="http://www.w3.org/2000/svg"
                                         class="ml-[1.4rem] h-[1.1rem] w-[1.1rem] mr-[0.5rem] mb-[0.1rem]"
                                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="M15.232 5.232l3.536 3.536M4 21h4.586a1 1
                      0 00.707-.293l10-10a1 1 0 000-1.414L14.414 4.293a1 1
                      0 00-1.414 0l-10 10A1 1 0 004 14.586V19a2 2 0 002 2z"/>
                                    </svg>
                                    <span class="ml-1">Add Episode(s)</span>
                                </button>
                            </form>
                        @elseif($isChapterBased)
                            <form method="POST" action="{{ route('chapters.sync', ['media' => $item['id']]) }}">
                                @csrf
                                <button type="submit"
                                        class="flex items-center justify-start w-full text-blue-950 py-2 rounded-sm hover:text-[#08875b]">
                                    <svg xmlns="http://www.w3.org/2000/svg"
                                         class="ml-[1.4rem] h-[1.1rem] w-[1.1rem] mr-[0.5rem] mb-[0.1rem]"
                                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="M15.232 5.232l3.536 3.536M4 21h4.586a1 1
                      0 00.707-.293l10-10a1 1 0 000-1.414L14.414 4.293a1 1
                      0 00-1.414 0l-10 10A1 1 0 004 14.586V19a2 2 0 002 2z"/>
                                    </svg>
                                    <span class="ml-1">Add Chapter(s)</span>
                                </button>
                            </form>
                        @endif
                    @endif
                </div>
                @endauth
            </div>
            {{-- Right Column: Basic Info --}}
            <div class="flex flex-col justify-start ml-8 mt-4 md:mt-2 text-gray-900 font-medium">
                <h1 class="text-2xl font-bold text-red-600 mb-2">{{ $title }}</h1>
                <div class="grid grid-cols-[7rem,1fr] gap-x-3 gap-y-4 text-sm mt-2 mb-2">
                @if($isChapterBased)
                    <div>Chapters</div>
                    @php
                        $chapTotal     = $item['chapters'] ?? null;
                        $chapProgress  = $item['userProgress'] ?? null;

                        if ($chapProgress !== null) {
                            if ($chapTotal !== null && $chapTotal > 0) {
                                $chapDisplay = ($chapProgress < $chapTotal)
                                    ? "{$chapProgress} / {$chapTotal}"
                                    : $chapTotal;
                            } else {
                                // total unknown
                                $chapDisplay = "{$chapProgress} / N/A";
                            }
                        } else {
                            $chapDisplay = $chapTotal ?? 'N/A';
                        }
                    @endphp
                    <div>{{ $chapDisplay ?? 'N/A' }}</div>
                    @if(!empty($item['volumes']) && $item['volumes'] > 0)
                        <div>Volumes</div>
                        <div>{{ $item['volumes'] }}</div>
                    @endif
                @elseif($isEpisodeBased)
                    <div>Episodes</div>
                    @php
                        $epTotal     = $item['episodes'] ?? null;
                        $epProgress  = $item['userProgress'] ?? null;

                        if ($epProgress !== null) {
                            if ($epTotal !== null && $epTotal > 0) {
                                $epDisplay = ($epProgress < $epTotal)
                                    ? "{$epProgress} / {$epTotal}"
                                    : $epTotal;
                            } else {
                                $epDisplay = "{$epProgress} / N/A";
                            }
                        } else {
                            $epDisplay = $epTotal ?? 'N/A';
                        }
                    @endphp
                    <div>{{ $epDisplay ?? 'N/A' }}</div>
                @endif

                    <div>Status</div>
                    <div>{{ $status }}</div>

                    <div>Release Date</div>
                    <div>{{ $releaseDate }}</div>

                    <div>Average Score</div>
                    <div>{{ $averageScore }}</div>

                    <div>My Score</div>
                    <div>{{ $myScore }}</div>
                    @if(strtoupper($item['type'] ?? '') === 'MANGA')
                        <div>Author</div>
                        @php
                            $authors = collect($item['authors'] ?? [])->filter()->unique()->values();
                        @endphp
                        <div>
                            @if($authors->isEmpty())
                                N/A
                            @else
                                @foreach($authors as $name)
                                    <a href="{{ category_filter_url('mangas', 'author', $name) }}"
                                       class="text-blue-600 hover:underline cursor-pointer">
                                        {{ $name }}
                                    </a>@if(!$loop->last), @endif
                                @endforeach
                            @endif
                        </div>
                    @elseif(strtoupper($item['type'] ?? '') === 'MANWHA')
                        <div>Author</div>
                        @php
                            $authors = collect($item['authors'] ?? [])->filter()->unique()->values();
                        @endphp
                        <div>
                            @if($authors->isEmpty())
                                N/A
                            @else
                                @foreach($authors as $name)
                                    <a href="{{ category_filter_url('manwhas', 'author', $name) }}"
                                       class="text-blue-600 hover:underline cursor-pointer">
                                        {{ $name }}
                                    </a>@if(!$loop->last), @endif
                                @endforeach
                            @endif
                        </div>
                    @elseif(strtoupper($item['type'] ?? '') === 'HENTAI')
                        <div>Studios</div>
                        @php
                            $studios = collect($item['studios'] ?? [])->filter()->unique()->values();
                        @endphp
                        <div>
                            @if($studios->isEmpty())
                                N/A
                            @else
                                @foreach($studios as $studio)
                                    <a href="{{ category_filter_url('hentais', 'studio', $studio) }}"
                                       class="text-blue-600 hover:underline cursor-pointer">
                                        {{ $studio }}
                                    </a>@if(!$loop->last), @endif
                                @endforeach
                            @endif
                        </div>
                    @else
                        <div>Studios</div>
                        @php
                            $studios = collect($item['studios'] ?? [])->filter()->unique()->values();
                        @endphp
                        <div>
                            @if($studios->isEmpty())
                                N/A
                            @else
                                @foreach($studios as $studio)
                                    <a href="{{ category_filter_url('animes', 'studio', $studio) }}"
                                       class="text-blue-600 hover:underline cursor-pointer">
                                        {{ $studio }}
                                    </a>@if(!$loop->last), @endif
                                @endforeach
                            @endif
                        </div>
                    @endif

                    <div>Genres:</div>
                    @php
                        $genres = is_array($item['genres']) ? $item['genres'] : [];
                    @endphp
                    <div>
                        @if(empty($genres))
                            N/A
                        @else
                          @foreach($genres as $genre)
                              <a  href="{{ category_filter_url($normalizedTypeFromItem, 'genre', $genre) }}"
                                  class="text-blue-600 hover:underline cursor-pointer">
                                  {{ $genre }}
                              </a>@if(!$loop->last), @endif
                          @endforeach
                        @endif
                    </div>
                </div>
                <p class="text-sm mb-2 mt-2">
                    {!! nl2br(e($item['description'])) !!}
                </p>
                <div class="mb-2 mt-2">
                    <div class="flex flex-wrap gap-2 text-xs text-gray-700">
                      @foreach($item['tags'] ?? [] as $tag)
                          <?php ($name = $tag['name'] ?? $tag) ?>
                          <a  href="{{ category_filter_url($normalizedTypeFromItem, 'tags', $name) }}"
                              class="inline-block bg-gray-100 px-4 py-3 rounded-sm
                                    hover:bg-gray-200  transition">
                              {{ $name }}
                          </a>
                      @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    @php
        use Illuminate\Support\Facades\Storage;

        // === EPISODES pagination (12 per page, query param: ep_page) ===
        $epPerPage = 12;
        $epPage    = max(1, (int) request('ep_page', 1));

        /** @var \Illuminate\Pagination\LengthAwarePaginator $episodesPaginator */
        $episodesPaginator = \App\Models\Episode::where('media_fk', $item['id'])
            ->orderBy('episode_number')
            ->paginate($epPerPage, ['*'], 'ep_page', $epPage);

        // collection for the current page
        $episodes = $episodesPaginator->getCollection();
        $rows     = $episodes->chunk(4);
    @endphp

    @auth
        @if($episodesPaginator->total() > 0)
            <div class="space-y-6 mt-8 w-[1278px] mx-auto font-medium relative z-0">
                @foreach($rows as $chunk)
                    <div class="grid grid-cols-4 gap-6">
                        @foreach($chunk as $ep)
                            @php $url = Storage::url($ep->file_path); @endphp
                            <div class="flex flex-col items-stretch">
                                <a href="{{ route('episodes.show', ['media' => $item['id'], 'episode' => $ep->episode_number]) }}"
                                   class="relative group rounded-lg overflow-hidden shadow-lg w-full aspect-[16/9] bg-gray-100">
                                    <video class="absolute inset-0 w-full h-full object-cover" muted playsinline preload="metadata">
                                        <source src="{{ $url }}#t=0.1" type="video/mp4" />
                                    </video>
                                    <div class="absolute inset-0 bg-black bg-opacity-20 duration-500 ease-in-out group-hover:bg-opacity-40 z-0 flex items-center justify-center"></div>
                                    <svg class="absolute inset-0 m-auto h-12 w-12 text-white opacity-75 z-10 pointer-events-none" fill="currentColor" viewBox="0 0 84 84">
                                        <circle cx="42" cy="42" r="42" opacity="0.5"/>
                                        <polygon points="33,27 59,42 33,57" fill="#fff"/>
                                    </svg>
                                </a>
                                <p class="text-center text-sm text-gray-600 mt-2">Episode {{ $ep->episode_number }}</p>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>

            {{-- EPISODES pagination bar --}}
            @php
                $epCurrent = $episodesPaginator->currentPage();
                $epLast    = $episodesPaginator->lastPage();
                $epUrl     = fn($p) => request()->fullUrlWithQuery(['ep_page' => $p]);
            @endphp
            <div class="relative z-10 flex items-center justify-center space-x-2 mt-12 episode-bottom {{ $epLast > 1 ? '' : 'hidden' }}">
            <span class="text-gray-600 text-lg font-medium">Episodes</span>

                @if($epCurrent > 1)
                    <a href="{{ $epUrl(1) }}"        class="pagination-arrow mb-1">&laquo;</a>
                    <a href="{{ $epUrl($epCurrent-1) }}" class="pagination-arrow mb-1">&lsaquo;</a>
                @endif

                <div class="flex space-x-2 text-lg">
                    @php
                        $maxVisible = 7;
                        $start = max(1, $epCurrent - intdiv($maxVisible,2));
                        $end   = min($epLast, $start + $maxVisible - 1);
                        if($end - $start + 1 < $maxVisible) $start = max(1, $end - $maxVisible + 1);
                    @endphp
                    @for ($i = $start; $i <= $end; $i++)
                        @if ($i == $epCurrent)
                            <span class="pagination-btn pagination-active">{{ $i }}</span>
                        @else
                            <a href="{{ $epUrl($i) }}" class="pagination-btn non-selected-page-number">{{ $i }}</a>
                        @endif
                    @endfor
                </div>

                @if($epCurrent < $epLast)
                    <a href="{{ $epUrl($epCurrent+1) }}" class="pagination-arrow mb-1">&rsaquo;</a>
                    <a href="{{ $epUrl($epLast) }}"      class="pagination-arrow mb-1">&raquo;</a>
                @endif
            </div>
        @endif
    @endauth

</div>

@php
    // === CHAPTERS pagination (70 per page, query param: ch_page) ===
    $chPerPage = 48;
    $chPage    = max(1, (int) request('ch_page', 1));

    /** @var \Illuminate\Pagination\LengthAwarePaginator $chaptersPaginator */
    $chaptersPaginator = \App\Models\Chapter::with(['pages' => function ($q) {
                            $q->orderBy('page_number');
                        }])
                        ->where('item_id', $item['id'])
                        ->orderBy('chapter_number')
                        ->paginate($chPerPage, ['*'], 'ch_page', $chPage);

    $chapters    = $chaptersPaginator->getCollection(); // current page items
@endphp

@auth
    @if($chaptersPaginator->total() > 0)
        <div class="flex justify-center mb-12 mt-[-100px] font-medium">
            <div id="chaptersGrid" class="w-[1278px] ml-[13px] grid grid-cols-4 gap-4 transition-opacity duration-500 ease-in-out">
                @php
                    $allowedExts = ['jpg','jpeg','png','gif','webp'];
                    $isMangaType = strtoupper($item['type'] ?? '') === 'MANGA';
                @endphp

                @foreach($chapters->chunk(4) as $rowIndex => $row)
                    @php
                        // Manga: reverse each row so it reads R→L; Manwha stays L→R
                        $cells  = $isMangaType ? $row->reverse()->values() : $row->values();
                        $count  = $cells->count();
                        $blanks = max(0, 4 - $count);
                    @endphp

                    {{-- For MANGA only: add blank cells first so short rows align to the RIGHT --}}
                    @if($isMangaType && $blanks > 0)
                        @for($i = 0; $i < $blanks; $i++)
                            <div></div>
                        @endfor
                    @endif

                    @foreach($cells as $chapter)
                        @php
                            $firstPage = $chapter->pages->first();
                            $ext       = strtolower(pathinfo($firstPage->file_path ?? '', PATHINFO_EXTENSION));
                            $isImage   = $firstPage && in_array($ext, $allowedExts, true);
                            $thumb     = $isImage ? Storage::url($firstPage->file_path) : asset('images/no-thumb.jpg');

                            $chapterParam = $chapter->chapter_number !== null && $chapter->chapter_number !== ''
                                ? (string) $chapter->chapter_number
                                : rawurlencode((string) $chapter->chapter_title);
                        @endphp

                        <div onclick="window.location.href='{{ route('chapters.page', ['media' => $chapter->item_id, 'chapter' => $chapterParam, 'page' => 1]) }}'"
                             class="cursor-pointer">
                            <div class="relative w-full rounded-lg overflow-hidden shadow-lg">
                                <img src="{{ $thumb }}" alt="{{ $chapter->chapter_title }}" class="w-full h-auto object-contain">
                            </div>
                            <p class="text-center text-sm text-gray-600 mt-2">
                                {{ $chapter->chapter_title }}
                            </p>
                        </div>
                    @endforeach
                @endforeach
            </div>
        </div>

        {{-- CHAPTERS pagination bar --}}
        @php
            $chCurrent = $chaptersPaginator->currentPage();
            $chLast    = $chaptersPaginator->lastPage();
            $chUrl     = fn($p) => request()->fullUrlWithQuery(['ch_page' => $p]);
        @endphp
        <div class="flex items-center justify-center space-x-2 mt-6 mb-6 {{ $chLast > 1 ? '' : 'hidden' }}">
            <span class="text-gray-600 text-lg font-medium">Chapters</span>

            @if($chCurrent > 1)
                <a href="{{ $chUrl(1) }}"            class="pagination-arrow mb-1">&laquo;</a>
                <a href="{{ $chUrl($chCurrent-1) }}" class="pagination-arrow mb-1">&lsaquo;</a>
            @endif

            <div class="flex space-x-2 text-lg">
                @php
                    $maxVisible = 7;
                    $start = max(1, $chCurrent - intdiv($maxVisible,2));
                    $end   = min($chLast, $start + $maxVisible - 1);
                    if($end - $start + 1 < $maxVisible) $start = max(1, $end - $maxVisible + 1);
                @endphp
                @for ($i = $start; $i <= $end; $i++)
                    @if ($i == $chCurrent)
                        <span class="pagination-btn pagination-active">{{ $i }}</span>
                    @else
                        <a href="{{ $chUrl($i) }}" class="pagination-btn non-selected-page-number">{{ $i }}</a>
                    @endif
                @endfor
            </div>

            @if($chCurrent < $chLast)
                <a href="{{ $chUrl($chCurrent+1) }}" class="pagination-arrow mb-1">&rsaquo;</a>
                <a href="{{ $chUrl($chLast) }}"      class="pagination-arrow mb-1">&raquo;</a>
            @endif
        </div>
    @endif


<div
  id="addToCollectionModal"
  class="fixed inset-0 flex items-start pt-[130px] justify-center bg-black bg-opacity-50 hidden z-50"
>
  <div class="relative bg-white p-4 text-left shadow-2xl
            w-[800px] rounded-lg">
    <div class="flex justify-between items-start pb-4 pt-2 border-b ml-4 mr-4 border-gray-200">
      <h3 class="text-lg font-bold text-gray-800">Add to Collection</h3>
      <button id="closeAddModal" type="button" class="text-gray-400 hover:text-gray-900" aria-label="Close Add Modal">
        <span class="sr-only">Close</span>
        <!-- you can swap this SVG for your .icon-times -->
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
             viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
          <path stroke-linecap="round" stroke-linejoin="round"
                d="M6 18L18 6M6 6l12 12"/>
        </svg>
        </button>
    </div>

    <div id="overlay-content" class="border-b border-gray-200 mr-4 ml-4">
        <form method="POST" action="{{ route('collection.attachMedia') }}" class="space-y-4" id="attachCollectionsForm">
        @csrf
        <input type="hidden" name="item_type"  value="{{ $normalizedTypeFromItem }}">
        <input type="hidden" name="item_id"    value="{{ $item['id']  }}">

        <div id="collectionCheckboxList" class="text-gray-800">
            @foreach($allCollections as $col)
            <label class="flex items-center justify-between w-full space-x-2 px-4 py-[15px] rounded hover:bg-gray-100 transition-colors">
                <div class="flex items-center space-x-2">

                @if($col->is_system)
                    {{-- Favorites checkbox --}}
                    <input
                    type="checkbox"
                    name="add_to_favorites"
                    value="1"
                    class="sr-only peer"
                    {{ isset($isFavorited) && $isFavorited ? 'checked' : '' }}
                    onchange="document.getElementById('attachCollectionsForm').submit()"
                    />
                @else
                    {{-- Regular collections --}}
                    <input
                    type="checkbox"
                    name="collection_ids[]"
                    value="{{ $col->id }}"
                    class="sr-only peer"
                    {{ in_array($col->id, $attachedIds, true) ? 'checked' : '' }}
                    onchange="this.form.submit()"
                    />
                @endif

                <span class="mr-1 inline-block h-[20px] w-[20px] rounded border border-gray-600 bg-white transition peer-checked:bg-flatRed peer-checked:border-red-600 group-hover:bg-gray-100 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-white hidden peer-checked:block" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor" class="size-[14px] text-white">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                </span>

                <span class="font-medium pl-1">
                    {{ $col->name }}
                </span>
                </div>

                <a href="{{ route('collection.show', $col) }}"
                class="pr-4 text-sm text-blue-600 hover:underline font-medium">
                View
                </a>
            </label>
            @endforeach
        </div>
        </form>
    </div>
    <div class="mb-2 px-4 flex justify-center">
        <button
        id="openInlineCreateCollection"
        type="button"
        class="w-full px-6 py-[15px] hover:bg-gray-100 transition-colors rounded"
        >
        <span class="font-medium text-gray-800">Create New Collection</span>
        </button>
    </div>
  </div>
</div>

<div
  id="createCollectionModal"
  class="fixed inset-0 flex items-start pt-[130px] justify-center bg-black bg-opacity-50 hidden z-50"
>
    <div
        class="relative bg-white p-4 text-left shadow-2xl
            w-[800px] h-[255px] rounded-lg space-y-6 overflow-auto"
        role="dialog"
        aria-modal="true"
    >
    <!-- Header -->
    <div class="flex justify-between items-start pb-4 pt-2 border-b border-gray-200">
      <h2 id="overlay-title" class="text-lg font-bold text-gray-800 pl-4">
        Create New Collection
      </h2>
      <button
        id="closeCreateModal"
        type="button"
        class="text-gray-400 hover:text-gray-900 pr-4"
        aria-label="Close Create Modal"
      >
        <span class="sr-only">Close</span>
        <!-- you can swap this SVG for your .icon-times -->
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
             viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
          <path stroke-linecap="round" stroke-linejoin="round"
                d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <!-- Body -->
    <div id="overlay-content" class="space-y-4">
      <form
        id="collectionCreateForm"
        class="space-y-4"
        method="POST"
        action="{{ route('collection.store') }}"
      >
        @csrf

        <!-- Name -->
        <label class="block relative" for="name">
          <span class="block mb-2 label-text text-red-600 font-medium pl-4">Collection Name</span>
            <input
                type="text"
                id="name"
                name="name"
                class="w-[735px] ml-4
                    rounded-md
                    border border-gray-200
                    px-3
                    py-2
                    text-base
                    bg-gray-100
                    focus:outline-none focus:ring-[0.2rem] focus:ring-red-600
                    text-gray-800 font-medium"
                required
            />

          @error('name')
          <span class="text-red-600 text-sm mt-1">{{ $message }}</span>
          @enderror
        </label>

        <!-- Submit -->
        <div class="block">
          <button
            type="submit"
            class="flatGreen transition-200 text-white
                   px-5 py-3 rounded ml-4"
          >
            Create Collection
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
@endauth
<script>
function chapterUpload () {
  return {
    showUpload : false,
    uploading  : false,
    xhr        : null,

    startUpload () {
      this.uploading = true;
      const d  = new FormData(this.$refs.form);
      this.xhr = new XMLHttpRequest();
      this.xhr.open('POST', this.$refs.form.action, true);

      this.xhr.upload.onprogress = ev => {
        if (ev.lengthComputable) {
          const pct = Math.round(ev.loaded / ev.total * 100);
          this.$refs.bar.style.width = pct + '%';
          this.$refs.txt.textContent = pct + '%';
        }
      };

      this.xhr.onload  = () => location.reload();
      this.xhr.onerror = () => this.cancelUpload();
      this.xhr.send(d);
    },

    cancelUpload () {
      if (this.xhr) this.xhr.abort();
      this.uploading            = false;
      this.$refs.bar.style.width = '0%';
      this.$refs.txt.textContent = '0%';
    }
  };
}
</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
const addModal       = document.getElementById('addToCollectionModal');
const createModal    = document.getElementById('createCollectionModal');
const openAddBtn     = document.getElementById('openAddToCollection');
const closeAddBtn    = document.getElementById('closeAddModal');
const openCreateBtn  = document.getElementById('openInlineCreateCollection');
const closeCreateBtn = document.getElementById('closeCreateModal');
const createForm     = document.getElementById('collectionCreateForm');
const listContainer  = document.getElementById('collectionCheckboxList');
const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('video-input');
const fileInfo  = document.getElementById('video-info');
const form        = document.getElementById('uploadForm');
const progressBar = document.getElementById('progressBar');
const progressText= document.getElementById('progressText');

// helpers
const lockBody   = ()=> document.body.classList.add('overflow-hidden');
const unlockBody = ()=> {
  // only unlock if both modals are hidden
  if (addModal.classList.contains('hidden') && createModal.classList.contains('hidden')) {
    document.body.classList.remove('overflow-hidden');
  }
};

const showAdd    = ()=> { addModal.classList.remove('hidden'); lockBody(); };
const hideAdd    = ()=> { addModal.classList.add('hidden'); unlockBody(); };
const showCreate = ()=> { createModal.classList.remove('hidden'); lockBody(); };
const hideCreate = ()=> { createModal.classList.add('hidden'); unlockBody(); };

// open/close Add→Collection
openAddBtn.addEventListener('click', showAdd);
closeAddBtn.addEventListener('click', hideAdd);
addModal.addEventListener('click', e => { if(e.target===addModal) hideAdd(); });
document.addEventListener('keyup', e => { if(e.key==='Escape' && !addModal.classList.contains('hidden')) hideAdd(); });

// from inside Add, open Create
openCreateBtn.addEventListener('click', () => {
hideAdd();
showCreate();
});

// close Create modal
closeCreateBtn.addEventListener('click', hideCreate);
createModal.addEventListener('click', e => { if(e.target===createModal) hideCreate(); });
document.addEventListener('keyup', e => { if(e.key==='Escape' && !createModal.classList.contains('hidden')) hideCreate(); });

// AJAX create‐collection
createForm.addEventListener('submit', async e => {
e.preventDefault();
const token = document.querySelector('meta[name="csrf-token"]').content;
const res   = await fetch(createForm.action, {
    method: 'POST',
    headers: {
    'Accept':       'application/json',
    'X-CSRF-TOKEN': token,
    },
    body: new FormData(createForm),
});

if (!res.ok) {
    const err = await res.json();
    return alert(err.errors?.name?.[0] || 'Create failed');
}

const newCol = await res.json();
listContainer.insertAdjacentHTML('beforeend', `
    <label class="flex items-center justify-between w-full space-x-2 px-4 py-[15px] rounded hover:bg-gray-100 transition-colors">
    <div class="flex items-center space-x-2">
        <input type="checkbox" name="collection_ids[]" value="${newCol.id}" class="sr-only peer" onchange="this.form.submit()" />
        <span class="mr-1 inline-block h-[20px] w-[20px] rounded border border-gray-600 bg-white transition peer-checked:bg-flatRed peer-checked:border-red-600 group-hover:bg-gray-100 flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-white hidden peer-checked:block" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor" class="size-[14px] text-white">
            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
            </svg>
        </span>
        <span class="font-medium pl-1">${newCol.name}</span>
    </div>
    <a href="/collection/${newCol.id}" class="pr-4 text-sm text-blue-600 hover:underline font-medium">View</a>
    </label>
`);

hideCreate();
});
['dragenter','dragover'].forEach(e => {
    dropZone.addEventListener(e, ev => {
    ev.preventDefault();
    dropZone.classList.add('ring-2','ring-red-600');
    });
});
['dragleave','drop'].forEach(e => {
    dropZone.addEventListener(e, ev => {
    dropZone.classList.remove('ring-2','ring-red-600');
    });
});

dropZone.addEventListener('drop', ev => {
    ev.preventDefault();
    if (ev.dataTransfer.files.length) {
    fileInput.files = ev.dataTransfer.files;
    updateFileInfo();
    }
});

fileInput.addEventListener('change', updateFileInfo);

function updateFileInfo() {
    const names = Array.from(fileInput.files).map(f => f.name).join(', ');
    fileInfo.textContent = names || 'MP4, WEBM';
}
  if (!form || !progressBar || !progressText) {
    console.error('Upload form or progress elements not found');
    return;
  }

  form.addEventListener('submit', function(e) {
    e.preventDefault();
    const data = new FormData(form);
    const xhr  = new XMLHttpRequest();

    xhr.open('POST', form.action, true);

    xhr.upload.onprogress = ev => {
      if (ev.lengthComputable) {
        const pct = Math.round(ev.loaded / ev.total * 100);
        progressBar.style.width  = pct + '%';
        progressText.textContent = pct + '%';
      }
    };

    xhr.onload = () => {
    if (xhr.status === 200) {
        alert('Success!');
        location.reload();
    } else {
        let msg = 'Error ' + xhr.status + ':\n';
        try {
        const json = JSON.parse(xhr.responseText);
        msg += json.error || JSON.stringify(json);
        } catch {
        msg += xhr.responseText || xhr.statusText;
        }
        alert(msg);
    }
    };
    xhr.onerror = () => alert('Network error during upload.');

    xhr.send(data);
  });
    Plyr.setup('.plyr', {
      controls: [
        'play-large','play','progress','current-time',
        'mute','volume','fullscreen'
      ]
    });
});
</script>

@endsection
