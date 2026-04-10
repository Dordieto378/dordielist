@extends('layouts.app')

@section('content')
@php
    use App\Models\Chapter;
    use App\Models\Episode;
    use App\Models\Collection;
    use App\Models\CollectionItem;
    use App\Models\Favorite;
    $romajiTitle = $item['title']['romaji'] ?? null;
    $englishTitle = $item['title']['english'] ?? null;
    $title = $item['title']['english']
          ?? $item['title']['romaji']
          ?? $item['title']['native']
          ?? 'No Title';
    $showRomajiTitle = filled($romajiTitle) && (
        blank($englishTitle)
            ? $romajiTitle !== $title
            : $romajiTitle !== $englishTitle
    );

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

    $localEpisodeCount = $isEpisodeBased
        ? Episode::where('media_fk', $item['id'])->count()
        : null;
    $localChapterCount = $isChapterBased
        ? Chapter::where('item_id', $item['id'])->count()
        : null;

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

    $currentProgress = old('progress', $item['userProgress'] ?? null);
    $currentScore = old('user_score', $item['userScore'] ?? null);
    $currentListStatus = old('list_status', $item['listStatus'] ?? 'PLANNING');
    $currentListStartDate = old('list_start_date', $item['listStartDate'] ?? null);
    $currentListEndDate = old('list_end_date', $item['listEndDate'] ?? null);

    $listOptions = [
        'CURRENT' => $isEpisodeBased ? 'Watching' : 'Reading',
        'PLANNING' => 'Planning',
        'COMPLETED' => 'Completed',
        'PAUSED' => 'Paused',
        'DROPPED' => 'Dropped',
        'REPEATING' => $isEpisodeBased ? 'Rewatching' : 'Rereading',
    ];

    if (!array_key_exists($currentListStatus, $listOptions)) {
        $currentListStatus = 'PLANNING';
    }

    $progressFieldLabel = $isEpisodeBased ? 'Episode Progress' : 'Chapter Progress';
    $progressTotal = $isEpisodeBased
        ? ($item['episodes'] ?? (($localEpisodeCount ?? 0) > 0 ? $localEpisodeCount : null))
        : ($item['chapters'] ?? (($localChapterCount ?? 0) > 0 ? $localChapterCount : null));
    $progressHardMax = $isEpisodeBased
        ? (($item['episodes'] ?? null) ?: null)
        : (($item['chapters'] ?? null) ?: null);
    $isAdmin = optional(auth()->user()?->role)->role === 'Admin';
    $contentUploadRoute = $isEpisodeBased
        ? route('episodes.upload', ['media' => $item['id']])
        : route('chapters.upload', ['media' => $item['id']]);
    $contentResetRoute = $isEpisodeBased
        ? route('episodes.reset', ['media' => $item['id']])
        : route('chapters.reset', ['media' => $item['id']]);
    $contentUploadLabel = $isEpisodeBased ? 'Upload Episode(s)' : 'Upload Chapter(s)';
    $contentUploadTitle = $isEpisodeBased ? 'Upload Episodes' : 'Upload Chapters';
    $contentResetLabel = $isEpisodeBased ? 'Reset Episodes' : 'Reset Chapters';
    $contentResetConfirm = $isEpisodeBased
        ? 'Remove all uploaded episodes for this title?'
        : 'Remove all uploaded chapters for this title?';
@endphp

