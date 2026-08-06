{{-- resources/views/settings/doujin-authors.blade.php --}}
@extends('layouts.app')

@section('content')
  @php
    $openEditAuthorId = session('open_edit_author_id');
    $editOldValues = [
      'name' => old('name'),
      'links' => collect($socialPlatforms)
        ->mapWithKeys(fn ($meta, $column) => [$column => old($column)])
        ->all(),
    ];
    $linkColumns = array_keys($socialPlatforms);
  @endphp

  <div class="bg-gray-100 mb-[50px] pt-[100px]">
    <div class="max-w-[1320px] mx-auto px-4 sm:px-6 lg:px-8 flex space-x-6 text-sm">

      @include('settings.partials.sidebar')

      <main class="w-3/4">
        <div class="bg-white shadow-md rounded-lg overflow-hidden p-8">
          <div class="flex items-center justify-between gap-4">
            <h1 class="text-2xl font-bold text-red-600">Doujin Artists</h1>
          </div>

          @if(session('status'))
            <div class="app-alert mt-4 mb-4 {{ session('status_color') === 'red' ? 'app-alert-error' : 'app-alert-success' }}">
              {{ session('status') }}
            </div>
          @endif

          <div class="mt-6 divide-y divide-gray-200">
            @forelse($authors as $author)
              <section class="py-6 first:pt-0 last:pb-0">
                <div class="flex items-start justify-between gap-4">
                  <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-3">
                      <h2 class="text-lg font-bold text-gray-800 break-words">{{ $author->name }}</h2>
                      <span class="inline-flex rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700">
                        {{ $author->doujin_count }} doujin(s)
                      </span>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2">
                      @php $hasSocialLinks = false; @endphp
                      @foreach($socialPlatforms as $column => $meta)
                        @php
                          $urls = \App\Support\DoujinAuthorLinks::urls($author->{$column} ?? null);
                          $hasSocialLinks = $hasSocialLinks || $urls !== [];
                        @endphp

                        @foreach($urls as $url)
                          @php
                            $href = preg_match('#^https?://#i', $url) ? $url : 'https://'.ltrim($url, '/');
                          @endphp
                          <a
                            href="{{ $href }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex max-w-full items-center gap-1 rounded bg-gray-100 px-2 py-1 text-xs font-medium text-blue-700 hover:underline"
                          >
                            <span class="shrink-0">{{ $meta['label'] }}</span>
                            <span class="truncate">{{ $url }}</span>
                          </a>
                        @endforeach
                      @endforeach

                      @unless($hasSocialLinks)
                        <span class="text-sm text-gray-500">No social links</span>
                      @endunless
                    </div>
                  </div>

                  <div class="flex shrink-0 items-center gap-3">
                    <button
                      type="button"
                      class="text-blue-600 hover:text-blue-800 hover:underline openEditAuthorModal"
                      data-author-id="{{ $author->id }}"
                    >
                      Edit
                    </button>

                    @if((int) $author->doujin_count === 0)
                      <form
                        method="POST"
                        action="{{ route('settings.doujin-authors.destroy', ['author' => $author->id]) }}"
                        data-delete-author-form
                        data-author-name="{{ $author->name }}"
                      >
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="confirm_author_id" value="{{ $author->id }}">
                        <button type="submit" class="text-red-600 hover:text-red-800 hover:underline">
                          Delete Empty Artist
                        </button>
                      </form>
                    @else
                      <span class="text-gray-400">
                        Remove from doujins first
                      </span>
                    @endif
                  </div>
                </div>

                <div class="mt-5 overflow-x-auto">
                  <table class="min-w-full table-auto bg-white">
                    <thead>
                      <tr class="border-b border-gray-200">
                        <th class="px-4 py-3 text-left font-medium text-gray-800 uppercase tracking-wider">Doujin</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-800 uppercase tracking-wider">Add Existing Artist</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-800 uppercase tracking-wider">New Artist</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-800 uppercase tracking-wider">Actions</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-medium">
                      @forelse($author->media as $doujin)
                        @php
                          $doujinTitle = $doujin->title_english
                            ?: ($doujin->title_romaji
                              ?: ($doujin->title_native
                                ?: ($doujin->slug ?: 'Doujin #'.$doujin->id)));
                        @endphp
                        <tr>
                          <td class="px-4 py-3 text-gray-800">
                            <form
                              id="attachDoujinAuthor{{ $author->id }}_{{ $doujin->id }}"
                              method="POST"
                              action="{{ route('settings.doujin-authors.doujins.authors.attach', ['author' => $author->id, 'media' => $doujin->id]) }}"
                              class="hidden"
                            >
                              @csrf
                            </form>
                            <form
                              id="detachDoujinAuthor{{ $author->id }}_{{ $doujin->id }}"
                              method="POST"
                              action="{{ route('settings.doujin-authors.doujins.authors.detach', ['author' => $author->id, 'media' => $doujin->id]) }}"
                              class="hidden"
                            >
                              @csrf
                              @method('DELETE')
                            </form>
                            <a href="{{ route('doujins.show', ['media' => $doujin->id]) }}" class="text-blue-600 hover:underline">
                              {{ $doujinTitle }}
                            </a>
                            <div class="mt-1 text-xs font-normal text-gray-500">
                              {{ $doujin->doujinAuthors->pluck('name')->join(', ') }}
                            </div>
                          </td>
                          <td class="px-4 py-3">
                            @php
                              $attachedAuthorIds = $doujin->doujinAuthors
                                ->pluck('id')
                                ->map(fn ($id) => (int) $id)
                                ->all();
                            @endphp
                            <select
                              name="target_author_id"
                              form="attachDoujinAuthor{{ $author->id }}_{{ $doujin->id }}"
                              class="w-48 rounded-md border border-gray-200 px-3 py-2 text-sm bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                            >
                              <option value="">Select artist</option>
                              @foreach($authors as $targetAuthor)
                                @continue(in_array((int) $targetAuthor->id, $attachedAuthorIds, true))
                                <option value="{{ $targetAuthor->id }}">
                                  {{ $targetAuthor->name }}
                                </option>
                              @endforeach
                            </select>
                          </td>
                          <td class="px-4 py-3">
                            <input
                              type="text"
                              name="new_author"
                              form="attachDoujinAuthor{{ $author->id }}_{{ $doujin->id }}"
                              value=""
                              class="w-48 rounded-md border border-gray-200 px-3 py-2 text-sm bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                              placeholder="New artist"
                            />
                          </td>
                          <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                              <button
                                type="submit"
                                form="attachDoujinAuthor{{ $author->id }}_{{ $doujin->id }}"
                                class="flatGreen text-white px-4 py-2 rounded text-sm"
                              >
                                Add
                              </button>
                              <button
                                type="submit"
                                form="detachDoujinAuthor{{ $author->id }}_{{ $doujin->id }}"
                                class="text-red-600 hover:text-red-800 hover:underline"
                                onclick="return confirm('Remove {{ $author->name }} from this doujin?');"
                              >
                                Remove
                              </button>
                            </div>
                          </td>
                        </tr>
                      @empty
                        <tr>
                          <td colspan="4" class="px-4 py-4 text-center text-gray-500">
                            No doujins found.
                          </td>
                        </tr>
                      @endforelse
                    </tbody>
                  </table>
                </div>
              </section>
            @empty
              <div class="py-8 text-center text-gray-500">
                No doujin artists found.
              </div>
            @endforelse
          </div>
        </div>
      </main>
    </div>
  </div>

  <div
    id="editAuthorModal"
    class="fixed inset-0 flex items-start pt-[130px] justify-center bg-black bg-opacity-50 hidden z-50"
  >
    <div
      class="relative bg-white p-4 text-left shadow-2xl w-[800px] rounded-lg space-y-6 overflow-auto max-h-[80vh]"
      role="dialog"
      aria-modal="true"
    >
      <div class="flex justify-between items-start pb-4 pt-2 border-b border-gray-200">
        <h2 class="text-lg font-bold text-gray-800 pl-4">Edit Artist</h2>
        <button type="button" class="text-gray-400 hover:text-gray-900 pr-4" aria-label="Close">
          <span class="sr-only">Close</span>
          <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>

      <form id="editAuthorForm" class="space-y-4" method="POST" action="">
        @csrf
        @method('PUT')

        <label class="block relative" for="editAuthorName">
          <span class="block mb-2 label-text text-red-600 font-medium pl-4">Artist Name</span>
          <input
            type="text"
            id="editAuthorName"
            name="name"
            class="w-[735px] ml-4 rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
            required
          />
        </label>

        @foreach($socialPlatforms as $column => $meta)
          <label class="block relative" for="editAuthor_{{ $column }}">
            <span class="block mb-2 label-text text-red-600 font-medium pl-4">{{ $meta['label'] }}</span>
            <textarea
              id="editAuthor_{{ $column }}"
              name="{{ $column }}"
              rows="3"
              class="w-[735px] ml-4 rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
            ></textarea>
          </label>
        @endforeach

        <div class="flex items-center gap-3 pl-4 pb-2">
          <button type="submit" class="flatGreen transition-200 text-white px-5 py-3 rounded">
            Save Changes
          </button>
          <button
            id="cancelEditAuthorModal"
            type="button"
            class="px-5 py-3 rounded border border-gray-200 text-gray-700 hover:bg-gray-100 transition-200"
          >
            Cancel
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const modal = document.getElementById('editAuthorModal');
      const form = document.getElementById('editAuthorForm');
      const nameInput = document.getElementById('editAuthorName');
      const closeBtn = modal?.querySelector('button[aria-label="Close"]');
      const cancelBtn = document.getElementById('cancelEditAuthorModal');
      const openButtons = document.querySelectorAll('.openEditAuthorModal');
      const authorForms = @json($authorForms);
      const linkColumns = @json($linkColumns);
      const oldValues = @json($editOldValues);
      const openEditAuthorId = @json($openEditAuthorId);
      const scrollKey = 'settings.doujin-authors.scrollY';

      if (!modal || !form || !nameInput) return;

      const savedScrollY = sessionStorage.getItem(scrollKey);
      if (savedScrollY !== null) {
        sessionStorage.removeItem(scrollKey);
        requestAnimationFrame(() => {
          window.scrollTo(0, Number(savedScrollY) || 0);
        });
      }

      const showModal = () => modal.classList.remove('hidden');
      const hideModal = () => modal.classList.add('hidden');

      const setLinkValue = (column, value) => {
        const field = document.getElementById(`editAuthor_${column}`);
        if (field) field.value = value || '';
      };

      const openAuthor = (authorId, useOldValues = false) => {
        const payload = authorForms[String(authorId)];
        if (!payload) return;

        form.action = payload.update_url || '';
        nameInput.value = payload.name || '';

        linkColumns.forEach((column) => {
          setLinkValue(column, payload.links?.[column] || '');
        });

        if (useOldValues) {
          if (oldValues.name !== null) nameInput.value = oldValues.name || '';

          linkColumns.forEach((column) => {
            if (
              oldValues.links
              && Object.prototype.hasOwnProperty.call(oldValues.links, column)
              && oldValues.links[column] !== null
            ) {
              setLinkValue(column, oldValues.links[column] || '');
            }
          });
        }

        showModal();
      };

      openButtons.forEach((btn) => {
        btn.addEventListener('click', () => openAuthor(btn.dataset.authorId));
      });

      closeBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        hideModal();
      });

      cancelBtn?.addEventListener('click', hideModal);

      modal.addEventListener('click', (e) => {
        if (e.target === modal) hideModal();
      });

      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
          hideModal();
        }
      });

      document.querySelectorAll('[data-delete-author-form]').forEach((deleteForm) => {
        deleteForm.addEventListener('submit', (e) => {
          const authorName = deleteForm.dataset.authorName || 'this artist';

          if (!confirm(`Delete ${authorName}? This will not delete any doujins.`)) {
            e.preventDefault();
          }
        });
      });

      document.querySelectorAll('main form, #editAuthorModal form').forEach((settingsForm) => {
        settingsForm.addEventListener('submit', () => {
          sessionStorage.setItem(scrollKey, String(window.scrollY));
        });
      });

      if (openEditAuthorId) {
        openAuthor(openEditAuthorId, true);
      }
    });
  </script>
@endsection
