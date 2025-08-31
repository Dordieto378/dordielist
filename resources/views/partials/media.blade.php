{{-- resources/views/partials/media.blade.php --}}

@if(count($media) > 0)
  <div id="contentContainer" class="w-[1050px] grid grid-cols-4 gap-y-2 transition-opacity duration-500 ease-in-out">
    @foreach($media as $entry)
      @php
        // 1) If $entry is actually a Doujin model (from our database), handle it:
        if ($entry instanceof \App\Models\Doujin) {
            $doujinName = $entry->doujin_name;
            $rawPath    = $entry->cover_url; 
            $isPlaceholder = str_starts_with($rawPath, 'images/');

            if ($isPlaceholder) {
                // Use the local placeholder
                $coverUrl = asset($rawPath);
            } else {
                // Build the B2 URL for a real doujin cover
                $coverUrl = \Illuminate\Support\Facades\Storage::disk('b2')->url($rawPath);
            }

            $isNsfw    = true;
            $fullTitle = $doujinName;
            $url       = route('media.doujin', ['doujin' => $entry->id]);
        }
        // 2) Else if it’s an AniList‐style array (your existing code):
        elseif (isset($entry['media'])) {
            $item      = $entry['media'];
            $coverUrl  = $item['coverImage']['extraLarge'] ?? asset('images/default.jpg');
            $fullTitle = $item['title']['english'] ?? $item['title']['romaji'] ?? 'No Title';
            $url       = route('media.show', ['id' => $item['id']]);
            $isNsfw    = $item['isAdult'] ?? false;
        }
        // 3) Else it must be VNDB‐style:
        else {
            $item      = $entry;
            $coverUrl  = $item['image']['url'] ?? asset('images/default.jpg');
            $fullTitle = is_array($item['title'])
                          ? ($item['title']['english'] ?? $item['title']['romaji'] ?? 'No Title')
                          : ($item['title'] ?? 'No Title');
            $url       = route('vn.show', ['id' => $item['id']]);
            $isNsfw    = ! ($item['hasNoSexualContent'] ?? false);
        }

        $shouldBlur = $isNsfw && ! Auth::check();
      @endphp

        <div onclick="window.location.href='{{ $url }}'" class="cursor-pointer">
          <div class="relative w-[242px] h-[339px] rounded-lg overflow-hidden shadow-lg">
            @if($isNsfw && ! Auth::check())
              <img src="{{ asset('images/18-plus.png') }}"
                  alt="18+"
                  class="absolute top-2 right-2 w-8 h-8 z-10">
            @endif

            {{-- Show the B2‐hosted cover, blurred if NSFW and user not logged in --}}
            <img src="{{ $coverUrl }}"
                alt="Cover Image"
                class="w-full h-full object-cover {{ ($isNsfw && ! Auth::check()) ? 'filter blur-2xl' : '' }}">
          </div>

          <div class="mt-2">
            <p class="text-red-600 font-bold">{{ \Illuminate\Support\Str::limit($fullTitle, 25) }}</p>
          </div>
        </div>
    @endforeach
  </div>
@else
  <p class="text-center text-gray-500 mt-4 font-medium">No results.</p>
@endif
