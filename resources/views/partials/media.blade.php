@if(count($media) > 0)
    <div id="contentContainer" class="w-[1050px] grid grid-cols-4 gap-y-2 transition-opacity duration-500 ease-in-out">
        @foreach($media as $card)
            @php
                $url   = $card['url']   ?? '#';
                $cover = $card['cover'] ?? asset('images/default.jpg');
                $title = $card['title'] ?? 'No Title';
            @endphp

            <div class="block group">
                <a href="{{ $url }}" class="block">
                    <div class="thumb-wrapper thumb-portrait relative w-[242px] h-[339px] rounded-lg overflow-hidden shadow-lg">
                        {{-- Cover --}}
                        <img src="{{ $cover }}" alt="Cover"
                             class="thumb-img w-full h-full">
                    </div>
                </a>

                <div class="mt-2">
                    <p class="text-red-600 font-bold">
                        {{ \Illuminate\Support\Str::limit($title, 25) }}
                    </p>
                </div>
            </div>
        @endforeach
    </div>
@else
    <p class="text-center text-gray-500 mt-4 font-medium">No results.</p>
@endif
