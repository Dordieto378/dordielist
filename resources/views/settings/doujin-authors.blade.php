@extends('layouts.app')

@section('content')
    @php
        $failedArtistId = (int) session('open_edit_author_id');
        $linkValues = function ($author, string $column) use ($failedArtistId) {
            $value = $failedArtistId === $author->id && session()->hasOldInput($column)
                ? old($column)
                : $author->{$column};
            return \App\Support\DoujinAuthorLinks::urls($value) ?: [''];
        };
    @endphp

    <div id="doujinArtistsSettings" class="bg-gray-100 mb-[50px] pt-[100px]" data-failed-artist-id="{{ $failedArtistId ?: '' }}">
        <div class="max-w-[1320px] mx-auto px-4 sm:px-6 lg:px-8 flex space-x-6 text-sm">
            @include('settings.partials.sidebar')

            <main class="w-3/4">
                <div class="bg-white shadow-md rounded-lg p-6">
                    <div class="flex items-center justify-between gap-4 border-b border-gray-200 pb-4">
                        <h1 class="text-2xl font-bold text-red-600">Doujin Artists</h1>
                        <span id="doujinArtistsCount" class="text-sm font-normal text-gray-500 normal-case" aria-live="polite">{{ $authors->count() }} artists</span>
                    </div>

                    @if(session('status'))
                        <div class="app-alert mt-4 {{ session('status_color') === 'red' ? 'app-alert-error' : 'app-alert-success' }}">{{ session('status') }}</div>
                    @endif

                    <label for="doujinArtistSearch" class="mt-5 block">
                        <span class="mb-2 block text-red-600 font-medium">Search Artists</span>
                        <input id="doujinArtistSearch" type="search" autocomplete="off" placeholder="Search an artist..."
                               class="w-full rounded-md border border-gray-200 bg-gray-100 px-3 py-2 text-base font-normal text-gray-800 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600" />
                    </label>

                    <div class="mt-5 space-y-5" id="doujinArtistsList">
                        @foreach($authors as $author)
                            <form id="artist-form-{{ $author->id }}" method="POST" action="{{ route('settings.doujin-authors.update', $author) }}"
                                  data-artist-row data-artist-id="{{ $author->id }}" data-artist-name="{{ $author->name }}"
                                  class="rounded-lg border border-gray-200 p-5 normal-case">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="name" value="{{ $author->name }}">
                                <div class="mb-4 flex items-center justify-between gap-4 border-b border-gray-200 pb-3">
                                    <h2 class="break-words text-lg font-bold text-gray-800">{{ $author->name }}</h2>
                                    <span class="shrink-0 text-xs font-normal text-gray-500">{{ $author->doujin_count }} {{ (int) $author->doujin_count === 1 ? 'doujin' : 'doujins' }}</span>
                                </div>

                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    @foreach($socialPlatforms as $column => $meta)
                                        <div data-social-group>
                                            <span id="artist-{{ $author->id }}-{{ $column }}" class="mb-2 block text-red-600 font-medium">{{ $meta['label'] }}</span>
                                            <div data-social-list class="space-y-2">
                                                @foreach($linkValues($author, $column) as $url)
                                                    <div data-social-row class="flex items-center gap-2">
                                                        <input type="text" name="{{ $column }}[]" value="{{ $url }}" maxlength="2048"
                                                               aria-labelledby="artist-{{ $author->id }}-{{ $column }}" placeholder="{{ $meta['label'] }} URL"
                                                               class="w-full min-w-0 rounded-md border border-gray-200 bg-gray-100 px-3 py-2 text-base font-medium text-gray-800 normal-case focus:outline-none focus:ring-[0.2rem] focus:ring-red-600" />
                                                        <button type="button" data-add-social aria-label="Add {{ $meta['label'] }} link"
                                                                class="{{ $loop->last ? '' : 'hidden' }} shrink-0 rounded-md border border-gray-200 bg-gray-100 px-3 py-2 text-base font-bold text-red-600 hover:bg-red-600 hover:text-white">+</button>
                                                        <button type="button" data-remove-social aria-label="Remove {{ $meta['label'] }} link"
                                                                class="{{ $loop->count > 1 ? '' : 'hidden' }} shrink-0 rounded-md border border-gray-200 bg-gray-100 px-3 py-2 text-base font-bold text-gray-500 hover:bg-gray-200">&times;</button>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="mt-4 flex justify-end border-t border-gray-200 pt-4">
                                    <button type="submit" class="flatGreen rounded px-5 py-3 font-medium text-white">Save Changes</button>
                                </div>
                            </form>
                        @endforeach
                    </div>
                    <p id="doujinArtistsEmpty" class="{{ $authors->isEmpty() ? '' : 'hidden' }} py-8 text-center font-normal text-gray-500 normal-case">No doujin artists found.</p>
                </div>
            </main>
        </div>
    </div>
@endsection
