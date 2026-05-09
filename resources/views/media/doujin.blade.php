@extends('layouts.app')

@section('content')
    @php
        use App\Models\Chapter;
        use App\Models\Collection;
        use App\Models\CollectionItem;
        use App\Models\Favorite;
        use Illuminate\Support\Facades\Storage;

        $title = $media->title_english
              ?? $media->title_romaji
              ?? $media->title_native
              ?? $media->slug
              ?? 'No Title';

        $type = 'DOUJIN';

        $firstChapter = Chapter::where('item_type', 'doujin')
                               ->where('media_fk', $media->id)
                               ->orderBy('chapter_number')
                               ->first();

        $releaseDate = $media->released ?? 'N/A';
        $averageScore = $media->average ? $media->average.'%' : 'N/A';
        $myScore = 'N/A';

        $status = $media->status ?? 'N/A';

        $normalizedTypeFromItem = 'doujins';

        $numericId = (int) $media->id;

        $isFavorited = Favorite::where('favoritable_type', $normalizedTypeFromItem)
                               ->where('favoritable_id',   $numericId)
                               ->exists();

        $attachedIds = CollectionItem::where('item_type', $normalizedTypeFromItem)
                                     ->where('item_id',   $numericId)
                                     ->pluck('collection_id')
                                     ->toArray();

        $allCollections = Collection::orderBy('name')->get();
        $authors = $media->doujinAuthors->pluck('name')->filter()->unique()->values();
        $tags = $media->doujinTags->pluck('name')->filter()->unique()->values();
        $authorOptions = collect($allAuthors ?? [])
            ->push($authors->first())
            ->filter()
            ->unique()
            ->values();
        $submittedTags = old('tags');
        $currentTagNames = $submittedTags !== null
            ? collect(explode(',', (string) $submittedTags))->map(fn ($tag) => trim($tag))->filter()->unique()->values()
            : $tags;
        $currentNewTags = old('new_tags', '');
        $tagOptions = collect($allTags ?? [])
            ->merge($tags)
            ->merge($currentTagNames)
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $currentTitleEnglish = old('title_english', $media->title_english);
        $currentTitleRomaji = old('title_romaji', $media->title_romaji);
        $currentTitleNative = old('title_native', $media->title_native);
        $currentAuthor = old('author', $authors->first());
        $currentAuthorRecord = $media->doujinAuthors->firstWhere('name', $currentAuthor)
            ?: $media->doujinAuthors->first();
        $currentTwitterUrl = old('author_twitter_url', $currentAuthorRecord?->twitter_url);
        $currentPatreonUrl = old('author_patreon_url', $currentAuthorRecord?->patreon_url);
        $currentFanboxUrl = old('author_fanbox_url', $currentAuthorRecord?->fanbox_url);
        $currentPixivUrl = old('author_pixiv_url', $currentAuthorRecord?->pixiv_url);
        $currentDoujinSource = old('doujin_source', $media->doujin_source);
        $doujinLinks = collect([
            'Twitter' => $currentAuthorRecord?->twitter_url,
            'Patreon' => $currentAuthorRecord?->patreon_url,
            'Fanbox' => $currentAuthorRecord?->fanbox_url,
            'Pixiv' => $currentAuthorRecord?->pixiv_url,
        ])->filter();
        $isAdmin = optional(auth()->user()?->role)->role === 'Admin';
        $contentUploadRoute = route('chapters.upload', ['media' => $media->id]);
        $contentUploadChunkRoute = route('chapters.upload.chunk', ['media' => $media->id]);
        $contentUploadCompleteRoute = route('chapters.upload.complete', ['media' => $media->id]);
        $contentResetRoute = route('chapters.reset', ['media' => $media->id]);
        $contentUploadTitle = 'Upload Chapters';
        $contentResetLabel = 'Reset Chapters';
        $contentResetConfirm = 'Remove all uploaded chapters for this doujin?';
        $isViewer = optional(auth()->user()?->role)->role === 'Viewer';

    @endphp

    <div class="flex flex-col items-center py-[8.5rem]">
        <div class="w-[1280px] h-auto bg-white shadow-sm rounded-md p-6 ml-[0.5rem]">
            <div class="flex flex-col md:flex-row">
                {{-- Left Column --}}
                <div class="flex flex-col items-center">
                    <div class="thumb-wrapper thumb-portrait relative w-[325px] h-[450px] overflow-hidden rounded">
                        <img
                            src="{{ $coverUrl ?? asset('images/no-image.jpg') }}"
                            alt="Cover Image"
                            class="thumb-img w-full h-full">
                    </div>
                    @auth
                        @unless($isViewer)
                        <div class="mt-4 flex flex-col space-y-3 w-[325px] font-bold">
                            @if($firstChapter)
                                <a href="{{ route('chapters.page', [
                'media'   => $media->id,
                'chapter' => $firstChapter->chapter_number ?? 1,
                'page'    => 1
          ]) }}"
                                   class="flex items-center justify-start w-full flatGreen text-white
              py-2 rounded-sm shadow-sm h-[50px] transition-200">
                                    <svg class="ml-6 mb-[0.1rem]" width="15" height="15"
                                         viewBox="0 0 460.114 460.114" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M393.538 203.629L102.557 5.543c-9.793-6.666-22.468-7.372-32.94-1.832
                      -10.472 5.538-17.022 16.413-17.022 28.26v396.173c0 11.846 6.55
                      22.721 17.022 28.26 10.471 5.539 23.147 4.834 32.94-1.832l290.981-198.087
                      c8.746-5.954 13.98-15.848 13.98-26.428 0-10.58-5.234-20.475-13.981-26.428z"/>
                                    </svg>
                                    <span class="ml-3">Start Reading</span>
                                </a>
                            @endif

                            {{-- Favorites toggle --}}
                            <form action="{{ route('favorites.toggle') }}" method="POST" class="mt-2 w-full">
                                @csrf
                                <input type="hidden" name="favoritable_type" value="{{ $normalizedTypeFromItem }}">
                                <input type="hidden" name="favoritable_id"   value="{{ $media->id }}">
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

                            {{-- Add to collection --}}
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
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                  d="M12 5v14m-7-7h14" />
                                        </svg>
                                        <span class="ml-1">Upload Chapter(s)</span>
                                    </button>
                                @endif

                                <form action="{{ route('doujin.destroy', ['media' => $media->id]) }}"
                                      method="POST"
                                      class="w-full"
                                      onsubmit="return confirm('Delete this doujin? This will remove its chapters and pages too.');">
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
                        @endunless
                    @endauth
                </div>

                {{-- Right Column --}}
                <div class="flex flex-col justify-start ml-8 mt-4 md:mt-2 text-gray-900 font-medium">
                    <h1 class="text-2xl font-bold text-red-600 mb-2">{{ $title }}</h1>
                    <div class="grid grid-cols-[7rem,1fr] gap-x-3 gap-y-4 text-sm mt-2 mb-2">
                        <div>Romaji</div>
                        <div>{{ $media->title_romaji ?? 'N/A' }}</div>

                        <div>Native</div>
                        <div>{{ $media->title_native ?? 'N/A' }}</div>

                        <div>Author</div>
                        <div>
                            @if($authors->isEmpty())
                                N/A
                            @else
                                @foreach($authors as $name)
                                    <a href="{{ category_filter_url('doujins', 'author', $name) }}"
                                       class="text-blue-600 hover:underline cursor-pointer">
                                        {{ $name }}
                                    </a>@if(!$loop->last), @endif
                                @endforeach
                            @endif
                        </div>

                        <div>Links</div>
                        <div>
                            @if($doujinLinks->isEmpty())
                                N/A
                            @else
                                @foreach($doujinLinks as $label => $url)
                                    <a href="{{ $url }}"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       class="text-blue-600 hover:underline cursor-pointer">
                                        {{ $label }}
                                    </a>@if(!$loop->last), @endif
                                @endforeach
                            @endif
                        </div>

                    </div>
                    <div class="mb-2 mt-2">
                        <div class="flex flex-wrap gap-2 text-xs text-gray-700">
                            @foreach($tags as $name)
                                <a href="{{ category_filter_url('doujins', 'tags', $name) }}"
                                   class="inline-block bg-gray-100 px-4 py-3 rounded-sm hover:bg-gray-200 transition">
                                    {{ $name }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- === CHAPTERS PAGINATION === --}}
        @php

            $chPerPage = 50;
            $chPage    = max(1, (int) request('ch_page', 1));

            /** @var \Illuminate\Pagination\LengthAwarePaginator $chaptersPaginator */
            $chaptersPaginator = \App\Models\Chapter::with(['pages' => fn($q) => $q->orderBy('page_number')])
                ->where('item_type', 'doujin')
                ->where('media_fk', $media->id)
                ->orderBy('chapter_number')
                ->paginate($chPerPage, ['*'], 'ch_page', $chPage);

            $chapters = $chaptersPaginator->getCollection(); // current page items
        @endphp

        @auth
            @if($chaptersPaginator->total() > 0)
                <div class="flex justify-center mb-12 mt-8 font-medium">
                    <div id="chaptersGrid" class="w-[1278px] ml-[13px] grid grid-cols-4 gap-4 transition-opacity duration-500 ease-in-out">
                        @php
                            $allowedExts = ['jpg','jpeg','png','gif','webp'];
                        @endphp

                        @foreach($chapters->chunk(4) as $rowIndex => $row)
                            @php
                                // RTL: reverse each row so it reads right→left
                                $cells  = $row->reverse()->values();
                                $count  = $cells->count();
                                $blanks = max(0, 4 - $count);
                            @endphp

                            {{-- Add blank cells first so short rows align to the RIGHT --}}
                            @if($blanks > 0)
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

                                    // Prefer numeric chapter param; fall back to title if needed
                                    $chapterParam = $chapter->chapter_number !== null && $chapter->chapter_number !== ''
                                        ? (string) $chapter->chapter_number
                                        : rawurlencode((string) $chapter->chapter_title);
                                @endphp

                                <div
                                    onclick="window.location.href='{{ route('chapters.page', [
              'media'   => $chapter->media_fk,
              'chapter' => $chapterParam,
              'page'    => 1
            ]) }}'"
                                    class="cursor-pointer"
                                >
                                    <div class="chapter-thumb-frame shadow-lg">
                                        <img src="{{ $thumb }}" alt="{{ $chapter->chapter_title }}" class="chapter-thumb-img">
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
        @endauth
    </div>

    @auth
        <div
          id="editEntryModal"
          class="fixed inset-0 flex items-start pt-[130px] justify-center bg-black bg-opacity-50 hidden z-50"
        >
            <div class="relative bg-white p-4 text-left shadow-2xl w-[800px] max-h-[calc(100vh-170px)] overflow-visible rounded-lg">
                <div class="flex justify-between items-start pb-4 pt-2 border-b ml-4 mr-4 border-gray-200">
                    <h3 class="text-lg font-bold text-gray-800">Edit Doujin</h3>
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
                    <form method="POST" action="{{ route('doujin.entry.update', ['media' => $media->id]) }}" class="space-y-4 py-4" id="editEntryForm">
                        @csrf
                        @method('PATCH')

                        @if(session('doujin_update_error'))
                            <div class="app-alert app-alert-error px-4 py-3 text-sm">
                                {{ session('doujin_update_error') }}
                            </div>
                        @endif

                        <div class="grid grid-cols-2 gap-4">
                            <label class="block">
                                <span class="block mb-2 text-red-600 font-medium">English Title</span>
                                <input
                                  type="text"
                                  name="title_english"
                                  value="{{ $currentTitleEnglish ?? '' }}"
                                  class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                                />
                            </label>

                            <label class="block">
                                <span class="block mb-2 text-red-600 font-medium">Romaji Title</span>
                                <input
                                  type="text"
                                  name="title_romaji"
                                  value="{{ $currentTitleRomaji ?? '' }}"
                                  class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                                />
                            </label>

                            <label class="block">
                                <span class="block mb-2 text-red-600 font-medium">Native Title</span>
                                <input
                                  type="text"
                                  name="title_native"
                                  value="{{ $currentTitleNative ?? '' }}"
                                  class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                                />
                            </label>

                            <label class="block">
                                <span class="block mb-2 text-red-600 font-medium">Author</span>
                                <select
                                  id="doujinAuthorSelect"
                                  name="author"
                                  class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                                >
                                    <option value="">No author</option>
                                    @foreach($authorOptions as $authorName)
                                        <option value="{{ $authorName }}" {{ ($currentAuthor ?? '') === $authorName ? 'selected' : '' }}>
                                            {{ $authorName }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>

                            <div class="block space-y-4 self-start">
                                <label class="block">
                                    <span class="block mb-2 text-red-600 font-medium">New Tags</span>
                                    <input
                                      type="text"
                                      name="new_tags"
                                      value="{{ $currentNewTags }}"
                                      class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                                    />
                                </label>

                                <div class="block relative">
                                    <span class="block mb-2 text-red-600 font-medium">Tags</span>
                                    <input
                                      type="hidden"
                                      name="tags"
                                      id="doujinTagsInput"
                                      value="{{ $currentTagNames->implode(',') }}"
                                    />
                                    <button id="doujinTagDropdownButton" type="button"
                                            class="w-full min-h-[2.5rem] px-3 py-2 border rounded-sm bg-white text-left flex flex-wrap items-center gap-2">
                                        <div id="doujinSelectedTags" class="flex flex-wrap gap-2 flex-1">
                                            @if($currentTagNames->isEmpty())
                                                <span class="text-gray-900 font-medium text-sm">Select Tags</span>
                                            @else
                                                @foreach($currentTagNames as $tag)
                                                    <span class="px-3 py-2 rounded-[0.2rem] bg-gray-200 text-gray-900 text-sm flex items-center transition-all duration-200 ease-in-out">
                                                        {{ $tag }}
                                                        <span role="button" tabindex="0"
                                                              data-remove-doujin-tag="{{ $tag }}"
                                                              class="ml-2 cursor-pointer text-gray-500 hover:text-gray-800 select-none">
                                                            &times;
                                                        </span>
                                                    </span>
                                                @endforeach
                                            @endif
                                        </div>
                                        <svg class="pointer-events-none h-3 w-3 text-gray-400" xmlns="http://www.w3.org/2000/svg"
                                             fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                    </button>
                                    <div id="doujinTagDropdownMenu"
                                         class="absolute left-0 w-full bg-white border rounded-sm shadow-lg hidden z-50 max-h-[400px] overflow-y-auto">
                                        <div class="p-2 sticky top-0 bg-white border-b border-gray-100">
                                            <input
                                              id="doujinTagSearch"
                                              type="search"
                                              placeholder="Search tags"
                                              class="w-full rounded-sm border border-gray-200 px-3 py-2 text-sm bg-gray-100 focus:outline-none focus:ring-2 focus:ring-red-600 text-gray-800 font-medium"
                                            />
                                        </div>
                                        <ul id="doujinTagOptions">
                                            @foreach($tagOptions as $tagName)
                                                <li class="px-1.5 py-[1px] cursor-pointer text-gray-900 font-medium text-sm transition-all duration-200 ease-in-out bg-white"
                                                    data-doujin-tag-name="{{ $tagName }}">
                                                    <span class="block w-full h-full px-3 py-2 rounded-[0.2rem] transition-all duration-200 ease-in-out hover:bg-red-600 hover:text-white hover:font-semibold">
                                                        {{ $tagName }}
                                                    </span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="block space-y-4">
                                <label class="block">
                                    <span class="block mb-2 text-red-600 font-medium">Twitter</span>
                                    <input
                                      type="text"
                                      id="authorTwitterUrl"
                                      name="author_twitter_url"
                                      value="{{ $currentTwitterUrl ?? '' }}"
                                      class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                                    />
                                </label>

                                <label class="block">
                                    <span class="block mb-2 text-red-600 font-medium">Patreon</span>
                                    <input
                                      type="text"
                                      id="authorPatreonUrl"
                                      name="author_patreon_url"
                                      value="{{ $currentPatreonUrl ?? '' }}"
                                      class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                                    />
                                </label>

                                <label class="block">
                                    <span class="block mb-2 text-red-600 font-medium">Fanbox</span>
                                    <input
                                      type="text"
                                      id="authorFanboxUrl"
                                      name="author_fanbox_url"
                                      value="{{ $currentFanboxUrl ?? '' }}"
                                      class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                                    />
                                </label>

                                <label class="block">
                                    <span class="block mb-2 text-red-600 font-medium">Pixiv</span>
                                    <input
                                      type="text"
                                      id="authorPixivUrl"
                                      name="author_pixiv_url"
                                      value="{{ $currentPixivUrl ?? '' }}"
                                      class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                                    />
                                </label>

                                <label class="block">
                                    <span class="block mb-2 text-red-600 font-medium">Source</span>
                                    <select
                                      name="doujin_source"
                                      class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                                    >
                                        <option value="" {{ empty($currentDoujinSource) ? 'selected' : '' }}>None</option>
                                        <option value="official" {{ $currentDoujinSource === 'official' ? 'selected' : '' }}>Official</option>
                                        <option value="unofficial" {{ $currentDoujinSource === 'unofficial' ? 'selected' : '' }}>Unofficial</option>
                                    </select>
                                </label>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="mb-2 px-4 pt-4 flex items-center justify-end gap-3">
                    <button id="cancelEditModal" type="button" class="px-5 py-3 rounded border border-gray-200 text-gray-700 font-medium hover:bg-gray-100 transition-colors">
                        Cancel
                    </button>
                    <button form="editEntryForm" type="submit" class="flatGreen transition-200 text-white px-5 py-3 rounded">
                        Save Changes
                    </button>
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
            <div class="relative bg-white p-4 text-left shadow-2xl w-[800px] rounded-lg">
                <div class="flex justify-between items-start pb-4 pt-2 border-b ml-4 mr-4 border-gray-200">
                    <h3 class="text-lg font-bold text-gray-800">Add to Collection</h3>
                    <button id="closeAddModal" type="button" class="text-gray-400 hover:text-gray-900" aria-label="Close Add Modal">
                        <span class="sr-only">Close</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
                             viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="border-b border-gray-200 mr-4 ml-4">
                    <form method="POST" action="{{ route('collection.attachMedia') }}" class="space-y-4" id="attachCollectionsForm">
                        @csrf
                        <input type="hidden" name="item_type" value="{{ $normalizedTypeFromItem }}">
                        <input type="hidden" name="item_id" value="{{ $media->id }}">

                        <div id="collectionCheckboxList" class="text-gray-800">
                            @foreach($allCollections as $col)
                                <label class="flex items-center justify-between w-full space-x-2 px-4 py-[15px] rounded hover:bg-gray-100 transition-colors">
                                    <div class="flex items-center space-x-2">
                                        @if($col->is_system)
                                            <input
                                              type="checkbox"
                                              name="add_to_favorites"
                                              value="1"
                                              class="sr-only peer"
                                              {{ isset($isFavorited) && $isFavorited ? 'checked' : '' }}
                                              onchange="document.getElementById('attachCollectionsForm').submit()"
                                            />
                                        @else
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

                                        <span class="font-medium pl-1">{{ $col->name }}</span>
                                    </div>

                                    <a href="{{ route('collection.show', $col) }}" class="pr-4 text-sm text-blue-600 hover:underline font-medium">
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
                class="relative bg-white p-4 text-left shadow-2xl w-[800px] h-[255px] rounded-lg space-y-6 overflow-auto"
                role="dialog"
                aria-modal="true"
            >
                <div class="flex justify-between items-start pb-4 pt-2 border-b border-gray-200">
                    <h2 class="text-lg font-bold text-gray-800 pl-4">Create New Collection</h2>
                    <button
                      id="closeCreateModal"
                      type="button"
                      class="text-gray-400 hover:text-gray-900 pr-4"
                      aria-label="Close Create Modal"
                    >
                        <span class="sr-only">Close</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
                             viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="space-y-4">
                    <form id="collectionCreateForm" class="space-y-4" method="POST" action="{{ route('collection.store') }}">
                        @csrf
                        <input type="hidden" name="attach_item_type" value="doujins">
                        <input type="hidden" name="attach_item_id" value="{{ $media->id }}">
                        <label class="block relative" for="name">
                            <span class="block mb-2 label-text text-red-600 font-medium pl-4">Collection Name</span>
                            <input
                              type="text"
                              id="name"
                              name="name"
                              class="w-[735px] ml-4 rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                              required
                            />

                            @error('name')
                                <span class="app-inline-error">{{ $message }}</span>
                            @enderror
                        </label>

                        <div class="block">
                            <button type="submit" class="flatGreen transition-200 text-white px-5 py-3 rounded ml-4">
                                Create Collection
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const editModal      = document.getElementById('editEntryModal');
                const addModal       = document.getElementById('addToCollectionModal');
                const createModal    = document.getElementById('createCollectionModal');
                const uploadModal    = document.getElementById('mediaContentUploadModal');
                const openEditBtn    = document.getElementById('openEditEntryModal');
                const openAddBtn     = document.getElementById('openAddToCollection');
                const openUploadBtn  = document.getElementById('openMediaContentUploadModal');
                const closeEditBtn   = document.getElementById('closeEditModal');
                const closeAddBtn    = document.getElementById('closeAddModal');
                const closeUploadBtn = document.getElementById('closeMediaContentUploadModal');
                const cancelEditBtn  = document.getElementById('cancelEditModal');
                const cancelUploadBtn = document.getElementById('cancelMediaContentUploadModal');
                const openCreateBtn  = document.getElementById('openInlineCreateCollection');
                const closeCreateBtn = document.getElementById('closeCreateModal');
                const editForm       = document.getElementById('editEntryForm');
                const createForm     = document.getElementById('collectionCreateForm');
                const listContainer  = document.getElementById('collectionCheckboxList');
                const mediaContentUploadForm = document.getElementById('mediaContentUploadForm');
                const archiveInput   = document.getElementById('mediaContentArchiveInput');
                const archiveName    = document.getElementById('mediaContentArchiveName');
                const uploadError    = document.getElementById('mediaContentUploadError');
                const uploadErrorText = document.getElementById('mediaContentUploadErrorText');
                const replaceExistingInput = document.getElementById('mediaContentReplaceExisting');
                const submitUploadBtn = document.getElementById('submitMediaContentUploadBtn');
                const uploadChunkRoute = @json($contentUploadChunkRoute);
                const uploadCompleteRoute = @json($contentUploadCompleteRoute);
                const uploadChunkSizeBytes = 8 * 1024 * 1024;
                let currentUploadSession = null;
                const doujinTagDropdownButton = document.getElementById('doujinTagDropdownButton');
                const doujinTagDropdownMenu = document.getElementById('doujinTagDropdownMenu');
                const doujinSelectedTags = document.getElementById('doujinSelectedTags');
                const doujinTagsInput = document.getElementById('doujinTagsInput');
                const doujinTagSearch = document.getElementById('doujinTagSearch');
                const doujinTagOptions = document.getElementById('doujinTagOptions');
                let selectedDoujinTags = @json($currentTagNames->values()->all());
                const doujinAuthorLinks = @json($allAuthorLinks ?? []);
                const doujinAuthorSelect = document.getElementById('doujinAuthorSelect');
                const authorTwitterUrl = document.getElementById('authorTwitterUrl');
                const authorPatreonUrl = document.getElementById('authorPatreonUrl');
                const authorFanboxUrl = document.getElementById('authorFanboxUrl');
                const authorPixivUrl = document.getElementById('authorPixivUrl');
                const shouldOpenEditModal = @json(session('open_edit_doujin_modal', false));
                const shouldOpenUploadModal = @json(session('open_media_content_upload_modal', false));

                const lockBody = () => document.body.classList.add('overflow-hidden');
                const unlockBody = () => {
                    const editHidden = !editModal || editModal.classList.contains('hidden');
                    const addHidden = !addModal || addModal.classList.contains('hidden');
                    const createHidden = !createModal || createModal.classList.contains('hidden');
                    const uploadHidden = !uploadModal || uploadModal.classList.contains('hidden');
                    if (editHidden && addHidden && createHidden && uploadHidden) {
                        document.body.classList.remove('overflow-hidden');
                    }
                };

                const showEdit = () => {
                    if (!editModal) return;
                    editModal.classList.remove('hidden');
                    lockBody();
                };
                const hideEdit = () => {
                    if (!editModal) return;
                    editModal.classList.add('hidden');
                    unlockBody();
                };
                const showAdd = () => {
                    if (!addModal) return;
                    addModal.classList.remove('hidden');
                    lockBody();
                };
                const hideAdd = () => {
                    if (!addModal) return;
                    addModal.classList.add('hidden');
                    unlockBody();
                };
                const showCreate = () => {
                    if (!createModal) return;
                    createModal.classList.remove('hidden');
                    lockBody();
                };
                const hideCreate = () => {
                    if (!createModal) return;
                    createModal.classList.add('hidden');
                    unlockBody();
                };
                const showUpload = () => {
                    if (!uploadModal) return;
                    uploadModal.classList.remove('hidden');
                    lockBody();
                };
                const hideUpload = () => {
                    if (!uploadModal) return;
                    uploadModal.classList.add('hidden');
                    resetUploadState();
                    unlockBody();
                };
                const fillAuthorLinkFields = (authorName) => {
                    const links = doujinAuthorLinks[authorName] || {};
                    if (authorTwitterUrl) authorTwitterUrl.value = links.twitter || '';
                    if (authorPatreonUrl) authorPatreonUrl.value = links.patreon || '';
                    if (authorFanboxUrl) authorFanboxUrl.value = links.fanbox || '';
                    if (authorPixivUrl) authorPixivUrl.value = links.pixiv || '';
                };
                doujinAuthorSelect?.addEventListener('change', () => {
                    fillAuthorLinkFields(doujinAuthorSelect.value);
                });
                const syncArchiveName = () => {
                    if (!archiveInput || !archiveName) return;
                    const selectedFile = archiveInput.files?.[0];
                    const placeholder = archiveName.dataset.placeholder ?? 'No ZIP selected';
                    archiveName.textContent = selectedFile ? selectedFile.name : placeholder;
                    archiveName.classList.toggle('text-gray-500', !selectedFile);
                    archiveName.classList.toggle('text-gray-800', Boolean(selectedFile));
                };
                const setUploadBusy = (isBusy, label = null) => {
                    if (!submitUploadBtn) return;
                    submitUploadBtn.disabled = isBusy;
                    submitUploadBtn.classList.toggle('opacity-60', isBusy);
                    submitUploadBtn.classList.toggle('cursor-not-allowed', isBusy);
                    if (isBusy && label) {
                        submitUploadBtn.textContent = label;
                    } else if (!isBusy) {
                        setReplaceMode(replaceExistingInput?.value === '1');
                    }
                };
                const setReplaceMode = (canReplace) => {
                    if (replaceExistingInput) {
                        replaceExistingInput.value = canReplace ? '1' : '0';
                    }
                    if (!submitUploadBtn) return;

                    submitUploadBtn.textContent = canReplace
                        ? (submitUploadBtn.dataset.replaceLabel || 'Replace Existing')
                        : (submitUploadBtn.dataset.defaultLabel || 'Upload ZIP');

                    submitUploadBtn.classList.toggle('flatGreen', !canReplace);
                    submitUploadBtn.classList.toggle('bg-red-600', canReplace);
                    submitUploadBtn.classList.toggle('hover:bg-red-700', canReplace);
                };
                const syncDoujinTagInput = () => {
                    if (doujinTagsInput) {
                        doujinTagsInput.value = selectedDoujinTags.join(',');
                    }
                };
                const renderDoujinTags = () => {
                    if (!doujinSelectedTags) return;
                    doujinSelectedTags.innerHTML = '';
                    if (selectedDoujinTags.length === 0) {
                        const placeholder = document.createElement('span');
                        placeholder.className = 'text-gray-900 font-medium text-sm';
                        placeholder.textContent = 'Select Tags';
                        doujinSelectedTags.appendChild(placeholder);
                        syncDoujinTagInput();
                        return;
                    }

                    selectedDoujinTags.forEach((name) => {
                        const tagElement = document.createElement('span');
                        tagElement.className = 'px-3 py-2 rounded-[0.2rem] bg-gray-200 text-gray-900 text-sm flex items-center transition-all duration-200 ease-in-out';

                        const label = document.createElement('span');
                        label.textContent = name;

                        const removeButton = document.createElement('span');
                        removeButton.setAttribute('role', 'button');
                        removeButton.tabIndex = 0;
                        removeButton.className = 'ml-2 cursor-pointer text-gray-500 hover:text-gray-800 select-none';
                        removeButton.innerHTML = '&times;';
                        removeButton.addEventListener('click', (event) => {
                            event.preventDefault();
                            event.stopPropagation();
                            selectedDoujinTags = selectedDoujinTags.filter((tag) => tag !== name);
                            renderDoujinTags();
                        });

                        tagElement.appendChild(label);
                        tagElement.appendChild(removeButton);
                        doujinSelectedTags.appendChild(tagElement);
                    });

                    syncDoujinTagInput();
                };
                const filterDoujinTags = () => {
                    if (!doujinTagOptions || !doujinTagSearch) return;
                    const query = doujinTagSearch.value.trim().toLowerCase();
                    doujinTagOptions.querySelectorAll('[data-doujin-tag-name]').forEach((item) => {
                        const name = (item.dataset.doujinTagName || '').toLowerCase();
                        item.classList.toggle('hidden', query !== '' && !name.includes(query));
                    });
                };
                const setUploadError = (message, canReplace = false) => {
                    if (uploadErrorText) {
                        uploadErrorText.textContent = message;
                    }
                    if (uploadError) {
                        uploadError.classList.remove('hidden');
                    }
                    setReplaceMode(canReplace);
                };
                const clearUploadError = () => {
                    if (uploadErrorText) {
                        uploadErrorText.textContent = '';
                    }
                    if (uploadError) {
                        uploadError.classList.add('hidden');
                    }
                };
                const resetUploadState = (keepError = false) => {
                    setUploadBusy(false);
                    setReplaceMode(false);
                    currentUploadSession = null;
                    if (!keepError) {
                        clearUploadError();
                    }
                };
                const createUploadId = () => {
                    const fallback = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
                    const value = window.crypto?.randomUUID ? window.crypto.randomUUID() : fallback;
                    return value.replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 80);
                };
                const uploadFileKey = (file) => [file.name, file.size, file.lastModified].join(':');
                const parseUploadResponse = async (response) => {
                    let payload = {};
                    try {
                        payload = await response.json();
                    } catch (err) {
                        payload = {};
                    }

                    if (!response.ok) {
                        throw {
                            message: payload.message || 'Upload failed.',
                            canReplace: Boolean(payload.can_replace),
                        };
                    }

                    return payload;
                };
                const uploadChunks = async (file, session, token) => {
                    for (let chunkIndex = 0; chunkIndex < session.totalChunks; chunkIndex++) {
                        const start = chunkIndex * uploadChunkSizeBytes;
                        const end = Math.min(file.size, start + uploadChunkSizeBytes);
                        const chunk = file.slice(start, end);
                        const formData = new FormData();
                        formData.append('upload_id', session.uploadId);
                        formData.append('chunk_index', String(chunkIndex));
                        formData.append('total_chunks', String(session.totalChunks));
                        formData.append('archive_chunk', chunk, `${file.name}.part${chunkIndex}`);

                        const res = await fetch(uploadChunkRoute, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                            },
                            body: formData,
                        });

                        await parseUploadResponse(res);

                        const percent = Math.max(1, Math.min(99, Math.round(((chunkIndex + 1) / session.totalChunks) * 100)));
                        setUploadBusy(true, `Uploading ${percent}%`);
                    }
                };
                const completeUpload = async (session, file, token) => {
                    const formData = new FormData();
                    formData.append('upload_id', session.uploadId);
                    formData.append('total_chunks', String(session.totalChunks));
                    formData.append('original_name', file.name);
                    formData.append('replace_existing', replaceExistingInput?.value === '1' ? '1' : '0');

                    const res = await fetch(uploadCompleteRoute, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                        },
                        body: formData,
                    });

                    return parseUploadResponse(res);
                };

                if (openEditBtn) {
                    openEditBtn.addEventListener('click', showEdit);
                }
                if (closeEditBtn && editModal) {
                    closeEditBtn.addEventListener('click', hideEdit);
                    editModal.addEventListener('click', (e) => {
                        if (e.target === editModal) hideEdit();
                    });
                    document.addEventListener('keyup', (e) => {
                        if (e.key === 'Escape' && !editModal.classList.contains('hidden')) hideEdit();
                    });
                }
                if (cancelEditBtn) {
                    cancelEditBtn.addEventListener('click', hideEdit);
                }
                if (shouldOpenEditModal) {
                    showEdit();
                }
                if (doujinTagDropdownButton && doujinTagDropdownMenu) {
                    renderDoujinTags();
                    doujinTagDropdownButton.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        doujinTagDropdownMenu.classList.toggle('hidden');
                        if (!doujinTagDropdownMenu.classList.contains('hidden')) {
                            doujinTagSearch?.focus();
                        }
                    });
                    doujinTagDropdownMenu.querySelectorAll('[data-doujin-tag-name]').forEach((item) => {
                        item.addEventListener('click', (event) => {
                            event.preventDefault();
                            event.stopPropagation();
                            const name = item.dataset.doujinTagName || '';
                            if (name === '') return;
                            if (selectedDoujinTags.includes(name)) {
                                selectedDoujinTags = selectedDoujinTags.filter((tag) => tag !== name);
                            } else {
                                selectedDoujinTags.push(name);
                            }
                            renderDoujinTags();
                        });
                    });
                    doujinTagSearch?.addEventListener('input', filterDoujinTags);
                    document.addEventListener('click', (event) => {
                        if (!doujinTagDropdownButton.contains(event.target) && !doujinTagDropdownMenu.contains(event.target)) {
                            doujinTagDropdownMenu.classList.add('hidden');
                        }
                    });
                }
                editForm?.addEventListener('submit', syncDoujinTagInput);

                if (openUploadBtn) {
                    openUploadBtn.addEventListener('click', () => {
                        resetUploadState();
                        showUpload();
                    });
                }
                if (closeUploadBtn && uploadModal) {
                    closeUploadBtn.addEventListener('click', hideUpload);
                    uploadModal.addEventListener('click', (e) => {
                        if (e.target === uploadModal) hideUpload();
                    });
                    document.addEventListener('keyup', (e) => {
                        if (e.key === 'Escape' && !uploadModal.classList.contains('hidden')) hideUpload();
                    });
                }
                if (cancelUploadBtn) {
                    cancelUploadBtn.addEventListener('click', hideUpload);
                }
                if (archiveInput) {
                    syncArchiveName();
                    archiveInput.addEventListener('change', () => {
                        syncArchiveName();
                        resetUploadState();
                    });
                }
                if (shouldOpenUploadModal) {
                    resetUploadState(true);
                    showUpload();
                    syncArchiveName();
                }
                if (mediaContentUploadForm) {
                    mediaContentUploadForm.addEventListener('submit', async (e) => {
                        e.preventDefault();

                        if (!archiveInput?.files?.length) {
                            setUploadError('Upload a ZIP archive.');
                            showUpload();
                            return;
                        }

                        const token = document.querySelector('meta[name="csrf-token"]')?.content;
                        const selectedFile = archiveInput.files[0];
                        const fileKey = uploadFileKey(selectedFile);
                        const canReuseUpload = currentUploadSession
                            && currentUploadSession.fileKey === fileKey;
                        setUploadBusy(true, submitUploadBtn?.dataset.defaultLabel || 'Upload ZIP');

                        try {
                            if (!canReuseUpload) {
                                currentUploadSession = {
                                    uploadId: createUploadId(),
                                    totalChunks: Math.max(1, Math.ceil(selectedFile.size / uploadChunkSizeBytes)),
                                    fileKey,
                                };
                                await uploadChunks(selectedFile, currentUploadSession, token);
                            }

                            await completeUpload(currentUploadSession, selectedFile, token);
                            currentUploadSession = null;
                            window.location.reload();
                            return;
                        } catch (err) {
                            const canReplace = Boolean(err?.canReplace);
                            const message = canReplace
                                ? `${err?.message || 'This number already exists.'} Click Replace Existing to overwrite it, or Cancel to keep the current one.`
                                : (err?.message || 'Upload failed.');

                            if (!canReplace) {
                                currentUploadSession = null;
                            }
                            setUploadError(message, canReplace);
                            showUpload();
                        } finally {
                            setUploadBusy(false);
                        }
                    });
                }

                if (openAddBtn) {
                    openAddBtn.addEventListener('click', showAdd);
                }
                if (closeAddBtn && addModal) {
                    closeAddBtn.addEventListener('click', hideAdd);
                    addModal.addEventListener('click', (e) => {
                        if (e.target === addModal) hideAdd();
                    });
                    document.addEventListener('keyup', (e) => {
                        if (e.key === 'Escape' && !addModal.classList.contains('hidden')) hideAdd();
                    });
                }

                if (openCreateBtn) {
                    openCreateBtn.addEventListener('click', () => {
                        hideAdd();
                        showCreate();
                    });
                }

                if (closeCreateBtn && createModal) {
                    closeCreateBtn.addEventListener('click', hideCreate);
                    createModal.addEventListener('click', (e) => {
                        if (e.target === createModal) hideCreate();
                    });
                    document.addEventListener('keyup', (e) => {
                        if (e.key === 'Escape' && !createModal.classList.contains('hidden')) hideCreate();
                    });
                }

                if (createForm && listContainer) {
                    createForm.addEventListener('submit', async (e) => {
                        e.preventDefault();
                        const token = document.querySelector('meta[name="csrf-token"]').content;
                        const res = await fetch(createForm.action, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
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
            });
        </script>
    @endauth
@endsection
