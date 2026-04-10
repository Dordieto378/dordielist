@extends('layouts.app')

@section('content')
    @php
        $title = $item['title'] ?? 'No Title';

        $releaseDate = 'N/A';
        $raw = $item['released'] ?? ($item['year'] ?? null);

        if (!empty($raw)) {
            if (preg_match('/^\d{4}$/', (string)$raw)) {
                $releaseDate = (string)$raw;
            } else {
                try { $releaseDate = \Illuminate\Support\Carbon::parse($raw)->format('j M Y'); }
                catch (\Throwable $e) { $releaseDate = (string)$raw; }
            }
        }

        $averageScore = isset($item['average']) ? $item['average'].'%' : 'N/A';
        $scoreValue   = $item['score'] ?? request()->query('score');
        $myScore      = (is_numeric($scoreValue) && (int)$scoreValue > 0) ? ((int)$scoreValue).'%' : 'N/A';
        $romajiTitle = $item['title_romaji'] ?? null;
        $showRomajiTitle = filled($romajiTitle) && $romajiTitle !== $title;

        // use the actual model we passed via fetchVnById()
        $mediaModel = $item['media'] ?? null;
        $isViewer = optional(auth()->user()?->role)->role === 'Viewer';


    @endphp

<div class="flex flex-col items-center py-[8.5rem]">
    <div class="w-[1280px] h-auto bg-white shadow-sm rounded-md p-6 ml-[0.5rem]">
        <div class="flex flex-col md:flex-row">
            {{-- Left Column: Image & Buttons --}}
            <div class="flex flex-col items-center">
                <div class="thumb-wrapper thumb-portrait relative w-[325px] h-[450px] overflow-hidden rounded">
                    <img
                        src="{{ $item['image']['url'] ?? asset('images/no-image.jpg') }}"
                        alt="Cover Image"
                        class="thumb-img w-full h-full">
                </div>
                @auth
                  @unless($isViewer)
                  <div class="mt-4 flex flex-col space-y-3 w-[325px] font-bold">
                      @php
                      $id       = $item['id'];
                      $category = $category;
                      @endphp

                      <form action="{{ route('favorites.toggle') }}" method="POST" class="mt-2 w-full">
                          @csrf
                          <input type="hidden" name="favoritable_type" value="{{ $category }}">
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
                  </div>
                  @endunless
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
                    <div>{{ $item['title_native'] ?? 'N/A' }}</div>

                    <div>Release Year</div>
                    <div>{{ $releaseDate }}</div>

                    <div>Average Score</div>
                    <div>{{ $averageScore }}</div>

                    <div>My Score</div>
                    <div>{{ $myScore }}</div>

                    <div>Developers</div>
                    <div>
                      @forelse ($item['developers'] as $dev)
                          <a  href="{{ category_filter_url('visual-novel', 'developers', $dev['name']) }}"
                              class="text-blue-600 hover:underline cursor-pointer">
                              {{ $dev['name'] }}
                          </a>@if(!$loop->last), @endif
                      @empty
                          N/A
                      @endforelse
                    </div>
                </div>
                <p class="vn-desc text-sm mb-2 mt-2">
                    {!! $item['description_html'] !!}
                </p>
                <div class="mb-2 mt-2">
                    <div class="flex flex-wrap gap-2 text-xs text-gray-700">
                      @foreach ($item['tags'] ?? [] as $tag)
                          <a  href="{{ category_filter_url('visual-novel', 'tags', $tag['name']) }}"
                              class="inline-block bg-gray-100 px-4 py-3 rounded-sm
                                    hover:bg-gray-200 transition">
                              {{ $tag['name'] }}
                          </a>
                      @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

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
        <input type="hidden" name="item_type"  value="{{ $category }}">
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

<script>
document.addEventListener('DOMContentLoaded', () => {
  // grab everything once
  const addModal       = document.getElementById('addToCollectionModal');
  const createModal    = document.getElementById('createCollectionModal');
  const openAddBtn     = document.getElementById('openAddToCollection');
  const closeAddBtn    = document.getElementById('closeAddModal');
  const openCreateBtn  = document.getElementById('openInlineCreateCollection');
  const closeCreateBtn = document.getElementById('closeCreateModal');
  const createForm     = document.getElementById('collectionCreateForm');
  const listContainer  = document.getElementById('collectionCheckboxList');

  // helpers
  const showAdd    = ()=> addModal.classList.remove('hidden');
  const hideAdd    = ()=> addModal.classList.add('hidden');
  const showCreate = ()=> createModal.classList.remove('hidden');
  const hideCreate = ()=> createModal.classList.add('hidden');

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

    // close the Create modal
    hideCreate();
    showAdd();
  });
    const spoilers = document.querySelectorAll('.vn-desc .spoiler');

    const setExpanded = (el, on) => {
        el.classList.toggle('revealed', on);
        el.setAttribute('aria-expanded', on ? 'true' : 'false');
    };

    spoilers.forEach(el => {
        // hover
        el.addEventListener('mouseenter', () => setExpanded(el, true));
        el.addEventListener('mouseleave', () => setExpanded(el, false));
        // keyboard focus
        el.addEventListener('focus',      () => setExpanded(el, true));
        el.addEventListener('blur',       () => setExpanded(el, false));
        // tap/click toggle (mobile support)
        el.addEventListener('click', e => {
            // if already revealed and user clicks a link inside, let it pass
            if (e.target.closest('a') && el.classList.contains('revealed')) return;
            e.preventDefault();
            setExpanded(el, !el.classList.contains('revealed'));
        });
    });

});
</script>

@endsection
