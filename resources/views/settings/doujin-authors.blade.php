{{-- resources/views/settings/doujin-authors.blade.php --}}
@extends('layouts.app')

@section('content')
  @php
    $failedAuthorId = (string) session('open_edit_author_id');
    $fieldValue = function ($author, string $column) use ($failedAuthorId) {
      if ($failedAuthorId === (string) $author->id && old($column) !== null) {
        return old($column);
      }

      return (string) ($author->{$column} ?? '');
    };
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
              <form
                method="POST"
                action="{{ route('settings.doujin-authors.update', ['author' => $author->id]) }}"
                class="grid gap-5 py-5 first:pt-0 last:pb-0 lg:grid-cols-[minmax(180px,260px)_1fr_auto] lg:items-start"
              >
                @csrf
                @method('PUT')
                <input type="hidden" name="name" value="{{ $fieldValue($author, 'name') ?: $author->name }}">

                <div class="min-w-0">
                  <h2 class="break-words text-base font-bold text-gray-900">{{ $author->name }}</h2>
                  <div class="mt-1 text-xs font-semibold uppercase text-gray-500">
                    {{ $author->doujin_count }} doujin(s)
                  </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                  @foreach($socialPlatforms as $column => $meta)
                    <label class="block" for="author_{{ $author->id }}_{{ $column }}">
                      <span class="mb-1 block text-xs font-bold uppercase text-red-600">{{ $meta['label'] }}</span>
                      <textarea
                        id="author_{{ $author->id }}_{{ $column }}"
                        name="{{ $column }}"
                        rows="2"
                        class="min-h-[64px] w-full resize-y rounded-md border border-gray-200 bg-gray-100 px-3 py-2 text-sm font-medium text-gray-800 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600"
                        placeholder="{{ $meta['label'] }} URL"
                      >{{ $fieldValue($author, $column) }}</textarea>
                    </label>
                  @endforeach
                </div>

                <div class="flex lg:justify-end">
                  <button type="submit" class="flatGreen h-10 px-5 rounded text-sm text-white">
                    Save
                  </button>
                </div>
              </form>
            @empty
              <div class="py-8 text-center text-gray-500">
                No doujin artists without socials found.
              </div>
            @endforelse
          </div>
        </div>
      </main>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const scrollKey = 'settings.doujin-authors.scrollY';
      const savedScrollY = sessionStorage.getItem(scrollKey);

      if (savedScrollY !== null) {
        sessionStorage.removeItem(scrollKey);
        requestAnimationFrame(() => {
          window.scrollTo(0, Number(savedScrollY) || 0);
        });
      }

      document.querySelectorAll('main form').forEach((settingsForm) => {
        settingsForm.addEventListener('submit', () => {
          sessionStorage.setItem(scrollKey, String(window.scrollY));
        });
      });
    });
  </script>
@endsection
