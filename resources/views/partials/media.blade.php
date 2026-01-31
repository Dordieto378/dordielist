@if(count($media) > 0)
    <div id="contentContainer" class="w-[1050px] grid grid-cols-4 gap-y-2 transition-opacity duration-500 ease-in-out">
        @foreach($media as $card)
            @php
                $url   = $card['url']   ?? '#';
                $cover = $card['cover'] ?? asset('images/default.jpg');
                $title = $card['title'] ?? 'No Title';
            @endphp

            <a href="{{ $url }}" class="block group">
                <div class="relative w-[242px] max-h-[339px] rounded-lg overflow-hidden shadow-lg">
                    {{-- Cover --}}
                    <img src="{{ $cover }}" alt="Cover"
                         class="w-full h-auto max-h-[339px] object-contain">
                </div>

                <div class="mt-2">
                    <p class="text-red-600 font-bold group-hover:underline">
                        {{ \Illuminate\Support\Str::limit($title, 25) }}
                    </p>
                </div>
            </a>
        @endforeach
    </div>
@else
    <p class="text-center text-gray-500 mt-4 font-medium">No results.</p>
@endif
