@extends('layouts.app')

@section('content')
<div class="mt-6 py-[4.5rem]">
    <div class="max-w-screen-xl mx-auto flex justify-between items-center px-4">
        <h2 class="text-2xl text-red-600 mb-[1.9rem]">
            {{ $collection->name }}
        </h2>

        @if(!$collection->is_system)
            <div class="flex items-center gap-2">
                <a
                    href="{{ route('collection.random', $collection) }}"
                    target="_blank" rel="noopener"
                    class="bg-flatRed hover:bg-red-600 text-white px-7 py-3 rounded-[0.19rem] transition"
                >
                    Random
                </a>

                <button
                    id="openRenameCollection"
                    type="button"
                    class="flatGreen text-white px-7 py-3 rounded-[0.19rem] transition"
                >
                    Edit Collection
                </button>
            </div>
        @endif
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
                            $link = route('doujins.show', ['media' => $ci->item_id]);
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
                    <a href="{{ $link }}" class="block card overflow-hidden">
                        <div class="thumb-wrapper thumb-portrait rounded-lg shadow-lg w-[302px] h-[424px] overflow-hidden">
                            <img
                                src="{{ $thumbUrl }}"
                                alt="{{ $ci->title ?? 'Cover' }}"
                                loading="lazy"
                                class="thumb-img w-full h-full"
                            >
                        </div>
                        <div class="py-3">
                            <p class="text-red-600 font-bold hover:underline">
                                {{ \Illuminate\Support\Str::limit($ci->title ?? 'Untitled', 25) }}
                            </p>
                            @if($typeLabel)
                                <p class="text-gray-600 font-medium">{{ $typeLabel }}</p>
                            @endif
                        </div>
                    </a>

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
@if(!$collection->is_system)
    <div
        id="renameCollectionModal"
        class="fixed inset-0 flex items-start pt-[130px] justify-center bg-black bg-opacity-50 hidden z-50"
    >
        <div
            class="relative bg-white p-4 text-left shadow-2xl
               w-[800px] h-[255px] rounded-lg space-y-6 overflow-auto"
            role="dialog"
            aria-modal="true"
        >
            <div class="flex justify-between items-start pb-4 pt-2 border-b border-gray-200">
                <h2 class="text-lg font-bold text-gray-800 pl-4">Rename Collection</h2>
                <button type="button" class="text-gray-400 hover:text-gray-900 pr-4" aria-label="Close">
                    <span class="sr-only">Close</span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="space-y-4">
                <form method="POST" action="{{ route('collection.rename', $collection) }}" class="space-y-4">
                    @csrf
                    @method('PATCH')

                    <label class="block relative" for="rename_name">
                        <span class="block mb-2 label-text text-red-600 font-medium pl-4">New name</span>
                        <input
                            type="text"
                            id="rename_name"
                            name="name"
                            value="{{ old('name', $collection->name) }}"
                            class="w-[735px] ml-4 rounded-md border border-gray-200 px-3 py-2 text-base
                               bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600
                               text-gray-800 font-medium"
                            required
                        />
                        @error('name')
                        <span class="text-red-600 text-sm mt-1 ml-4">{{ $message }}</span>
                        @enderror
                    </label>

                    <div class="block">
                        <button type="submit" class="flatGreen transition-200 text-white px-5 py-3 rounded ml-4">
                            Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const modal    = document.getElementById('renameCollectionModal');
        const openBtn  = document.getElementById('openRenameCollection');
        const closeBtn = modal?.querySelector('button[aria-label="Close"]');

        function toggleModal() { modal.classList.toggle('hidden'); }

        openBtn?.addEventListener('click', toggleModal);
        closeBtn?.addEventListener('click', (e) => { e.stopPropagation(); toggleModal(); });
        modal?.addEventListener('click', (e) => { if (e.target === modal) toggleModal(); });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) toggleModal();
        });
    });
</script>
@endsection
