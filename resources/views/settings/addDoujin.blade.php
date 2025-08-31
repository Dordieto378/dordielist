{{-- resources/views/settings/addDoujin.blade.php --}}
@extends('layouts.app')

@section('content')
  @if(optional(auth()->user()->role)->role === 'Admin')
    <div class="bg-gray-100 mb-[50px] pt-[100px]">
      <div class="max-w-[1320px] mx-auto px-4 sm:px-6 lg:px-8 flex space-x-6 text-sm">
        
        {{-- LEFT: Shared sidebar --}}
        @include('settings.partials.sidebar')

        {{-- RIGHT: “Add Doujin” form --}}
        <main class="w-3/4">
          <div class="bg-white shadow-md rounded-lg overflow-hidden">
            <div class="p-8">
              <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold text-red-600">Add Doujin</h1>
              </div>

              {{-- FLASH MESSAGE --}}
              @if(session('status'))
                @php
                  $bg = session('status_color') === 'red'
                          ? 'bg-red-100 border-red-200 text-red-800'
                          : (
                              session('status_color') === 'green'
                              ? 'bg-green-100 border-green-200 text-green-800'
                              : 'bg-gray-100 border-gray-200 text-gray-800'
                          );
                @endphp
                <div class="mt-4 p-4 {{ $bg }} border rounded">
                  {{ session('status') }}
                </div>
              @endif

              <form 
                method="POST" 
                action="{{ route('settings.doujin.store') }}" 
                class="mt-4"
                enctype="multipart/form-data"
                autocomplete="off"
              >
                @csrf

                {{-- ——— Author (Autocomplete “combo box”) ——— --}}
                <div class="mt-6 relative">
                  <label for="author_input" class="block font-medium text-[17px] text-red-600">
                    Author
                  </label>
                  <div class="mt-1">
                    <input
                      type="text"
                      id="author_input"
                      name="author"
                      value="{{ old('author') }}"
                      class="block w-full rounded-md border border-gray-300 py-2 pl-3 pr-10 text-base
                             bg-gray-50 focus:outline-none focus:ring-2 focus:ring-red-600
                             text-gray-800 font-medium @error('author') border-red-500 @enderror"
                      required
                    />
                    {{-- Custom down‐chevron (purely decorative) --}}
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pt-6 pr-3">
                      <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                      </svg>
                    </div>
                    {{-- Suggestions dropdown (hidden by default) --}}
                    <div 
                      id="author_suggestions"
                      class="absolute z-10 mt-1 w-full bg-white border border-gray-300 rounded-md shadow-lg max-h-60 overflow-auto hidden"
                    >
                      {{-- JS will inject <div class="px-3 py-2 hover:bg-gray-100 cursor-pointer">…</div> here --}}
                    </div>

                    @error('author')
                      <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                    @enderror
                  </div>
                </div>

                {{-- Title --}}
                <div class="mt-4">
                  <label for="title" class="block font-medium text-[17px] text-red-600">
                    Title
                  </label>
                  <div class="relative mt-1">
                    <input
                      type="text"
                      name="title"
                      id="title"
                      value="{{ old('title') }}"
                      class="w-full rounded-md border border-gray-300 py-2 pl-3 pr-3 text-base
                             bg-gray-50 focus:outline-none focus:ring-2 focus:ring-red-600
                             text-gray-800 font-medium @error('title') border-red-500 @enderror"
                      required
                    />
                    @error('title')
                      <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                    @enderror
                  </div>
                </div>

                {{-- Upload Files --}}
                <div class="mt-8">
                  <label class="block font-medium text-[17px] text-red-600 mb-2">
                    Upload Pages
                  </label>
                  <div
                    id="drop-zone"
                    class="mt-1 flex flex-col items-center justify-center border-2 border-dashed border-gray-300 rounded-lg h-64 cursor-pointer relative"
                  >
                    {{-- Invisible full-size <input> captures clicks/drops --}}
                    <input
                      type="file"
                      name="files[]"
                      id="file-input"
                      class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                      multiple
                      accept="image/png, image/jpeg, image/webp, image/jpg"
                    />

                    {{-- Instructional text --}}
                    <p class="mt-2 text-lg font-medium text-gray-600 relative z-10">
                    <span class="pointer-events-none">Drag and drop here </span>
                    <span class="text-blue-600 hover:underline cursor-pointer" onclick="document.getElementById('file-input').click();">
                        or browse
                    </span>
                    </p>

                    {{-- Filename display area --}}
                    <p id="file-info" class="mt-1 text-sm text-gray-400 font-medium">
                      PNG, JPG, JPEG, WEBP
                    </p>
                  </div>

                  @error('files.*')
                    <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
                  @enderror
                </div>

                {{-- Submit Button --}}
                <div class="mt-8 flex justify-center">
                  <button
                    type="submit"
                    class="h-12 w-40 rounded-md bg-[#08875b] text-white text-lg
                           hover:bg-emerald-700 focus:outline-none"
                  >
                    Add
                  </button>
                </div>
              </form>
            </div>
          </div>
        </main>
      </div>
    </div>

    {{-- ——— Autocomplete & Drop-Zone JS ——— --}}
    <script>
      document.addEventListener('DOMContentLoaded', () => {
        // === Autocomplete JS ===
        const existingAuthors = @json($authors);
        const input = document.getElementById('author_input');
        const suggestionsBox = document.getElementById('author_suggestions');

        function getMatches(query) {
          if (!query) return [];
          const lc = query.toLowerCase();
          return existingAuthors.filter(name =>
            name.toLowerCase().includes(lc)
          );
        }

        function renderSuggestions(list) {
          suggestionsBox.innerHTML = '';
          if (list.length === 0) {
            suggestionsBox.classList.add('hidden');
            return;
          }
          list.forEach(name => {
            const div = document.createElement('div');
            div.className = 'px-3 py-2 hover:bg-gray-100 text-gray-800 font-medium cursor-pointer';
            div.textContent = name;
            div.addEventListener('mousedown', () => {
              input.value = name;
              suggestionsBox.classList.add('hidden');
            });
            suggestionsBox.appendChild(div);
          });
          suggestionsBox.classList.remove('hidden');
        }

        document.addEventListener('click', (e) => {
          if (!input.contains(e.target) && !suggestionsBox.contains(e.target)) {
            suggestionsBox.classList.add('hidden');
          }
        });

        input.addEventListener('input', () => {
          const matches = getMatches(input.value.trim());
          renderSuggestions(matches);
        });
        input.addEventListener('focus', () => {
          const matches = getMatches(input.value.trim());
          renderSuggestions(matches);
        });
        input.addEventListener('keydown', (e) => {
          if (e.key === 'Escape') {
            suggestionsBox.classList.add('hidden');
          }
        });

        // === Drop-Zone & File-Info JS ===
        const dropZone = document.getElementById('drop-zone');
        const fileInput = document.getElementById('file-input');
        const fileInfo  = document.getElementById('file-info');

        if (dropZone && fileInput && fileInfo) {
          // No manual fileInput.click() on dropZone—<input> covers it.

          // Highlight on dragover
          dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('ring-2', 'ring-red-600');
          });

          dropZone.addEventListener('dragleave', (e) => {
            dropZone.classList.remove('ring-2', 'ring-red-600');
          });

          // Handle dropped files
          dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('ring-2', 'ring-red-600');
            if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
              fileInput.files = e.dataTransfer.files;
              updateFileInfo();
            }
          });

          // Handle file selection via dialog
          fileInput.addEventListener('change', () => {
            updateFileInfo();
          });

          // Update filenames under the instruction text
          function updateFileInfo() {
            const files = Array.from(fileInput.files);
            if (files.length === 0) {
              fileInfo.textContent = 'PNG, JPG, JPEG, WEBP';
            } else {
              const names = files.map(f => f.name).join(', ');
              fileInfo.textContent = names;
            }
          }
        } else {
          console.warn('Drop-zone, file-input, or file-info not found in DOM.');
        }
      });
    </script>
    {{-- ———————————————————————————————— --}}
  @endif
@endsection
