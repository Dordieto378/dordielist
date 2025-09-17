@if(count($media) > 0)
    <div id="contentContainer" class="w-[1050px] grid grid-cols-4 gap-y-2 transition-opacity duration-500 ease-in-out">
        @foreach($media as $card)
            @php
                $url   = $card['url']   ?? '#';
                $cover = $card['cover'] ?? asset('images/default.jpg');
                $title = $card['title'] ?? 'No Title';
                $nsfw  = (bool)($card['nsfw'] ?? false);
                $blur  = $nsfw && !Auth::check();
            @endphp

            <div onclick="window.location.href='{{ $url }}'" class="cursor-pointer">
                <div class="relative w-[242px] h-[339px] rounded-lg overflow-hidden shadow-lg">
                    @if($nsfw && !Auth::check())
                        <img src="{{ asset('images/18-plus.png') }}" alt="18+" class="absolute top-2 right-2 w-8 h-8 z-10">
                    @endif

                    <img src="{{ $cover }}" alt="Cover" class="w-full h-full object-cover {{ $blur ? 'filter blur-2xl' : '' }}">
                </div>
                <div class="mt-2">
                    <p class="text-red-600 font-bold">{{ \Illuminate\Support\Str::limit($title, 25) }}</p>
                </div>
            </div>
        @endforeach
    </div>
@else
    <p class="text-center text-gray-500 mt-4 font-medium">No results.</p>
@endif
