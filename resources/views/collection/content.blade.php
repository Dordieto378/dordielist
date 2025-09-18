@extends('layouts.app')

@section('content')
<div class="mt-6 py-[4.5rem]">
    <div class="max-w-screen-xl mx-auto px-4">
        <h2 class="text-2xl text-red-600 mb-[1.9rem]">
            {{ $collection->name }}
        </h2>
    </div>

    <section class="mt-6 flex justify-center">
        <div class="w-full max-w-screen-xl grid grid-cols-4 gap-4">
            @forelse ($items as $ci)
                @php
                    switch ($ci->item_type) {
                        case 'visual-novel':
                            $link = route('vn.show', 'v'.$ci->item_id);
                            break;
                        case 'doujins':
                            $link = route('media.doujin', $ci->item_id);
                            break;
                        default:
                            $link = route('media.show', $ci->item_id);
                            break;
                    }

                    $orig = $ci->thumbnail_url;

                    if ($orig && \Illuminate\Support\Str::startsWith($orig, ['http://','https://','/'])) {
                        $thumbUrl = $orig;
                    } else {
                        $thumbUrl = asset($orig ?: 'images/no-image.jpg');
                    }

                    $typeMap = [
                        'visual-novel' => 'Visual Novel',
                        'doujins'      => 'Doujin',
                        'animes'       => 'Anime',
                        'mangas'       => 'Manga',
                        'manwhas'      => 'Manwha',
                        'hentais'      => 'Hentai',
                    ];
                    $typeLabel = $typeMap[$ci->item_type] ?? ucfirst($ci->item_type);
                @endphp

                <div class="relative group">
                    <div class="cursor-pointer card overflow-hidden" onclick="location='{{ $link }}'">
                        <img
                            src="{{ $thumbUrl }}"
                            alt="{{ $ci->title ?? 'Cover' }}"
                            loading="lazy"
                            class="rounded-lg shadow-lg w-[302px] h-[424px] object-cover"
                        >
                        <div class="py-3">
                            <p class="text-red-600 font-bold">
                                {{ \Illuminate\Support\Str::limit($ci->title ?? 'Untitled', 25) }}
                            </p>
                            @if($typeLabel)
                                <p class="text-gray-600 font-medium">{{ $typeLabel }}</p>
                            @endif
                        </div>
                    </div>

                    <form method="POST"
                          action="{{ route('collection.item.remove', $collection) }}"
                          class="absolute top-2 left-2 z-20"
                          onpointerdown="event.stopPropagation()">
                        @csrf
                        <input type="hidden" name="item_type" value="{{ $ci->item_type }}">
                        <input type="hidden" name="item_id"   value="{{ $ci->item_id   }}">
                        <button type="submit"
                                class="p-1 rounded-full bg-transparent hover:bg-gray-200"
                                title="Remove {{ \Illuminate\Support\Str::limit($ci->title ?? 'item', 25) }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-gray-600" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10
              8.586l4.293-4.293a1 1 0 111.414
              1.414L11.414 10l4.293 4.293a1 1
              0 01-1.414 1.414L10 11.414l-4.293
              4.293a1 1 0 01-1.414-1.414L8.586
              10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                            </svg>
                        </button>
                    </form>
                </div>
            @empty
                <div class="w-[1270px] bg-white border border-gray-200 rounded shadow-sm
                flex items-center justify-center py-8">
                    <p class="text-gray-700 text-md font-medium">This collection is empty.</p>
                </div>
            @endforelse
        </div>
    </section>
</div>
@endsection
