{{-- resources/views/collections/index.blade.php --}}
@extends('layouts.app')

@section('content')
    <div class="mt-6 py-[4.5rem]">
        <div class="max-w-screen-xl mx-auto flex justify-between items-center px-4">
            <h2 class="text-2xl text-red-600 mb-[1.9rem]">Collections</h2>
            <button
                id="openCreateCollection"
                type="button"
                class="flatGreen text-white px-7 py-3 rounded-[0.19rem] transition"
            >
                Create Collection
            </button>
        </div>

        <section class="mt-6 flex justify-center">
            <div id="contentContainer" class="w-full max-w-screen-xl grid grid-cols-4 gap-4">
                <div class="cursor-pointer card grid-view overflow-hidden"
                     onclick="window.location.href='{{ route('collection.show', $favorites) }}'">
                    <img
                        src="{{ $favoritesThumbnail ?? asset('images/no-image.jpg') }}"
                        alt="Favorites Cover"
                        loading="lazy"
                            class="rounded-lg shadow-lg w-[302px] max-h-[424px] h-auto object-contain"
                    />
                    <div class="py-3">
                        <p class="text-red-600 font-bold">Favorites</p>
                    </div>
                </div>

                @forelse($otherCollections as $col)
                    <div class="relative cursor-pointer overflow-hidden transition">

                        @unless($col->is_system)
                            <form
                                method="POST"
                                action="{{ route('collection.destroy', $col) }}"
                                class="absolute top-2 left-2 z-10"
                                onpointerdown="event.stopPropagation()"
                            >
                                @csrf
                                @method('DELETE')
                                <button
                                    type="submit"
                                    class="p-1 rounded-full bg-transparent hover:bg-gray-200"
                                    title="Remove {{ $col->name }}"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg"
                                         class="h-4 w-4 text-gray-600"
                                         viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd"
                                              d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                              clip-rule="evenodd"/>
                                    </svg>
                                </button>
                            </form>
                        @endunless

                        @php
                            $ci = $col->latestItem ?? null;
                            $fromMap = isset($collectionThumbnails) && is_array($collectionThumbnails)
                                       ? ($collectionThumbnails[$col->id] ?? null)
                                       : null;

                            $orig = $fromMap ?: ($ci->thumbnail_url ?? null);

                            if ($orig && \Illuminate\Support\Str::startsWith($orig, ['http://','https://','/'])) {
                              $thumbUrl = $orig;
                            } else {
                              $thumbUrl = asset($orig ?: 'images/no-image.jpg');
                            }

                            $cardHref = route('collection.show', $col);
                        @endphp

                        <div onclick="window.location.href='{{ $cardHref }}'">
                            <div class="cursor-pointer card grid-view overflow-hidden">
                                <img
                                    src="{{ $thumbUrl }}"
                                    alt="Cover for {{ $col->name }}"
                                    loading="lazy"
                                    class="rounded-lg shadow-lg w-[302px] max-h-[424px] h-auto object-contain"
                                />
                                <div class="py-3">
                                    <p class="text-red-600 font-bold">{{ $col->name }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty

                @endforelse

            </div>
        </section>
    </div>

    <div
        id="createCollectionModal"
        class="fixed inset-0 flex items-start pt-[130px] justify-center bg-black bg-opacity-50 hidden z-50"
    >
        <div
            class="relative bg-white p-4 text-left shadow-2xl
           w-[800px] h-[255px] rounded-lg space-y-6 overflow-auto"
            role="dialog"
            aria-modal="true"
        >
            <div class="flex justify-between items-start pb-4 pt-2 border-b border-gray-200">
                <h2 class="text-lg font-bold text-gray-800 pl-4">Create New Collection</h2>
                <button type="button" class="text-gray-400 hover:text-gray-900 pr-4" aria-label="Close">
                    <span class="sr-only">Close</span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div id="overlay-content" class="space-y-4">
                <form id="collectionCreateForm" class="space-y-4" method="POST" action="{{ route('collection.store') }}">
                    @csrf
                    <label class="block relative" for="name">
                        <span class="block mb-2 label-text text-red-600 font-medium pl-4">Collection Name</span>
                        <input
                            type="text" id="name" name="name"
                            class="w-[735px] ml-4 rounded-md border border-gray-200 px-3 py-2 text-base
                   bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600
                   text-gray-800 font-medium"
                            required
                        />
                        @error('name')
                        <span class="text-red-600 text-sm mt-1">{{ $message }}</span>
                        @enderror
                    </label>

                    <div class="block">
                        <button type="submit" class="flatGreen transition-200 text-white px-5 py-3 rounded ml-4">
                            Create Collection
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modal    = document.getElementById('createCollectionModal');
            const openBtn  = document.getElementById('openCreateCollection');
            const closeBtn = modal.querySelector('button[aria-label="Close"]');

            function toggleCreateModal() { modal.classList.toggle('hidden'); }

            openBtn.addEventListener('click', toggleCreateModal);
            closeBtn.addEventListener('click', (e) => { e.stopPropagation(); toggleCreateModal(); });
            modal.addEventListener('click', (e) => { if (e.target === modal) toggleCreateModal(); });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && !modal.classList.contains('hidden')) toggleCreateModal();
            });
        });
    </script>
@endsection
