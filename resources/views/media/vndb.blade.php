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
        $isAdmin = optional(auth()->user()?->role)->role === 'Admin';
        $isViewer = optional(auth()->user()?->role)->role === 'Viewer';
        $hasUploadedGame = $hasUploadedGame ?? false;
        $currentScore = old('user_score', $mediaModel?->user_score ?? '0');
        $currentListStatus = old('list_status', $mediaModel?->list_status ?: 'WISHLIST');
        $listOptions = [
            'PLAYING' => 'Playing',
            'FINISHED' => 'Finished',
            'STALLED' => 'Stalled',
            'DROPPED' => 'Dropped',
            'WISHLIST' => 'Wishlist',
        ];

        if (!array_key_exists($currentListStatus, $listOptions)) {
            $currentListStatus = 'WISHLIST';
        }
        $parseIniBytes = static function (?string $value): int {
            $value = trim((string) $value);

            if ($value === '') {
                return 0;
            }

            $number = (float) $value;
            $unit = strtolower(substr($value, -1));

            return match ($unit) {
                'g' => (int) round($number * 1024 * 1024 * 1024),
                'm' => (int) round($number * 1024 * 1024),
                'k' => (int) round($number * 1024),
                default => (int) round($number),
            };
        };

        $uploadLimitBytes = $parseIniBytes(ini_get('upload_max_filesize'));
        $postLimitBytes = $parseIniBytes(ini_get('post_max_size'));
        $gameUploadMaxBytes = match (true) {
            $uploadLimitBytes > 0 && $postLimitBytes > 0 => min($uploadLimitBytes, $postLimitBytes),
            $uploadLimitBytes > 0 => $uploadLimitBytes,
            $postLimitBytes > 0 => $postLimitBytes,
            default => 0,
        };
        $formatBytes = static function (int $bytes): string {
            if ($bytes <= 0) {
                return '';
            }

            if ($bytes >= 1024 * 1024 * 1024) {
                return rtrim(rtrim(number_format($bytes / (1024 * 1024 * 1024), 2), '0'), '.').' GB';
            }

            if ($bytes >= 1024 * 1024) {
                return rtrim(rtrim(number_format($bytes / (1024 * 1024), 2), '0'), '.').' MB';
            }

            if ($bytes >= 1024) {
                return rtrim(rtrim(number_format($bytes / 1024, 2), '0'), '.').' KB';
            }

            return $bytes.' bytes';
        };
        $gameUploadMaxLabel = $formatBytes($gameUploadMaxBytes);

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

                      @if($hasUploadedGame && $mediaModel)
                      <a href="{{ route('vn.game.download', ['media' => $mediaModel->id]) }}"
                         @if(!empty($gameDownloadFilename)) download="{{ $gameDownloadFilename }}" @endif
                         class="flex items-center justify-start w-full flatGreen text-white py-2 rounded-sm shadow-sm h-[50px] transition-200">
                          <svg xmlns="http://www.w3.org/2000/svg"
                               class="ml-6 mt-[0.08rem]"
                                fill="none"
                               width="20"
                               height="20"
                               viewBox="0 0 24 24"
                               stroke="currentColor"
                               stroke-width="2.2">
                              <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 12-4-4m4 4 4-4M5 19h14" />
                          </svg>
                          <span class="ml-3">Download</span>
                      </a>
                      @endif

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
                      <button id="openEditEntryModal" type="button" class="flex items-center justify-start w-full text-blue-950 py-2 rounded-sm hover:text-[#08875b]">
                          <svg xmlns="http://www.w3.org/2000/svg"
                               class="ml-[1.4rem] h-[1.1rem] w-[1.1rem] mr-[0.5rem] mb-[0.1rem]"
                               fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                              <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M15.232 5.232l3.536 3.536M4 21h4.586a1 1 0 00.707-.293l10-10a1 1 0 000-1.414L14.414 4.293a1 1 0 00-1.414 0l-10 10A1 1 0 004 14.586V19a2 2 0 002 2z"/>
                          </svg>
                          <span class="ml-1">Edit</span>
                      </button>
                      @if($isAdmin && $mediaModel)
                      <button id="openGameUploadModal" type="button" class="flex items-center justify-start w-full text-blue-950 py-2 rounded-sm hover:text-[#08875b]">
                          <svg xmlns="http://www.w3.org/2000/svg"
                               class="ml-[1.4rem] h-[1.1rem] w-[1.1rem] mr-[0.5rem] mb-[0.1rem]"
                               fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                              <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14" />
                          </svg>
                          <span class="ml-1">Upload Game</span>
                      </button>
                      @endif
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

