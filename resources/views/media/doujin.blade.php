@extends('layouts.app')

@section('content')
@php
    use Illuminate\Support\Facades\Storage;

    $title      = $doujin->doujin_name;
    $isNsfw     = true; 
    $shouldBlur = $isNsfw && ! Auth::check();

    $coverUrl = Storage::disk('b2')->url($doujin->cover_url);
@endphp
<div class="flex flex-col items-center py-[8.5rem]">
    <div class="w-[1280px] bg-white shadow-sm rounded-md p-6 ml-[0.5rem]">
        <div class="flex flex-col md:flex-row">
            {{-- Left Column: Cover + Buttons --}}
            <div class="flex flex-col items-center">
                <div class="relative w-[325px] h-[450px] overflow-hidden rounded">
                    @if($shouldBlur)
                        <img
                            src="{{ asset('images/18-plus.png') }}"
                            alt="18+"
                            class="absolute top-2 right-2 w-8 h-8 z-10"
                        >
                    @endif

                    <img
                        src="{{ $coverUrl }}"
                        alt="Cover Image"
                        class="w-full h-full object-cover {{ $shouldBlur ? 'filter blur-2xl' : '' }}"
                    >
                </div>
                @auth
                  <div class="mt-4 flex flex-col space-y-3 w-[325px] font-bold">
                      <button
                          onclick="window.location.href='{{ route('media.doujin.page', ['doujin' => $doujin->id, 'page' => 1]) }}'"
                          class="flex items-center justify-start w-full flatGreen text-white py-2 rounded-sm shadow-sm h-[50px] transition-200"
                      >
                          <svg
                              version="1.1"
                              xmlns="http://www.w3.org/2000/svg"
                              viewBox="0 0 460.114 460.114"
                              width="15"
                              height="15"
                              fill="#fff"
                              class="ml-[1.5rem] mb-[0.1rem]"
                          >
                              <g><g>
                                  <path d="M393.538,203.629L102.557,5.543c-9.793-6.666-22.468-7.372-32.94-1.832
                                            c-10.472,5.538-17.022,16.413-17.022,28.26v396.173
                                            c0,11.846,6.55,22.721,17.022,28.26
                                            c10.471,5.539,23.147,4.834,32.94-1.832
                                            l290.981-198.087
                                            c8.746-5.954,13.98-15.848,13.98-26.428
                                            C407.519,219.477,402.285,209.582,393.538,203.629z"/>
                              </g></g>
                          </svg>
                          <span class="ml-[0.8rem]">Start Reading</span>
                      </button>

                      <form action="{{ route('favorites.toggle') }}" method="POST" class="mt-2 w-full">
                          @csrf
                          <input type="hidden" name="favoritable_type" value="doujins">
                          <input type="hidden" name="favoritable_id"   value="{{ $doujin->id }}">
                          <button
                              type="submit"
                              class="flex items-center w-full text-blue-950 py-2 rounded-sm hover:text-red-600 transition"
                          >
                              @if($isFavorited)
                                  <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" stroke="none"
                                      class="ml-[1.4rem] h-[1.1rem] w-[1.1rem] mr-[0.5rem] mb-[0.1rem]"
                                      viewBox="0 0 24 24">
                                      <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5
                                              2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09
                                              C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5
                                              c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                                  </svg>
                                  <span>Remove Favorite</span>
                              @else
                                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor"
                                      class="ml-[1.4rem] h-[1.1rem] w-[1.1rem] mr-[0.5rem] mb-[0.1rem]"
                                      stroke-width="2.5" viewBox="0 0 24 24">
                                      <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5
                                                2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09
                                                C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5
                                                c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                                  </svg>
                                  <span>Add to Favorites</span>
                              @endif
                          </button>
                      </form>

                      <button
                          id="openAddToCollection"
                          class="flex items-center justify-start w-full text-blue-950 py-2 rounded-sm hover:text-yellow-400"
                      >
                          <svg xmlns="http://www.w3.org/2000/svg"
                              class="ml-[1.4rem] h-[1.1rem] w-[1.1rem] mr-[0.5rem] mb-[0.1rem]"
                              fill="none"
                              viewBox="0 0 24 24"
                              stroke="currentColor"
                              stroke-width="2"
                          >
                              <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M3 7a2 2 0 012-2h5l2 2h5a2 2 0 012 2v1H3V7z
                                        M3 11h18v7a2 2 0 01-2 2H5a2 2 0 01-2-2v-7z"/>
                          </svg>
                          <span class="ml-[0.2rem]">Add to Collection</span>
                      </button>
                      @if(optional(auth()->user()->role)->role === 'Admin')
                      <form action="{{ route('doujin.destroy', [$doujin->author_name, $doujin->doujin_name]) }}" method="POST" class="mt-2 w-full"  onsubmit="return confirm('Are you sure you want to delete this entire doujin and all its files?');">
                          @csrf
                           @method('DELETE') 
                          <button
                              type="submit"
                              class="flex items-center w-full text-blue-950 py-2 rounded-sm hover:text-red-600 transition"
                          >
                                  <svg xmlns="http://www.w3.org/2000/svg"
                                      class="ml-[1.4rem] h-[1.1rem] w-[1.1rem] mr-[0.5rem] mb-[0.1rem]"
                                      fill="none"
                                      viewBox="0 0 24 24"
                                      stroke="currentColor"
                                      stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3" />
                                  </svg>
                                  <span>Delete</span>
                          </button>
                      </form>
                      <button
                        id="openRenameModal"
                        class="flex items-center w-full text-blue-950 py-2 rounded-sm hover:text-yellow-400 transition"
                      >
                          <svg xmlns="http://www.w3.org/2000/svg"
                              class="ml-[1.4rem] h-[1.1rem] w-[1.1rem] mr-[0.5rem] mb-[0.1rem]"
                              fill="none"
                              viewBox="0 0 24 24"
                              stroke="currentColor"
                              stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M15.232 5.232l3.536 3.536M4 21h4.586a1 1 0 00.707-.293l10-10a1 1 0 000-1.414L14.414 4.293a1 1 0 00-1.414 0l-10 10A1 1 0 004 14.586V19a2 2 0 002 2z" />
                          </svg>
                        <span>Rename</span>
                      </button> 
                      @endif
                  </div>
                @endauth
            </div>

            <div class="flex flex-col justify-start ml-8 mt-4 md:mt-2 text-gray-900 font-medium">
                <h1 class="text-2xl font-bold text-red-600 mb-2">{{ $title }}</h1>

                <div class="grid grid-cols-[7rem,1fr] gap-x-3 gap-y-4 text-sm mt-2 mb-2">
                    <div>Author</div>
                      <div>
                          <a  href="{{ category_filter_url('doujins', 'author', $doujin->author_name) }}"
                              class="text-blue-600 hover:underline cursor-pointer">
                              {{ $doujin->author_name }}
                          </a>
                      </div>
                </div>

                <div class="grid grid-cols-[7rem,1fr] gap-x-3 gap-y-4 text-sm mt-2 mb-2">
                    <div>Pages</div>
                    <div>
                      {{ $doujin->pages->count() }}
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
@auth
@if($doujin->pages->isNotEmpty())
  <div class="flex justify-center mb-12 mt-[-100px]">
  <div
    id="pagesGrid"
    class="w-[1278px] ml-[13px] grid grid-cols-4 gap-4 transition-opacity duration-500 ease-in-out"
  >
    @php
      $pageRows = $doujin->pages->chunk(4);
      $allowedExts = ['jpg','jpeg','png','gif','webp'];
    @endphp

    @foreach($pageRows as $rowIndex => $pageChunk)
      @php
        $rowPages = ($rowIndex % 2 === 0)
                     ? $pageChunk->reverse()->values()
                     : $pageChunk->values();
        $count    = $rowPages->count();
      @endphp

      @if($rowIndex % 2 === 0 && $count < 4)
        @for($i = 0; $i < 4 - $count; $i++)
          <div></div>
        @endfor
      @endif

      @foreach($rowPages as $page)
        @php
          $ext     = strtolower(pathinfo($page->file_path, PATHINFO_EXTENSION) ?? '');
          $isImage = in_array($ext, $allowedExts, true);
          $pageUrl = $isImage ? Storage::disk('b2')->url($page->file_path) : null;
          $shouldBlur = true && ! Auth::check();
        @endphp

        @if($isImage)
          <div
            onclick="window.location.href='{{ route('media.doujin.page', [
              'doujin' => $doujin->id,
              'page'   => $page->page_number
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
                src="{{ $pageUrl }}"
                alt="Page {{ $page->page_number }} of {{ $doujin->doujin_name }}"
                class="w-full h-auto object-contain {{ $shouldBlur ? 'filter blur-2xl' : '' }}"
              >
            </div>
          </div>
        @endif
      @endforeach
    @endforeach
  </div>
