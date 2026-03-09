@extends('layouts.app')

@section('content')
    @php
        use App\Models\Chapter;
        use App\Models\Collection;
        use App\Models\CollectionItem;
        use App\Models\Favorite;
        use Illuminate\Support\Facades\Storage;

        $title = $media->title_romaji
              ?? $media->title_english
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
                        </div>
                    @endauth
                </div>

                {{-- Right Column --}}
                <div class="flex flex-col justify-start ml-8 mt-4 md:mt-2 text-gray-900 font-medium">
                    <h1 class="text-2xl font-bold text-red-600 mb-2">{{ $title }}</h1>
                    <div class="grid grid-cols-[7rem,1fr] gap-x-3 gap-y-4 text-sm mt-2 mb-2">
                        <div>Author</div>
                        <div>
                            @php $authors = collect($media->publisher ?? [])->filter()->unique()->values(); @endphp
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
                                    <div class="relative w-full rounded-lg overflow-hidden shadow-lg">
                                        <img src="{{ $thumb }}" alt="{{ $chapter->chapter_title }}" class="w-full h-auto object-contain">
                                        <span class="absolute z-10 bg-black/70 text-white text-lg font-semibold px-2 py-0.5 rounded leading-none"
                                              style="right: 8px; bottom: 8px; top: auto; left: auto;">
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
                                <span class="text-red-600 text-sm mt-1">{{ $message }}</span>
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
                const addModal       = document.getElementById('addToCollectionModal');
                const createModal    = document.getElementById('createCollectionModal');
                const openAddBtn     = document.getElementById('openAddToCollection');
                const closeAddBtn    = document.getElementById('closeAddModal');
                const openCreateBtn  = document.getElementById('openInlineCreateCollection');
                const closeCreateBtn = document.getElementById('closeCreateModal');
                const createForm     = document.getElementById('collectionCreateForm');
                const listContainer  = document.getElementById('collectionCheckboxList');

                const lockBody = () => document.body.classList.add('overflow-hidden');
                const unlockBody = () => {
                    const addHidden = !addModal || addModal.classList.contains('hidden');
                    const createHidden = !createModal || createModal.classList.contains('hidden');
                    if (addHidden && createHidden) {
                        document.body.classList.remove('overflow-hidden');
                    }
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
                }
            });
        </script>
    @endauth
@endsection
