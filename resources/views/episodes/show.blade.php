{{-- resources/views/episodes/show.blade.php --}}
@extends('layouts.app')

@section('content')
@php
  use Illuminate\Support\Facades\Storage;

    $title         = $item['title']['english'] ?? $item['title']['romaji'] ?? 'No Title';
    $episodeNumber = $episode->episode_number;
    $totalEps      = $item['episodes'] ?? 'N/A';
    $url       = route('media.show', ['id' => $item['id']]);

@endphp

<div class="w-full bg-black flex justify-center h-[994px] relative">
  <a href="{{ url()->previous() }}"
     class="absolute top-4 right-4 text-white text-3xl hover:text-gray-400 z-20">&times;</a>

  <div class="w-[1710px] mx-auto mt-8">
    <video
      id="player"
      class="plyr w-full h-auto aspect-video object-contain bg-black"
      playsinline
      controls
    >
      <source src="{{ Storage::disk('b2')->url($episode->file_path) }}" type="video/mp4" />
      Your browser doesn’t support HTML5 video.
    </video>
  </div>
</div>

{{-- ↓ Episode info card ↓ --}}
<div class="w-full flex justify-center mt-6 mb-6">
  <div class="max-w-[1710px] w-full rounded-lg flex overflow-hidden">
    {{-- Cover thumbnail --}}
    <div class="flex-shrink-0">
      <img
        src="{{ $item['coverImage']['extraLarge'] }}"
        alt="{{ $title }} cover"
        class="w-32 h-32 object-cover"
      />
    </div>

    {{-- Textual info --}}
    <div class="p-4 flex-col justify-start">
      {{-- Title + Episode --}}
      <h1 class="text-2xl font-bold text-red-600 truncate">
        <a class="hover:underline" href="{{ $url }}">{{ $title }}</a> <span class="font-light text-gray-600">- Episode {{ $episodeNumber }}</span>
      </h1>

      {{-- Meta line --}}
      <div class="mt-1 text-sm text-gray-600 space-x-2">
        <span>{{ $category }}</span>
        <span>&bull;</span>
        <span>{{ $totalEps }} Episodes</span>
      </div>

    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    Plyr.setup('#player', {
      controls: [
        'play-large','play','progress','current-time','duration',
        'mute','volume','settings','fullscreen'
      ],
      invertTime: false
    });
  });
</script>
@endsection
