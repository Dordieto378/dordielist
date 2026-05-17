{{-- resources/views/settings/doujin-tags.blade.php --}}
@extends('layouts.app')

@section('content')
  <div class="bg-gray-100 mb-[50px] pt-[100px]">
    <div class="max-w-[1320px] mx-auto px-4 sm:px-6 lg:px-8 flex space-x-6 text-sm">

      @include('settings.partials.sidebar')

      <main class="w-3/4">
        <div class="bg-white shadow-md rounded-lg overflow-hidden p-8">
          <div class="flex items-center justify-between gap-4">
            <h1 class="text-2xl font-bold text-red-600">Doujin Tags</h1>

            <form method="GET" action="{{ route('settings.doujin-tags') }}" class="flex items-center gap-2">
              <input
                type="text"
                name="q"
                value="{{ $query }}"
                placeholder="Search tags"
                class="w-64 rounded-md border border-gray-200 py-2 px-3 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
              />
              <button type="submit" class="px-4 py-2 rounded-md bg-red-600 text-white hover:bg-red-700">
                Search
              </button>
              @if($query !== '')
                <a href="{{ route('settings.doujin-tags') }}" class="px-4 py-2 rounded-md border border-gray-200 text-gray-700 hover:bg-gray-100">
                  Clear
                </a>
              @endif
            </form>
          </div>

          @if(session('status'))
            <div class="app-alert mt-4 mb-4 {{ session('status_color') === 'green' ? 'app-alert-success' : 'app-alert-error' }}">
              {{ session('status') }}
            </div>
          @endif

          <form method="POST" action="{{ route('settings.doujin-tags.store') }}" class="mt-6 flex items-end gap-3">
            @csrf
            <label class="flex-1" for="newTagName">
              <span class="block mb-2 text-red-600 font-medium">Add Tag</span>
              <input
                type="text"
                id="newTagName"
                name="name"
                value="{{ old('name') && !session('editing_tag_id') ? old('name') : '' }}"
                class="w-full rounded-md border border-gray-200 py-2 px-3 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                required
              />
            </label>
            <button type="submit" class="h-11 px-5 rounded-md bg-[#08875b] text-white hover:bg-emerald-700">
              Add Tag
            </button>
          </form>

          <div class="overflow-x-auto mt-8">
            <table class="min-w-full table-auto bg-white">
              <thead>
                <tr class="border-b border-gray-200">
                  <th class="px-4 py-3 font-medium text-gray-800 uppercase tracking-wider text-left">Name</th>
                  <th class="px-4 py-3 font-medium text-gray-800 uppercase tracking-wider text-left">Slug</th>
                  <th class="px-4 py-3 font-medium text-gray-800 uppercase tracking-wider text-center">Doujins</th>
                  <th class="px-4 py-3 font-medium text-gray-800 uppercase tracking-wider text-right">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-200 font-medium">
                @foreach($tags as $tag)
                  @php
                    $isEditingFailedTag = (int) session('editing_tag_id') === $tag->id;
                    $editFormId = 'editDoujinTagForm'.$tag->id;
                    $deleteFormId = 'deleteDoujinTagForm'.$tag->id;
                  @endphp
                  <tr>
                    <td class="px-4 py-4 align-middle">
                      <form id="{{ $editFormId }}" method="POST" action="{{ route('settings.doujin-tags.update', $tag) }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="q" value="{{ $query }}">
                        <input type="hidden" name="page" value="{{ request('page') }}">
                        <input
                          type="text"
                          name="name"
                          value="{{ $isEditingFailedTag ? old('name', $tag->name) : $tag->name }}"
                          class="w-full rounded-md border border-gray-200 py-2 px-3 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                          required
                        />
                      </form>
                    </td>
                    <td class="px-4 py-4 align-middle text-gray-700">
                      {{ $tag->slug ?: '-' }}
                    </td>
                    <td class="px-4 py-4 align-middle text-center text-gray-800">
                      {{ $tag->media_count }}
                    </td>
                    <td class="px-4 py-4 align-middle">
                      <div class="flex items-center justify-end gap-2">
                        <button
                          type="submit"
                          form="{{ $editFormId }}"
                          class="px-4 py-2 rounded-md bg-[#08875b] text-white hover:bg-emerald-700"
                        >
                          Save
                        </button>

                        <form id="{{ $deleteFormId }}" method="POST" action="{{ route('settings.doujin-tags.destroy', $tag) }}">
                          @csrf
                          @method('DELETE')
                          <input type="hidden" name="q" value="{{ $query }}">
                          <input type="hidden" name="page" value="{{ request('page') }}">
                          <button
                            type="submit"
                            class="px-4 py-2 rounded-md bg-red-600 text-white hover:bg-red-700"
                            onclick="return confirm('Delete this tag? It will be removed from every doujin that uses it.')"
                          >
                            Delete
                          </button>
                        </form>
                      </div>
                    </td>
                  </tr>
                @endforeach

                @if($tags->isEmpty())
                  <tr>
                    <td colspan="4" class="px-4 py-6 text-center text-gray-800">
                      No doujin tags found.
                    </td>
                  </tr>
                @endif
              </tbody>
            </table>
          </div>

          @if($tags->lastPage() > 1)
            <div class="flex items-center justify-center space-x-2 mt-6">
              <span class="text-gray-900 text-lg font-medium">Pages</span>

              @if($tags->currentPage() > 1)
                <a href="{{ $tags->url(1) }}" class="pagination-arrow mb-1">&laquo;</a>
                <a href="{{ $tags->previousPageUrl() }}" class="pagination-arrow mb-1">&lsaquo;</a>
              @endif

              @php
                $maxVisible = 7;
                $start = max(1, $tags->currentPage() - intdiv($maxVisible, 2));
                $end = min($tags->lastPage(), $start + $maxVisible - 1);

                if ($end - $start + 1 < $maxVisible) {
                  $start = max(1, $end - $maxVisible + 1);
                }
              @endphp

              <div class="flex space-x-2 text-lg">
                @for($i = $start; $i <= $end; $i++)
                  @if($i === $tags->currentPage())
                    <span class="pagination-btn pagination-active">{{ $i }}</span>
                  @else
                    <a href="{{ $tags->url($i) }}" class="pagination-btn non-selected-page-number">
                      {{ $i }}
                    </a>
                  @endif
                @endfor
              </div>

              @if($tags->currentPage() < $tags->lastPage())
                <a href="{{ $tags->nextPageUrl() }}" class="pagination-arrow mb-1">&rsaquo;</a>
                <a href="{{ $tags->url($tags->lastPage()) }}" class="pagination-arrow mb-1">&raquo;</a>
              @endif
            </div>
          @endif
        </div>
      </main>
    </div>
  </div>
@endsection
