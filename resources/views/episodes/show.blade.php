{{-- resources/views/episodes/show.blade.php --}}
@extends('layouts.app')

@section('content')
    @php
        use Illuminate\Support\Facades\Storage;
        use App\Models\Episode;

        $title         = $item['title']['english'] ?? $item['title']['romaji'] ?? 'No Title';
        $episodeNumber = $episode->episode_number;
        // Count locally if API field is missing
        $totalEps      = $item['episodes'] ?? Episode::where('media_fk', $item['id'])->count();
        $mediaUrl      = route('media.show', $item['id']);

        // Derive a mime type from extension (optional but nicer for <source>):
        $ext = strtolower(pathinfo($episode->file_path, PATHINFO_EXTENSION));
        $mime = $ext === 'webm' ? 'video/webm' : 'video/mp4';

        // Build cover URL safely
        $cover = $item['coverImage']['extraLarge'] ?? asset('images/no-image.jpg');
    @endphp

    <div class="w-full bg-black flex justify-center min-h-[70vh] relative">
        <a href="{{ url()->previous() }}"
           class="absolute top-4 right-4 text-white text-3xl hover:text-gray-400 z-20">&times;</a>

        <div class="w-[1710px] max-w-full mx-auto mt-8 px-4">
            <video
                id="player"
                class="plyr w-full h-auto aspect-video object-contain bg-black"
                playsinline
                controls
            >
                <source src="{{ Storage::disk('public')->url($episode->file_path) }}#t=0.1" type="{{ $mime }}">
                Your browser doesn’t support HTML5 video.
            </video>
        </div>
    </div>

    {{-- Episode info card --}}
    <div class="w-full flex justify-center mt-6 mb-6 px-4">
        <div class="max-w-[1710px] w-full rounded-lg flex overflow-hidden">
            {{-- Cover thumbnail --}}
            <div class="flex-shrink-0">
                <img
                    src="{{ $cover }}"
                    alt="{{ $title }} cover"
                    class="w-32 h-32 object-cover rounded"
                />
            </div>

            {{-- Textual info --}}
            <div class="p-4 flex-col justify-start">
                <h1 class="text-2xl font-bold text-red-600 truncate">
                    <a class="hover:underline" href="{{ $mediaUrl }}">{{ $title }}</a>
                    <span class="font-light text-gray-600">- Episode {{ $episodeNumber }}</span>
                </h1>

                <div class="mt-1 text-sm text-gray-600 space-x-2">
                    <span>{{ $category }}</span>
                    <span>&bull;</span>
                    <span>{{ $totalEps ?: 'N/A' }} Episodes</span>
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