</div>
@else
  <p class="text-center text-gray-500 font-medium mb-12">No pages found for this doujin.</p>
@endif
@endauth
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

    <div id="overlay-content" class="border-b border-gray-200 mr-4 ml-4">
      <form
        method="POST"
        action="{{ route('collection.attachMedia') }}"
        class="space-y-4"
        id="attachCollectionsForm"
      >
        @csrf

        <input type="hidden" name="item_type" value="doujins">
        <input type="hidden" name="item_id"   value="{{ $doujin->id }}">

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
                        {{ $isFavorited ? 'checked' : '' }}
                        onchange="this.form.submit()"
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
  <div class="relative bg-white p-4 text-left shadow-2xl w-[800px] h-[255px] rounded-lg space-y-6 overflow-auto" role="dialog" aria-modal="true">
    <div class="flex justify-between items-start pb-4 pt-2 border-b border-gray-200">
      <h2 id="overlay-title" class="text-lg font-bold text-gray-800 pl-4">Create New Collection</h2>
      <button id="closeCreateModal" type="button" class="text-gray-400 hover:text-gray-900 pr-4" aria-label="Close Create Modal">
        <span class="sr-only">Close</span>
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
             viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
          <path stroke-linecap="round" stroke-linejoin="round"
                d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <div id="overlay-content" class="space-y-4">
      <form id="collectionCreateForm" class="space-y-4" method="POST" action="{{ route('collection.store') }}">
        @csrf

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

        <div class="block">
          <button type="submit" class="flatGreen transition-200 text-white px-5 py-3 rounded ml-4">
            Create Collection
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

 <div
  id="renameModal"
  class="fixed inset-0 flex items-start pt-[130px] justify-center bg-black bg-opacity-50 hidden z-50"
