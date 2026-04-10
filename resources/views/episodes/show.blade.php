{{-- resources/views/episodes/show.blade.php --}}
@extends('layouts.app')

@section('content')
    @php
        use Illuminate\Support\Facades\Storage;

        $title = $item['title']['english'] ?? $item['title']['romaji'] ?? $item['title']['native'] ?? 'No Title';
        $episodeNumber = $episode->episode_number;
        $mediaUrl = route('media.show', $item['id']);

        $src = asset('storage/'.$episode->file_path);
        $releaseDate = !empty($item['releaseDate'])
            ? \Carbon\Carbon::parse($item['releaseDate'])->format('M j, Y')
            : (!empty($item['startDate']['year']) ? (string) $item['startDate']['year'] : null);
        $description = (string) ($item['description'] ?? '');
        $visibleEpisodes = ($episodes ?? collect())->values();
    @endphp

    <div class="w-full bg-black flex justify-center min-h-[70vh] relative">
        <a href="{{ url()->previous() }}"
           class="absolute top-4 right-4 text-white text-3xl hover:text-gray-400 z-20">&times;</a>

        <div class="w-[1710px] max-w-full mx-auto mt-8 px-4">
            <video id="player"
                   class="w-full h-auto aspect-video object-contain bg-black"
                   playsinline controls preload="metadata">
                <source src="{{ $src }}">
                Your browser doesn’t support HTML5 video.
            </video>
        </div>
    </div>

    <div class="w-full flex justify-center mt-6 mb-6 px-4">
        <div style="max-width:1280px;width:100%;margin:0 auto;display:flex;align-items:flex-start;gap:20px;">
            <div style="flex:1 1 auto;min-width:0;" class="rounded-lg overflow-hidden">
                <div class="px-1 py-2 text-gray-900 font-medium">
                    <h1 class="text-xl font-semibold text-red-600 leading-tight">
                        <a class="hover:underline" href="{{ $mediaUrl }}">{{ $title }}</a>
                    </h1>
                    <div class="mt-1 text-lg font-semibold text-black">
                        E{{ $episodeNumber }}
                    </div>
                    @if($releaseDate)
                        <div class="mt-1 text-sm font-normal text-black">
                            Released on {{ $releaseDate }}
                        </div>
                    @endif
                    @if($description !== '')
                        <p class="text-sm mb-2 mt-2">
                            {!! nl2br(e($description)) !!}
                        </p>
                    @endif
                </div>
            </div>

            @if($visibleEpisodes->isNotEmpty())
                <aside style="width:320px;flex:0 0 320px;">
                    <div class="max-h-[460px] overflow-y-auto space-y-1.5 pr-1">
                        @foreach($visibleEpisodes as $index => $ep)
                            @php
                                $isCurrentEpisode = (int) $ep->episode_number === (int) $episodeNumber;
                                $thumbUrl = !empty($ep->thumbnail_path)
                                    ? Storage::url($ep->thumbnail_path)
                                    : asset('images/no-image.jpg');
                                $rowStyle = 'display:flex;align-items:flex-start;gap:12px;text-decoration:none;';
                                if ($index >= 12) {
                                    $rowStyle .= 'display:none;';
                                }
                            @endphp
                            <a href="{{ route('episodes.show', ['media' => $item['id'], 'episode' => $ep->episode_number]) }}"
                               class="episode-sidebar-item rounded-sm px-2 py-1 transition {{ $isCurrentEpisode ? 'bg-gray-300' : 'hover:bg-gray-50' }}"
                               @if($isCurrentEpisode) aria-current="page" @endif
                               style="{{ $rowStyle }}">
                                <img
                                    style="width:160px;height:90px;flex:0 0 160px;display:block;object-fit:cover;border-radius:2px;"
                                    src="{{ $thumbUrl }}"
                                    alt="Episode {{ $ep->episode_number }} thumbnail"
                                    loading="lazy"
                                >
                                <span style="display:block;flex:0 0 auto;min-width:88px;color:#111827;font-size:14px;font-weight:{{ $isCurrentEpisode ? '700' : '600' }};line-height:1.2;white-space:nowrap;">
                                    E{{ $ep->episode_number }}
                                </span>
                            </a>
                        @endforeach
                        @if($visibleEpisodes->count() > 12)
                            <div style="display:flex;align-items:center;justify-content:center;gap:16px;padding:8px 8px 0 8px;">
                                <button
                                    type="button"
                                    id="show-more-episodes"
                                    class="text-sm"
                                    style="color:#111827;text-align:center;font-weight:400;"
                                >
                                    Show more
                                </button>
                                <button
                                    type="button"
                                    id="show-less-episodes"
                                    class="text-sm"
                                    style="color:#111827;text-align:center;font-weight:400;display:none;"
                                >
                                    Show less
                                </button>
                            </div>
                        @endif
                    </div>
                </aside>
            @endif
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            Plyr.setup('#player', {
                controls: ['play-large','play','progress','current-time','duration','mute','volume','settings','fullscreen'],
                invertTime: false
            });

            const showMoreButton = document.getElementById('show-more-episodes');
            const showLessButton = document.getElementById('show-less-episodes');

            const updateEpisodeToggleState = () => {
                const items = Array.from(document.querySelectorAll('.episode-sidebar-item'));
                const hiddenEpisodes = items.filter((item) => item.style.display === 'none');
                const visibleCount = items.length - hiddenEpisodes.length;

                if (showMoreButton) {
                    showMoreButton.style.display = hiddenEpisodes.length > 0 ? 'inline-block' : 'none';
                }

                if (showLessButton) {
                    showLessButton.style.display = visibleCount > 12 ? 'inline-block' : 'none';
                }
            };

            if (showMoreButton) {
                showMoreButton.addEventListener('click', () => {
                    const hiddenEpisodes = Array.from(document.querySelectorAll('.episode-sidebar-item'))
                        .filter((item) => item.style.display === 'none');

                    hiddenEpisodes.forEach((item) => {
                        item.style.display = 'flex';
                    });

                    updateEpisodeToggleState();
                });
            }

            if (showLessButton) {
                showLessButton.addEventListener('click', () => {
                    const items = Array.from(document.querySelectorAll('.episode-sidebar-item'));

                    items.forEach((item, index) => {
                        item.style.display = index < 12 ? 'flex' : 'none';
                    });

                    updateEpisodeToggleState();
                });
            }

            updateEpisodeToggleState();
        });
    </script>
@endsection