@auth
@unless($isViewer)
<div
  id="editEntryModal"
  class="fixed inset-0 flex items-center justify-center overflow-y-auto bg-black bg-opacity-50 hidden z-50 p-8"
>
  <div class="relative bg-white p-4 text-left shadow-2xl w-[800px] max-w-full rounded-lg my-auto">
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
      <form method="POST" action="{{ route('vn.entry.update', ['media' => $mediaModel->id]) }}" class="space-y-4 py-4" id="editEntryForm">
        @csrf
        @method('PATCH')

        @if(session('vn_entry_update_error'))
          <div class="app-alert app-alert-error px-4 py-3 text-sm">
            {{ session('vn_entry_update_error') }}
          </div>
        @endif

        <div class="grid grid-cols-2 gap-4">
          <label class="block">
            <span class="block mb-2 text-red-600 font-medium">Score</span>
            <input
              type="number"
              min="0"
              max="100"
              name="user_score"
              value="{{ $currentScore ?? '' }}"
              class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
            />
          </label>

          <label class="block">
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
      <button id="cancelEditModal" type="button" class="px-5 py-3 rounded border border-gray-200 text-gray-700 font-medium hover:bg-gray-100 transition-colors">
        Cancel
      </button>
      <button form="editEntryForm" type="submit" class="flatGreen transition-200 text-white px-5 py-3 rounded">
        Save Changes
      </button>
    </div>
  </div>
</div>
@endunless
@endauth

@if($isAdmin && $mediaModel)
<div
  id="gameUploadModal"
  class="fixed inset-0 flex items-start pt-[130px] justify-center bg-black bg-opacity-50 hidden z-50"