>
  <div
    class="relative bg-white p-4 text-left shadow-2xl w-[800px] rounded-lg space-y-6"
    role="dialog"
    aria-modal="true"
  >
    <div class="flex justify-between items-start pb-4 pt-2 border-b border-gray-200">
      <h2 id="overlay-title" class="text-lg font-bold text-gray-800 pl-4">
        Rename
      </h2>
      <button
        id="closeRenameModal"
        type="button"
        class="text-gray-400 hover:text-gray-900 pr-4"
        aria-label="Close Rename Modal"
      >
        <span class="sr-only">Close</span>
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
             viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
          <path stroke-linecap="round" stroke-linejoin="round"
                d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <div id="overlay-content" class="space-y-4">
      <form
        id="renameForm"
        class="space-y-4"
        method="POST"
        action="{{ route('doujin.rename', [$doujin->author_name, $doujin->doujin_name]) }}"
      >
        @csrf
        @method('PATCH')

        <label class="block relative" for="newTitle">
          <span class="block mb-2 label-text text-red-600 font-medium pl-4">
            New Title
          </span>
          <input
            type="text"
            id="newTitle"
            name="newTitle"
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
            value="{{ old('newTitle') }}"
          />
          @error('newTitle')
            <span class="text-red-600 text-sm mt-1">{{ $message }}</span>
          @enderror
        </label>

        <div class="block">
          <button type="submit" class="flatGreen transition-200 text-white px-5 py-3 rounded ml-4">
            Save
          </button>
        </div>
      </form>
    </div>
  </div>
</div> 


<script>
document.addEventListener('DOMContentLoaded', () => {
  const htmlEl = document.documentElement; // <html> element

  // ------ Add to Collection Modal ------
  const addModal    = document.getElementById('addToCollectionModal');
  const openAddBtn  = document.getElementById('openAddToCollection');
  const closeAddBtn = document.getElementById('closeAddModal');

  function showAdd() {
    addModal.classList.remove('hidden');
    htmlEl.style.overflow = 'hidden';
  }
  function hideAdd() {
    addModal.classList.add('hidden');
    htmlEl.style.overflow = '';
  }

  openAddBtn.addEventListener('click', showAdd);
  closeAddBtn.addEventListener('click', hideAdd);
  addModal.addEventListener('click', e => {
    if (e.target === addModal) hideAdd();
  });

  // ------ Create Collection Modal ------
  const createModal    = document.getElementById('createCollectionModal');
  const openCreateBtn  = document.getElementById('openInlineCreateCollection');
  const closeCreateBtn = document.getElementById('closeCreateModal');
  const createForm     = document.getElementById('collectionCreateForm');
  const listContainer  = document.getElementById('collectionCheckboxList');

  function showCreate() {
    createModal.classList.remove('hidden');
    htmlEl.style.overflow = 'hidden';
  }
  function hideCreate() {
    createModal.classList.add('hidden');
    htmlEl.style.overflow = '';
  }

  openCreateBtn.addEventListener('click', () => {
    hideAdd();
    showCreate();
  });
  closeCreateBtn.addEventListener('click', hideCreate);
  createModal.addEventListener('click', e => {
    if (e.target === createModal) hideCreate();
  });
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

  // ------ Rename Modal ------
  const renameModal   = document.getElementById('renameModal');
  const openRenameBtn = document.getElementById('openRenameModal');
  const closeRenameBtn= document.getElementById('closeRenameModal');

  function showRename() {
    renameModal.classList.remove('hidden');
    htmlEl.style.overflow = 'hidden';
  }
  function hideRename() {
    renameModal.classList.add('hidden');
    htmlEl.style.overflow = '';
  }

  openRenameBtn.addEventListener('click', showRename);
  closeRenameBtn.addEventListener('click', hideRename);
  renameModal.addEventListener('click', e => {
    if (e.target === renameModal) hideRename();
  });

  // Global Escape listener
  document.addEventListener('keyup', e => {
    if (e.key === 'Escape') {
      if (!addModal.classList.contains('hidden'))    hideAdd();
      if (!createModal.classList.contains('hidden')) hideCreate();
      if (!renameModal.classList.contains('hidden')) hideRename();
    }
  });
});
</script>

@endsection