<div class="flex flex-col items-center py-[8.5rem]">
    @if(session('status') && session('status_color') === 'red')
        <div class="app-alert app-alert-error w-[1280px] mb-4 px-5 py-4 text-sm">
            {{ session('status') }}
        </div>
    @endif
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
                    <button id="openEditEntryModal" type="button" class="flex items-center justify-start w-full text-blue-950 py-2 rounded-sm hover:text-[#08875b]">
                        <svg xmlns="http://www.w3.org/2000/svg"
                             class="ml-[1.4rem] h-[1.1rem] w-[1.1rem] mr-[0.5rem] mb-[0.1rem]"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M15.232 5.232l3.536 3.536M4 21h4.586a1 1 0 00.707-.293l10-10a1 1 0 000-1.414L14.414 4.293a1 1 0 00-1.414 0l-10 10A1 1 0 004 14.586V19a2 2 0 002 2z"/>
                        </svg>
                        <span class="ml-1">Edit</span>
                    </button>
                    @if($isAdmin)
                    <button id="openMediaContentUploadModal" type="button" class="flex items-center justify-start w-full text-blue-950 py-2 rounded-sm hover:text-[#08875b]">
                        <svg xmlns="http://www.w3.org/2000/svg"
                             class="ml-[1.4rem] h-[1.1rem] w-[1.1rem] mr-[0.5rem] mb-[0.1rem]"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14" />
                        </svg>
                        <span class="ml-1">{{ $contentUploadLabel }}</span>
                    </button>
                    @endif

                    <form action="{{ route('media.destroy', ['media' => $item['id']]) }}"
                          method="POST"
                          class="w-full"
                          onsubmit="return confirm('Delete this entry? This will remove it locally and from your AniList list.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="flex items-center justify-start w-full text-blue-950 py-2 rounded-sm hover:text-red-600">
                            <svg xmlns="http://www.w3.org/2000/svg"
                                 class="ml-[1.4rem] h-[1.1rem] w-[1.1rem] mr-[0.5rem] mb-[0.1rem]"
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M6 7h12M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2m-7 0v12m4-12v12m5-12-.867 12.142A2 2 0 0114.138 21H9.862a2 2 0 01-1.995-1.858L7 7m10 0H7"/>
                            </svg>
                            <span class="ml-1">Delete</span>
                        </button>
                    </form>
                </div>
                @endauth
            </div>
            {{-- Right Column: Basic Info --}}
            <div class="flex flex-col justify-start ml-8 mt-4 md:mt-2 text-gray-900 font-medium">
                <h1 class="text-2xl font-bold text-red-600 mb-2">{{ $title }}</h1>
                <div class="grid grid-cols-[7rem,1fr] gap-x-3 gap-y-4 text-sm mt-2 mb-2">
                    @if($showRomajiTitle)
                        <div>Romaji</div>
                        <div>{{ $romajiTitle }}</div>
                    @endif

                    <div>Native</div>
                    <div>{{ $item['title']['native'] ?? 'N/A' }}</div>

                @if($isChapterBased)
                    <div>Chapters</div>
                    @php
                        $chapTotal = $item['chapters'] ?? (($localChapterCount ?? 0) > 0 ? $localChapterCount : null);
                        $chapProgress  = $item['userProgress'] ?? null;

                        if ($chapProgress !== null && $chapProgress > 0) {
                            if ($chapTotal !== null && $chapTotal > 0) {
                                $chapDisplay = ($chapProgress < $chapTotal)
                                    ? "{$chapProgress} / {$chapTotal}"
                                    : $chapTotal;
                            } else {
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
                        $epTotal = $item['episodes'] ?? (($localEpisodeCount ?? 0) > 0 ? $localEpisodeCount : null);
                        $epProgress  = $item['userProgress'] ?? null;

                        if ($epProgress !== null && $epProgress > 0) {
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
        $rows     = $episodes->chunk(3);
    @endphp

    @auth
        @if($episodesPaginator->total() > 0)
            <div class="space-y-6 mt-8 w-[1278px] mx-auto font-medium relative z-0">
                @foreach($rows as $chunk)
                    <div class="grid gap-6" style="grid-template-columns: repeat(3, minmax(0, 1fr));">
                        @foreach($chunk as $ep)
                            @php
                                $thumbUrl = !empty($ep->thumbnail_path)
                                    ? Storage::url($ep->thumbnail_path)
                                    : asset('images/no-image.jpg');
                            @endphp
                            <div class="flex flex-col items-stretch">
                                <a href="{{ route('episodes.show', ['media' => $item['id'], 'episode' => $ep->episode_number]) }}"
                                   class="relative rounded-lg overflow-hidden shadow-lg w-full aspect-[16/9] bg-gray-100">
                                    <img
                                        class="absolute inset-0 w-full h-full object-cover"
                                        src="{{ $thumbUrl }}"
                                        alt="Episode {{ $ep->episode_number }} thumbnail"
                                        loading="lazy"
                                    >
                                    <span class="absolute inset-0 z-10 pointer-events-none"
                                          style="background: linear-gradient(to top, rgba(0, 0, 0, 0.76) 0%, rgba(0, 0, 0, 0.46) 9%, rgba(0, 0, 0, 0.22) 18%, rgba(0, 0, 0, 0.08) 28%, rgba(0, 0, 0, 0.02) 36%, rgba(0, 0, 0, 0) 46%);"></span>
                                    <span class="absolute z-20 text-white text-lg font-semibold leading-none"
                                          style="right: 10px; bottom: 8px; top: auto; left: auto; text-shadow: 0 2px 6px rgba(0, 0, 0, 0.98), 0 1px 2px rgba(0, 0, 0, 0.95);">
                                        {{ $ep->episode_number }}
                                    </span>
                                </a>
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
                                $chapterBadge = ($chapter->chapter_number !== null && $chapter->chapter_number !== '')
                                    ? rtrim(rtrim((string) $chapter->chapter_number, '0'), '.')
                                    : ((preg_match('/\d+(?:\.\d+)?/', (string) $chapter->chapter_title, $m) === 1) ? $m[0] : '?');

                                $chapterParam = $chapter->chapter_number !== null && $chapter->chapter_number !== ''
                                    ? (string) $chapter->chapter_number
                                    : rawurlencode((string) $chapter->chapter_title);
                            @endphp

                        <div onclick="window.location.href='{{ route('chapters.page', ['media' => $chapter->item_id, 'chapter' => $chapterParam, 'page' => 1]) }}'"
                             class="cursor-pointer">
                            <div class="chapter-thumb-frame shadow-lg">
                                <img
                                    src="{{ $thumb }}"
                                    alt="{{ $chapter->chapter_title }}"
                                    class="chapter-thumb-img"
                                >
                                <span class="absolute inset-0 z-10 pointer-events-none"
                                      style="background: linear-gradient(to top, rgba(0, 0, 0, 0.76) 0%, rgba(0, 0, 0, 0.46) 9%, rgba(0, 0, 0, 0.22) 18%, rgba(0, 0, 0, 0.08) 28%, rgba(0, 0, 0, 0.02) 36%, rgba(0, 0, 0, 0) 46%);"></span>
                                <span class="absolute z-20 text-white text-lg font-semibold leading-none"
                                      style="right: 10px; bottom: 8px; top: auto; left: auto; text-shadow: 0 2px 6px rgba(0, 0, 0, 0.98), 0 1px 2px rgba(0, 0, 0, 0.95);">
                                    {{ $chapterBadge }}
                                </span>
                            </div>
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
  id="editEntryModal"
  class="fixed inset-0 flex items-start pt-[130px] justify-center bg-black bg-opacity-50 hidden z-50"
>
  <div class="relative bg-white p-4 text-left shadow-2xl w-[800px] rounded-lg">
    <div class="flex justify-between items-start pb-4 pt-2 border-b ml-4 mr-4 border-gray-200">
      <h3 class="text-lg font-bold text-gray-800">Edit Entry</h3>
      <button id="closeEditModal" type="button" class="text-gray-400 hover:text-gray-900" aria-label="Close Edit Modal">
        <span class="sr-only">Close</span>
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
             viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
          <path stroke-linecap="round" stroke-linejoin="round"
                d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <div class="border-b border-gray-200 mr-4 ml-4">
      <form method="POST" action="{{ route('media.entry.update', ['media' => $item['id']]) }}" class="space-y-4 py-4" id="editEntryForm">
        @csrf
        @method('PATCH')

        @if(session('entry_update_error'))
          <div class="app-alert app-alert-error px-4 py-3 text-sm">
            {{ session('entry_update_error') }}
          </div>
        @endif

        <div class="grid grid-cols-2 gap-4">
          <label class="block">
            <span class="block mb-2 text-red-600 font-medium">{{ $progressFieldLabel }}</span>
            <input
              type="number"
              min="0"
              @if($progressHardMax)
              max="{{ $progressHardMax }}"
              @endif
              name="progress"
              value="{{ $currentProgress ?? '' }}"
              class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
              placeholder="0"
            />
          </label>

          <label class="block">
            <span class="block mb-2 text-red-600 font-medium">Score</span>
            <input
              type="number"
              min="0"
              max="100"
              name="user_score"
              value="{{ $currentScore ?? '' }}"
              class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
              placeholder="0 - 100"
            />
          </label>

          <label class="block">
            <span class="block mb-2 text-red-600 font-medium">Start Date</span>
            <input
              type="date"
              name="list_start_date"
              value="{{ $currentListStartDate ?? '' }}"
              class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
            />
          </label>

          <label class="block">
            <span class="block mb-2 text-red-600 font-medium">End Date</span>
            <input
              type="date"
              name="list_end_date"
              value="{{ $currentListEndDate ?? '' }}"
              class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
            />
          </label>

          <label class="block col-span-2">
            <span class="block mb-2 text-red-600 font-medium">List</span>
            <select
              name="list_status"
              class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
            >
              @foreach($listOptions as $value => $label)
                <option value="{{ $value }}" {{ $currentListStatus === $value ? 'selected' : '' }}>
                  {{ $label }}
                </option>
              @endforeach
            </select>
          </label>
        </div>
      </form>
    </div>

    <div class="mb-2 px-4 pt-4 flex items-center justify-end gap-3">
      <div class="flex items-center gap-3">
        <button id="cancelEditModal" type="button" class="px-5 py-3 rounded border border-gray-200 text-gray-700 font-medium hover:bg-gray-100 transition-colors">
          Cancel
        </button>
        <button form="editEntryForm" type="submit" class="flatGreen transition-200 text-white px-5 py-3 rounded">
          Save Changes
        </button>
      </div>
    </div>
  </div>
</div>

@if($isAdmin)
<div
  id="mediaContentUploadModal"
  class="fixed inset-0 flex items-start pt-[130px] justify-center bg-black bg-opacity-50 hidden z-50"
>
  <div class="relative bg-white p-4 text-left shadow-2xl w-[800px] rounded-lg">
    <div class="flex justify-between items-start pb-4 pt-2 border-b ml-4 mr-4 border-gray-200">
      <h3 class="text-lg font-bold text-gray-800">{{ $contentUploadTitle }}</h3>
      <button id="closeMediaContentUploadModal" type="button" class="text-gray-400 hover:text-gray-900" aria-label="Close Upload Modal">
        <span class="sr-only">Close</span>
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
             viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
          <path stroke-linecap="round" stroke-linejoin="round"
                d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <div class="border-b border-gray-200 mr-4 ml-4">
      <form method="POST" action="{{ $contentUploadRoute }}" enctype="multipart/form-data" class="space-y-4 py-4" id="mediaContentUploadForm">
        @csrf
        <input type="hidden" name="replace_existing" id="mediaContentReplaceExisting" value="0">

        <div id="mediaContentUploadError" class="app-alert app-alert-error px-4 py-3 text-sm {{ session('media_content_upload_error') ? '' : 'hidden' }}">
          <span id="mediaContentUploadErrorText">{{ session('media_content_upload_error') }}</span>
        </div>

        <div>
          <span class="block mb-2 text-red-600 font-medium">ZIP File</span>
          <input
            id="mediaContentArchiveInput"
            type="file"
            name="archive"
            accept=".zip"
            class="sr-only"
          />
          <label
            for="mediaContentArchiveInput"
            class="flex w-full cursor-pointer items-center justify-between gap-4 rounded-md border border-gray-200 bg-gray-100 px-3 py-2 text-base text-gray-800 font-medium focus-within:ring-[0.2rem] focus-within:ring-red-600"
          >
            <span
              id="mediaContentArchiveName"
              class="min-w-0 flex-1 truncate text-gray-500"
              data-placeholder="No ZIP selected"
            >
              No ZIP selected
            </span>
            <span class="flatGreen shrink-0 rounded-[0.19rem] px-3 py-2 text-sm font-medium text-white">
              Select ZIP
            </span>
          </label>
        </div>
      </form>
    </div>

    <div class="mb-2 px-4 pt-4 flex items-center justify-between gap-3">
      <form method="POST" action="{{ $contentResetRoute }}" onsubmit="return confirm(@js($contentResetConfirm));">
        @csrf
        @method('DELETE')
        <button type="submit" class="rounded bg-red-600 px-5 py-3 text-white transition-colors hover:bg-red-700">
          {{ $contentResetLabel }}
        </button>
      </form>

      <div class="flex items-center gap-3">
        <button id="cancelMediaContentUploadModal" type="button" class="px-5 py-3 rounded border border-gray-200 text-gray-700 font-medium hover:bg-gray-100 transition-colors">
          Cancel
        </button>
        <button id="submitMediaContentUploadBtn" form="mediaContentUploadForm" type="submit" class="flatGreen transition-200 text-white px-5 py-3 rounded" data-default-label="Upload ZIP" data-replace-label="Replace Existing">
          Upload ZIP
        </button>
      </div>
    </div>
  </div>
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
        <input type="hidden" name="attach_item_type" value="{{ $category }}">
        <input type="hidden" name="attach_item_id" value="{{ $item['id'] }}">

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
          <span class="app-inline-error">{{ $message }}</span>
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
document.addEventListener('DOMContentLoaded', () => {
const addModal       = document.getElementById('addToCollectionModal');
const editModal      = document.getElementById('editEntryModal');
const uploadModal    = document.getElementById('mediaContentUploadModal');
const createModal    = document.getElementById('createCollectionModal');
const openAddBtn     = document.getElementById('openAddToCollection');
const openEditBtn    = document.getElementById('openEditEntryModal');
const openUploadBtn  = document.getElementById('openMediaContentUploadModal');
const closeAddBtn    = document.getElementById('closeAddModal');
const closeEditBtn   = document.getElementById('closeEditModal');
const cancelEditBtn  = document.getElementById('cancelEditModal');
const closeUploadBtn = document.getElementById('closeMediaContentUploadModal');
const cancelUploadBtn = document.getElementById('cancelMediaContentUploadModal');
const openCreateBtn  = document.getElementById('openInlineCreateCollection');
const closeCreateBtn = document.getElementById('closeCreateModal');
const createForm     = document.getElementById('collectionCreateForm');
const listContainer  = document.getElementById('collectionCheckboxList');
const mediaContentUploadForm = document.getElementById('mediaContentUploadForm');
const mediaContentArchiveInput = document.getElementById('mediaContentArchiveInput');
const mediaContentArchiveName = document.getElementById('mediaContentArchiveName');
const mediaContentUploadError = document.getElementById('mediaContentUploadError');
const mediaContentUploadErrorText = document.getElementById('mediaContentUploadErrorText');
const mediaContentReplaceExisting = document.getElementById('mediaContentReplaceExisting');
const submitMediaContentUploadBtn = document.getElementById('submitMediaContentUploadBtn');

// helpers
const shouldOpenEditModal = @json(session('open_edit_entry_modal', false));
const shouldOpenUploadModal = @json(session('open_media_content_upload_modal', false));
const lockBody   = ()=> document.body.classList.add('overflow-hidden');
const unlockBody = ()=> {
  const addHidden = !addModal || addModal.classList.contains('hidden');
  const editHidden = !editModal || editModal.classList.contains('hidden');
  const uploadHidden = !uploadModal || uploadModal.classList.contains('hidden');
  const createHidden = !createModal || createModal.classList.contains('hidden');
  if (addHidden && editHidden && uploadHidden && createHidden) {
    document.body.classList.remove('overflow-hidden');
  }
};

const showAdd    = ()=> { if (!addModal) return; addModal.classList.remove('hidden'); lockBody(); };
const hideAdd    = ()=> { if (!addModal) return; addModal.classList.add('hidden'); unlockBody(); };
const showEdit   = ()=> { if (!editModal) return; editModal.classList.remove('hidden'); lockBody(); };
const hideEdit   = ()=> { if (!editModal) return; editModal.classList.add('hidden'); unlockBody(); };
const showUpload = ()=> { if (!uploadModal) return; uploadModal.classList.remove('hidden'); lockBody(); };
const hideUpload = ()=> { if (!uploadModal) return; uploadModal.classList.add('hidden'); resetMediaContentUploadState(); unlockBody(); };
const showCreate = ()=> { if (!createModal) return; createModal.classList.remove('hidden'); lockBody(); };
const hideCreate = ()=> { if (!createModal) return; createModal.classList.add('hidden'); unlockBody(); };

function setMediaContentUploadBusy(isBusy) {
  if (!submitMediaContentUploadBtn) return;
  submitMediaContentUploadBtn.disabled = isBusy;
  submitMediaContentUploadBtn.classList.toggle('opacity-60', isBusy);
  submitMediaContentUploadBtn.classList.toggle('cursor-not-allowed', isBusy);
}

function setMediaContentReplaceMode(canReplace) {
  if (mediaContentReplaceExisting) {
    mediaContentReplaceExisting.value = canReplace ? '1' : '0';
  }
  if (!submitMediaContentUploadBtn) return;

  submitMediaContentUploadBtn.textContent = canReplace
    ? (submitMediaContentUploadBtn.dataset.replaceLabel || 'Replace Existing')
    : (submitMediaContentUploadBtn.dataset.defaultLabel || 'Upload ZIP');

  submitMediaContentUploadBtn.classList.toggle('flatGreen', !canReplace);
  submitMediaContentUploadBtn.classList.toggle('bg-red-600', canReplace);
  submitMediaContentUploadBtn.classList.toggle('hover:bg-red-700', canReplace);
}

function setMediaContentUploadError(message, canReplace = false) {
  if (mediaContentUploadErrorText) {
    mediaContentUploadErrorText.textContent = message;
  }
  if (mediaContentUploadError) {
    mediaContentUploadError.classList.remove('hidden');
  }
  setMediaContentReplaceMode(canReplace);
}

function clearMediaContentUploadError() {
  if (mediaContentUploadErrorText) {
    mediaContentUploadErrorText.textContent = '';
  }
  if (mediaContentUploadError) {
    mediaContentUploadError.classList.add('hidden');
  }
}

function resetMediaContentUploadState(keepError = false) {
  setMediaContentUploadBusy(false);
  setMediaContentReplaceMode(false);
  if (!keepError) {
    clearMediaContentUploadError();
  }
}

function updateMediaContentArchiveName() {
  if (!mediaContentArchiveInput || !mediaContentArchiveName) return;

  const selectedFile = mediaContentArchiveInput.files?.[0];
  const placeholder = mediaContentArchiveName.dataset.placeholder ?? 'No ZIP selected';

  mediaContentArchiveName.textContent = selectedFile ? selectedFile.name : placeholder;
  mediaContentArchiveName.classList.toggle('text-gray-500', !selectedFile);
  mediaContentArchiveName.classList.toggle('text-gray-800', Boolean(selectedFile));
}

// open/close Add→Collection
if (openAddBtn && addModal) {
  openAddBtn.addEventListener('click', showAdd);
}
if (closeAddBtn && addModal) {
  closeAddBtn.addEventListener('click', hideAdd);
  addModal.addEventListener('click', e => { if(e.target===addModal) hideAdd(); });
  document.addEventListener('keyup', e => { if(e.key==='Escape' && !addModal.classList.contains('hidden')) hideAdd(); });
}

if (openEditBtn && editModal) {
  openEditBtn.addEventListener('click', showEdit);
}
if (closeEditBtn && editModal) {
  closeEditBtn.addEventListener('click', hideEdit);
  editModal.addEventListener('click', e => { if (e.target === editModal) hideEdit(); });
  document.addEventListener('keyup', e => { if (e.key === 'Escape' && !editModal.classList.contains('hidden')) hideEdit(); });
}
if (cancelEditBtn) {
  cancelEditBtn.addEventListener('click', hideEdit);
}
if (shouldOpenEditModal) {
  showEdit();
}

if (openUploadBtn && uploadModal) {
  openUploadBtn.addEventListener('click', () => {
    resetMediaContentUploadState();
    showUpload();
  });
}
if (closeUploadBtn && uploadModal) {
  closeUploadBtn.addEventListener('click', hideUpload);
  uploadModal.addEventListener('click', e => { if (e.target === uploadModal) hideUpload(); });
  document.addEventListener('keyup', e => { if (e.key === 'Escape' && !uploadModal.classList.contains('hidden')) hideUpload(); });
}
if (cancelUploadBtn) {
  cancelUploadBtn.addEventListener('click', hideUpload);
}
mediaContentArchiveInput?.addEventListener('change', () => {
  updateMediaContentArchiveName();
  resetMediaContentUploadState();
});
updateMediaContentArchiveName();
if (shouldOpenUploadModal) {
  resetMediaContentUploadState(true);
  showUpload();
}

if (mediaContentUploadForm) {
  mediaContentUploadForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    if (!mediaContentArchiveInput?.files?.length) {
      setMediaContentUploadError('Upload a ZIP archive.');
      showUpload();
      return;
    }

    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const formData = new FormData(mediaContentUploadForm);
    setMediaContentUploadBusy(true);

    try {
      const res = await fetch(mediaContentUploadForm.action, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...(token ? { 'X-CSRF-TOKEN': token } : {}),
        },
        body: formData,
      });

      if (res.ok) {
        window.location.reload();
        return;
      }

      let payload = {};
      try {
        payload = await res.json();
      } catch (err) {
        payload = {};
      }

      const canReplace = Boolean(payload.can_replace);
      const message = canReplace
        ? `${payload.message || 'This number already exists.'} Click Replace Existing to overwrite it, or Cancel to keep the current one.`
        : (payload.message || 'Upload failed.');

      setMediaContentUploadError(message, canReplace);
      showUpload();
    } catch (err) {
      setMediaContentUploadError('Upload failed.');
      showUpload();
    } finally {
      setMediaContentUploadBusy(false);
    }
  });
}

// from inside Add, open Create
if (openCreateBtn) {
  openCreateBtn.addEventListener('click', () => {
  hideAdd();
  showCreate();
  });
}

// close Create modal
if (closeCreateBtn && createModal) {
  closeCreateBtn.addEventListener('click', hideCreate);
  createModal.addEventListener('click', e => { if(e.target===createModal) hideCreate(); });
  document.addEventListener('keyup', e => { if(e.key==='Escape' && !createModal.classList.contains('hidden')) hideCreate(); });
}

// AJAX create‐collection
if (createForm && listContainer) {
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
        <input type="checkbox" name="collection_ids[]" value="${newCol.id}" class="sr-only peer" onchange="this.form.submit()" checked />
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

 const newCheckbox = listContainer.querySelector(`input[name="collection_ids[]"][value="${newCol.id}"]`);
 if (newCheckbox) {
     newCheckbox.checked = true;
 }

 hideCreate();
 showAdd();
});
}

    Plyr.setup('.plyr', {
      controls: [
        'play-large','play','progress','current-time',
        'mute','volume','fullscreen'
      ]
    });
});
</script>

@endsection
