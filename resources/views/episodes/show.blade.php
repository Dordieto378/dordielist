{{-- resources/views/episodes/show.blade.php --}}
@extends('layouts.app')

@section('content')
    @php
        use App\Models\Episode;

        $title         = $item['title']['english'] ?? $item['title']['romaji'] ?? $item['title']['native'] ?? 'No Title';
        $episodeNumber = $episode->episode_number;
        $totalEps      = $item['episodes'] ?? Episode::where('media_fk', $item['id'])->count();
        $mediaUrl      = route('media.show', $item['id']);
        $categoryLabel = match (strtolower((string) $category)) {
            'animes'  => 'Anime',
            'hentais' => 'Hentai',
            'mangas'  => 'Manga',
            'manwhas' => 'Manwha',
            'doujins' => 'Doujin',
            default   => ucfirst(rtrim((string) $category, 's')),
        };

        $src  = asset('storage/'.$episode->file_path);
        $ext  = strtolower(pathinfo($episode->file_path, PATHINFO_EXTENSION));

        $cover = $item['coverImage']['extraLarge'] ?? asset('images/no-image.jpg');
    @endphp

    <div class="w-full bg-black flex justify-center min-h-[70vh] relative">
        <a href="{{ url()->previous() }}"
           class="absolute top-4 right-4 text-white text-3xl hover:text-gray-400 z-20">&times;</a>

        <div class="w-[1710px] max-w-full mx-auto mt-8 px-4">
            <video id="player"
                   class=" w-full h-auto aspect-video object-contain bg-black"
                   playsinline controls preload="metadata">
                <source src="{{ $src }}">
                Your browser doesn’t support HTML5 video.
            </video>
        </div>
    </div>

    {{-- Episode info --}}
    <div class="w-full flex justify-center mt-6 mb-6 px-4">
        <div class="max-w-[1710px] w-full rounded-lg flex overflow-hidden">
            <div class="flex-shrink-0">
                <img src="{{ $cover }}" alt="{{ $title }} cover" class="w-32 h-32 object-cover rounded" />
            </div>
            <div class="p-4 flex-col justify-start">
                <h1 class="text-2xl font-bold text-red-600 truncate">
                    <a class="hover:underline" href="{{ $mediaUrl }}">{{ $title }}</a>
                    <span class="font-light text-gray-600">- {{ $episodeNumber }}</span>
                </h1>
                <div class="mt-1 text-sm font-medium text-gray-600 space-x-2">
                    <span>{{ $categoryLabel }}</span>
                    <span>&bull;</span>
                    <span>{{ $totalEps ?: 'N/A' }} Episodes</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            Plyr.setup('#player', {
                controls: ['play-large','play','progress','current-time','duration','mute','volume','settings','fullscreen'],
                invertTime: false
            });
        });
    </script>
@endsection