>
  <div class="relative bg-white p-4 text-left shadow-2xl w-[800px] rounded-lg">
    <div class="flex justify-between items-start pb-4 pt-2 border-b ml-4 mr-4 border-gray-200">
      <h3 class="text-lg font-bold text-gray-800">Upload Game</h3>
      <button id="closeGameUploadModal" type="button" class="text-gray-400 hover:text-gray-900" aria-label="Close Upload Modal">
        <span class="sr-only">Close</span>
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
             viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
          <path stroke-linecap="round" stroke-linejoin="round"
                d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <div class="border-b border-gray-200 mr-4 ml-4">
      <form id="gameUploadForm" method="POST" action="{{ route('vn.game.upload', ['media' => $mediaModel->id]) }}" enctype="multipart/form-data" class="space-y-4 py-4">
        @csrf

        <div id="gameUploadError" class="app-alert app-alert-error px-4 py-3 text-sm {{ session('vn_game_upload_error') ? '' : 'hidden' }}">
          <span id="gameUploadErrorText">{{ session('vn_game_upload_error') }}</span>
        </div>

        <div>
          <span class="block mb-2 text-red-600 font-medium">ZIP File</span>
          <input
              id="gameArchiveInput"
              type="file"
              name="game_archive"
              accept=".zip"
              class="sr-only"
              required
          />
          <label
            for="gameArchiveInput"
            class="flex w-full cursor-pointer items-center justify-between gap-4 rounded-md border border-gray-200 bg-gray-100 px-3 py-2 text-base text-gray-800 font-medium focus-within:ring-[0.2rem] focus-within:ring-red-600"
          >
            <span
              id="gameArchiveName"
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
      @if($hasUploadedGame)
      <form method="POST" action="{{ route('vn.game.delete', ['media' => $mediaModel->id]) }}" onsubmit="return confirm('Delete the uploaded game ZIP?');">
        @csrf
        @method('DELETE')
        <button type="submit" class="bg-red-600 text-white px-5 py-3 rounded hover:bg-red-700 transition-colors">
          Delete Game
        </button>
      </form>
      @else
      <div></div>
      @endif
      <div class="flex items-center gap-3">
        <button id="cancelGameUploadBtn" type="button" class="px-5 py-3 rounded border border-gray-200 text-gray-700 font-medium hover:bg-gray-100 transition-colors">
          Cancel
        </button>
        <button id="submitGameUploadBtn" form="gameUploadForm" type="submit" class="flatGreen transition-200 text-white px-5 py-3 rounded" data-default-label="Upload ZIP" data-uploading-label="Uploading...">
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
  const editModal      = document.getElementById('editEntryModal');
  const openAddBtn     = document.getElementById('openAddToCollection');
  const closeAddBtn    = document.getElementById('closeAddModal');
  const openCreateBtn  = document.getElementById('openInlineCreateCollection');
  const closeCreateBtn = document.getElementById('closeCreateModal');
  const openEditBtn    = document.getElementById('openEditEntryModal');
  const closeEditBtn   = document.getElementById('closeEditModal');
  const cancelEditBtn  = document.getElementById('cancelEditModal');
  const createForm     = document.getElementById('collectionCreateForm');
  const listContainer  = document.getElementById('collectionCheckboxList');
  const shouldOpenEditModal = @json(session('open_vn_edit_modal', false));

  // helpers
  const showAdd    = ()=> addModal.classList.remove('hidden');
  const hideAdd    = ()=> addModal.classList.add('hidden');
  const showCreate = ()=> createModal.classList.remove('hidden');
  const hideCreate = ()=> createModal.classList.add('hidden');
  const showEdit   = ()=> editModal?.classList.remove('hidden');
  const hideEdit   = ()=> editModal?.classList.add('hidden');

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
  if (openEditBtn) {
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

@if($isAdmin && $mediaModel)
<script>
document.addEventListener('DOMContentLoaded', () => {
  const gameUploadModal = document.getElementById('gameUploadModal');
  const openGameUploadBtn = document.getElementById('openGameUploadModal');
  const closeGameUploadBtn = document.getElementById('closeGameUploadModal');
  const cancelGameUploadBtn = document.getElementById('cancelGameUploadBtn');
  const gameUploadForm = document.getElementById('gameUploadForm');
  const gameArchiveInput = document.getElementById('gameArchiveInput');
  const pickGameArchiveBtn = document.getElementById('pickGameArchiveBtn');
  const gameArchiveName = document.getElementById('gameArchiveName');
  const gameUploadError = document.getElementById('gameUploadError');
  const gameUploadErrorText = document.getElementById('gameUploadErrorText');
  const submitGameUploadBtn = document.getElementById('submitGameUploadBtn');
  const shouldOpenGameUploadModal = @json(session('open_vn_game_upload_modal', false));
  const gameUploadMaxBytes = @json($gameUploadMaxBytes);
  const gameUploadChunkRoute = @json(route('vn.game.upload.chunk', ['media' => $mediaModel->id]));
  const gameUploadCompleteRoute = @json(route('vn.game.upload.complete', ['media' => $mediaModel->id]));
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const defaultChunkSizeBytes = 8 * 1024 * 1024;
  const gameUploadChunkBytes = gameUploadMaxBytes > 0
    ? Math.max(8 * 1024, Math.min(defaultChunkSizeBytes, Math.floor(gameUploadMaxBytes / 2)))
    : defaultChunkSizeBytes;
  let isUploadingGame = false;

  const showGameUpload = () => gameUploadModal?.classList.remove('hidden');
  const hideGameUpload = () => {
    if (isUploadingGame) {
      return;
    }

    gameUploadModal?.classList.add('hidden');
  };

  const setGameUploadError = (message) => {
    if (!gameUploadError || !gameUploadErrorText) {
      return;
    }

    if (message) {
      gameUploadErrorText.textContent = message;
      gameUploadError.classList.remove('hidden');
      return;
    }

    gameUploadErrorText.textContent = '';
    gameUploadError.classList.add('hidden');
  };

  const updateGameArchiveName = () => {
    if (!gameArchiveInput || !gameArchiveName) {
      return;
    }

    gameArchiveName.textContent = gameArchiveInput.files?.[0]?.name || gameArchiveName.dataset.placeholder || 'No ZIP selected';
  };

  const setGameUploadBusy = (busy, label = null) => {
    isUploadingGame = busy;

    if (submitGameUploadBtn) {
      submitGameUploadBtn.disabled = busy;
      submitGameUploadBtn.textContent = label || submitGameUploadBtn.dataset.defaultLabel || 'Upload ZIP';
    }

    if (cancelGameUploadBtn) {
      cancelGameUploadBtn.disabled = busy;
    }

    if (closeGameUploadBtn) {
      closeGameUploadBtn.disabled = busy;
    }

    if (pickGameArchiveBtn) {
      pickGameArchiveBtn.disabled = busy;
    }
  };

  const createUploadId = () => {
    const fallback = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    const value = window.crypto?.randomUUID ? window.crypto.randomUUID() : fallback;
    return value.replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 80);
  };

  const parseJsonResponse = async (response) => {
    let payload = null;

    try {
      payload = await response.json();
    } catch (error) {
      payload = null;
    }

    if (!response.ok) {
      throw new Error(payload?.message || 'Game upload failed.');
    }

    return payload;
  };

  const uploadGameInChunks = async (file) => {
    const totalChunks = Math.max(1, Math.ceil(file.size / gameUploadChunkBytes));
    const uploadId = createUploadId();

    for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
      const start = chunkIndex * gameUploadChunkBytes;
      const end = Math.min(file.size, start + gameUploadChunkBytes);
      const chunkBlob = file.slice(start, end);
      const formData = new FormData();
      formData.append('upload_id', uploadId);
      formData.append('chunk_index', String(chunkIndex));
      formData.append('total_chunks', String(totalChunks));
      formData.append('game_chunk', chunkBlob, `${file.name}.part${chunkIndex}`);

      await parseJsonResponse(await fetch(gameUploadChunkRoute, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrfToken,
        },
        body: formData,
      }));

      const percent = Math.max(1, Math.min(99, Math.round(((chunkIndex + 1) / totalChunks) * 100)));
      setGameUploadBusy(true, `Uploading ${percent}%`);
    }

    const completeFormData = new FormData();
    completeFormData.append('upload_id', uploadId);
    completeFormData.append('total_chunks', String(totalChunks));
    completeFormData.append('original_name', file.name);

    await parseJsonResponse(await fetch(gameUploadCompleteRoute, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrfToken,
      },
      body: completeFormData,
    }));
  };

  if (openGameUploadBtn) {
    openGameUploadBtn.addEventListener('click', () => {
      setGameUploadError('');
      showGameUpload();
    });
  }

  if (closeGameUploadBtn) {
    closeGameUploadBtn.addEventListener('click', hideGameUpload);
  }

  if (cancelGameUploadBtn) {
    cancelGameUploadBtn.addEventListener('click', hideGameUpload);
  }

  if (gameUploadModal) {
    gameUploadModal.addEventListener('click', (event) => {
      if (event.target === gameUploadModal) {
        hideGameUpload();
      }
    });
  }

  document.addEventListener('keyup', (event) => {
    if (event.key === 'Escape' && gameUploadModal && !gameUploadModal.classList.contains('hidden')) {
      hideGameUpload();
    }
  });

  if (pickGameArchiveBtn && gameArchiveInput) {
    pickGameArchiveBtn.addEventListener('click', () => gameArchiveInput.click());
  }

  if (gameArchiveInput) {
    gameArchiveInput.addEventListener('change', () => {
      updateGameArchiveName();
      setGameUploadError('');
    });
  }

  if (gameUploadForm && gameArchiveInput) {
    gameUploadForm.addEventListener('submit', async (event) => {
      event.preventDefault();

      if (isUploadingGame) {
        return;
      }

      if (!gameArchiveInput.files?.length) {
        setGameUploadError('Upload a ZIP archive.');
        showGameUpload();
        return;
      }

      const selectedFile = gameArchiveInput.files[0];
      if (!selectedFile.name.toLowerCase().endsWith('.zip')) {
        setGameUploadError('Upload a ZIP archive.');
        showGameUpload();
        return;
      }

      setGameUploadError('');
      setGameUploadBusy(true, submitGameUploadBtn?.dataset.uploadingLabel || 'Uploading...');

      try {
        await uploadGameInChunks(selectedFile);
        window.location.reload();
      } catch (error) {
        setGameUploadError(error instanceof Error ? error.message : 'Game upload failed.');
        setGameUploadBusy(false);
      }
    });
  }

  if (shouldOpenGameUploadModal) {
    updateGameArchiveName();
    showGameUpload();
  }
});
</script>
@endif

@endsection
