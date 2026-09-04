@extends('layouts.app')

@section('content')
@php
use Illuminate\Support\Str;
use App\Support\DoujinAuthorLinks;
$isViewer = optional(auth()->user()?->role)->role === 'Viewer';
$doujinAuthorLinkValues = fn (string $field, mixed $default = null) => DoujinAuthorLinks::urls(old($field, $default)) ?: [''];

if (!function_exists('shortTitle')) {
    function shortTitle($title, $maxLen = 25) {
        if (strlen($title) <= $maxLen) {
            return $title;
        }
        return substr($title, 0, $maxLen - 1) . '…';
    }
}
@endphp
<script>
  const categorySlug = @json($categorySlug ?? Str::slug($category));
</script>
<div class="mt-6 ml-4 flex flex-col items-center py-[4.5rem]">
    <div class="w-[1320px] flex justify-between items-center">
        @if($category === 'VISUAL-NOVEL')
            <h2 class="text-2xl text-red-600 mb-[1.5rem]">VISUAL NOVELS</h2>
        @else
            <h2 class="text-2xl text-red-600 mb-[1.5rem]">{{ $category }}</h2>
        @endif
    </div>
    <!-- Category Layout -->
    <div class="flex w-[1400px] ml-[48px]">
        <!-- Sidebar for Filters -->
        <aside class="w-[265px] bg-gray-100 p-4 mt-[5px]">
            @if(strtoupper($category) === 'DOUJINS')
                <form method="GET"
                        action="{{ route('category', ['category' => $categorySlug ?? Str::slug($category)]) }}"
                        onsubmit="event.preventDefault(); toggleSpinner(true); setTimeout(() => this.submit(), 100)">
                    <div class="relative mb-4">
                        <label class="block text-sm font-medium text-gray-900 mb-2">TITLE</label>
                        <select name="name_order"
                                onchange="debouncedRedirectWithFilters()"
                                class="appearance-none w-full px-3 py-2 border rounded-sm focus:border-red-600
                                    focus:outline-none focus:ring-2 focus:ring-red-600 h-[2.5rem] text-gray-900 font-medium">
                            <option value="none" {{ ($nameOrder ?? 'none') === 'none' ? 'selected' : '' }}>None</option>
                            <option value="az"   {{ ($nameOrder ?? '') === 'az'   ? 'selected' : '' }}>A–Z</option>
                            <option value="za"   {{ ($nameOrder ?? '') === 'za'   ? 'selected' : '' }}>Z–A</option>
                        </select>
                        <svg class="pointer-events-none absolute right-3 top-1/2 transform -translate-y-1/2 h-3 w-3 text-gray-400 mt-3.5"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>

                    <div class="relative mb-4">
                        <label class="block text-sm font-medium text-gray-900 mb-2">AUTHOR</label>

                        <!-- Button -->
                        <button id="dropdownButtonAuthor" type="button"
                                class="w-full min-h-[2.5rem] px-3 py-2 border rounded-sm bg-white text-left flex flex-wrap items-center gap-2">
                            <div id="selectedAuthor" class="flex flex-wrap gap-2 flex-1">
                                @forelse ($selectedAuthors as $auth)
                                    <span class="px-3 py-2 rounded-[0.2rem] bg-gray-200 text-gray-900 text-sm flex items-center">
                                        {{ $auth }}
                                        <span
                                            role="button" tabindex="0"
                                            data-remove-dropdown-name="{{ $auth }}"
                                            data-remove-dropdown-type="Author"
                                            class="ml-2 cursor-pointer text-gray-500 hover:text-gray-800 select-none"
                                        >
                                            ×
                                        </span>
                                    </span>
                                @empty
                                    <span class="text-gray-900 font-medium text-sm">Select Author</span>
                                @endforelse
                            </div>
                            <svg class="pointer-events-none h-3 w-3 text-gray-400"
                                xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                        </button>

                        <!-- Menu -->
                        <div id="dropdownMenuAuthor"
                            class="absolute left-0 w-full bg-white border rounded-sm shadow-lg hidden z-10 max-h-[400px] overflow-y-auto">
                            <ul>
                                @foreach ($allAuthors as $author)
                                    <li class="px-1.5 py-[1px] cursor-pointer text-gray-900 font-medium text-sm bg-white"
                                        data-dropdown-name="{{ $author }}" data-dropdown-type="Author">
                                        <span class="block w-full h-full px-3 py-2 rounded-[0.2rem] hover:bg-red-600 hover:text-white hover:font-semibold">
                                            {{ $author }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                            </div>
                        </div>

                    @include('category._collection-filter')
                </form>

                <div class="mt-6">
                    @unless($isViewer)
                    <button type="button"
                            id="openAddDoujinModal"
                            class="inline-block w-full px-4 py-2 flatGreen text-white rounded-[0.19rem] mt-2 hover:bg-emerald-700 text-center">
                        Add Doujin ZIP
                    </button>
                    @endunless
                </div>
            @elseif(strtoupper($category) === 'VISUAL-NOVEL')
                <form method="GET"
                    action="{{ route('category', [
                        'category'   => $categorySlug ?? Str::slug($category),
                        'listFilter' => $listFilter
                    ]) }}">
                    <div class="relative mb-4">
                        <label class="block text-sm font-medium text-gray-900 mb-2">LIST</label>
                        <select name="list_filter" onchange="redirectWithFilters()"
                                class="appearance-none w-full px-3 py-2 border rounded-sm focus:border-red-600 focus:outline-none focus:ring-2 focus:ring-red-600 h-[2.5rem] text-gray-900 font-medium">
                                @php
                                // include “all” as the very first option
                                $options = ['all','playing','finished','stalled','dropped','wishlist'];
                                @endphp

                                @foreach($options as $opt)
                                <option value="{{ $opt }}"
                                    {{ (isset($listFilter) && strtolower($listFilter)===$opt) ? 'selected' : '' }}>
                                    {{ ucfirst($opt) }}
                                </option>
                                @endforeach
                        </select>
                        <svg class="pointer-events-none absolute right-3 top-1/2 transform -translate-y-1/2 h-3 w-3 text-gray-400 mt-3.5"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>

                    <div class="relative mb-4">
                        <label class="block text-sm font-medium text-gray-900 mb-2">TITLE</label>
                        <select name="title_order" onchange="redirectWithFilters()"
                                class="appearance-none w-full px-3 py-2 border rounded-sm focus:border-red-600
                                        focus:outline-none focus:ring-2 focus:ring-red-600 h-[2.5rem] text-gray-900 font-medium">
                            <option value="none" {{ ($titleOrder ?? 'none')=='none' ? 'selected':'' }}>None</option>
                            <option value="az"   {{ ($titleOrder ?? '')=='az'   ? 'selected':'' }}>A–Z</option>
                            <option value="za"   {{ ($titleOrder ?? '')=='za'   ? 'selected':'' }}>Z–A</option>
                        </select>
                        <svg class="pointer-events-none absolute right-3 top-1/2 transform -translate-y-1/2 h-3 w-3 text-gray-400 mt-3.5"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>

                    <div class="relative mb-4">
                        <label class="block text-sm font-medium text-gray-900 mb-2">SCORE</label>
                        <select name="score_order" onchange="redirectWithFilters()"
                                class="appearance-none w-full px-3 py-2 border rounded-sm focus:border-red-600 focus:outline-none focus:ring-2 focus:ring-red-600 h-[2.5rem] text-gray-900 font-medium">
                            <option value="none" {{ ($scoreOrder ?? 'none')=='none' ? 'selected':'' }}>None</option>
                            <option value="avg_desc" {{ ($scoreOrder ?? '')=='avg_desc' ? 'selected':'' }}>Average (High to Low)</option>
                            <option value="avg_asc" {{ ($scoreOrder ?? '')=='avg_asc' ? 'selected':'' }}>Average (Low to High)</option>
                            <option value="personal_desc" {{ ($scoreOrder ?? '')=='personal_desc' ? 'selected':'' }}>Personal (High to Low)</option>
                            <option value="personal_asc" {{ ($scoreOrder ?? '')=='personal_asc' ? 'selected':'' }}>Personal (Low to High)</option>
                        </select>
                        <svg class="pointer-events-none absolute right-3 top-1/2 transform -translate-y-1/2 h-3 w-3 text-gray-400 mt-3.5"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>
                    <div class="relative mb-4">
                        <label class="block text-sm font-medium text-gray-900 mb-2">RELEASE</label>
                        <select name="year_order" onchange="redirectWithFilters()"
                            class="appearance-none w-full px-3 py-2 border rounded-sm focus:border-red-600 focus:outline-none focus:ring-2 focus:ring-red-600 h-[2.5rem] text-gray-900 font-medium">
                            <option value="none" {{ ($yearOrder ?? 'none')=='none' ? 'selected':'' }}>None</option>
                            <option value="year_desc" {{ ($yearOrder ?? '')=='year_desc' ? 'selected':'' }}>New to Old</option>
                            <option value="year_asc"  {{ ($yearOrder ?? '')=='year_asc'  ? 'selected':'' }}>Old to New</option>
                        </select>
                        </select>
                        <svg class="pointer-events-none absolute right-3 top-1/2 transform -translate-y-1/2 h-3 w-3 text-gray-400 mt-3.5"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>
                    <div class="relative mb-4">
                    <label class="block text-sm font-medium text-gray-900 mb-2">TAG</label>

                    <button id="dropdownButtonTags" type="button"
                        class="w-full min-h-[2.5rem] px-3 py-2 border rounded-sm bg-white text-left flex flex-wrap items-center gap-2">
                        <div id="selectedTags" class="flex flex-wrap gap-2 flex-1">
                        @if(empty($selectedTags))
                            <span class="text-gray-900 font-medium text-sm">Select Tags</span>
                        @else
                            @foreach($selectedTags as $tag)
                            <span class="px-3 py-2 rounded-[0.2rem] bg-gray-200 text-gray-900 text-sm flex items-center transition-all duration-200 ease-in-out">
                                {{ $tag }}
                                <span role="button" tabindex="0"
                                    data-remove-dropdown-name="{{ $tag }}"
                                    data-remove-dropdown-type="Tags"
                                    class="ml-2 cursor-pointer text-gray-500 hover:text-gray-800 select-none">
                                    ×
                                </span>
                            </span>
                            @endforeach
                        @endif
                        </div>
                        <svg class="pointer-events-none h-3 w-3 text-gray-400" xmlns="http://www.w3.org/2000/svg"
                            fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                    </button>

                    <div id="dropdownMenuTags"
                        class="absolute left-0 w-full bg-white border rounded-sm shadow-lg hidden z-10 max-h-[400px] overflow-y-auto">
                        <ul>
                        @foreach($allTags as $tagName)
                            <li class="px-1.5 py-[1px] cursor-pointer text-gray-900 font-medium text-sm transition-all duration-200 ease-in-out bg-white"
                                data-dropdown-name="{{ $tagName }}" data-dropdown-type="Tags">
                            <span class="block w-full h-full px-3 py-2 rounded-[0.2rem] transition-all duration-200 ease-in-out hover:bg-red-600 hover:text-white hover:font-semibold">
                                {{ $tagName }}
                            </span>
                            </li>
                        @endforeach
                        </ul>
                    </div>
                    </div>

                    <div class="relative mb-4">
                    <label class="block text-sm font-medium text-gray-900 mb-2">LANGUAGE</label>
                    <ul class="space-y-2">
                        @foreach($allLanguages as $language)
                        @php
                            $lang = $language['value'];
                            $languageLabel = $language['label'];
                            $languageFlag = $language['flag'];
                        @endphp
                        <li class="group">
                            <label class="flex items-center bg-white border rounded-sm px-3 py-2 text-sm cursor-pointer">
                            <input
                                type="checkbox"
                                name="language[]"
                                value="{{ $lang }}"
                                class="sr-only peer"
                                onchange="debouncedRedirectWithFilters()"
                                {{ in_array($lang, $selectedLanguages) ? 'checked' : '' }}
                            >
                            <span class="mr-1 inline-block h-4 w-4 rounded border border-gray-300 bg-gray-50 transition
                                peer-checked:bg-red-600 peer-checked:border-red-600 group-hover:bg-gray-100 flex items-center justify-center">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-white hidden peer-checked:block" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                </svg>
                            </span>
                            <span
                                title="{{ $languageLabel }}"
                                aria-label="{{ $languageLabel }}"
                                class="text-gray-900 ml-3 font-medium inline-flex items-center gap-2">
                                <span aria-hidden="true">{{ $languageFlag }}</span>
                                <span>{{ $languageLabel }}</span>
                            </span>
                            </label>
                        </li>
                        @endforeach
                    </ul>
                    </div>


                    <div class="relative mb-4">
                    <label class="block text-sm font-medium text-gray-900 mb-2">DEVELOPER</label>
                    <button id="dropdownButtonDevs" type="button"
                        class="w-full min-h-[2.5rem] px-3 py-2 border rounded-sm bg-white text-left flex flex-wrap items-center gap-2">
                        <div id="selectedDevelopers" class="flex flex-wrap gap-2 flex-1">
                        @if(empty($selectedDevelopers))
                            <span class="text-gray-900 font-medium text-sm">Select Developers</span>
                        @else
                            @foreach($selectedDevelopers as $dev)
                            <span class="px-3 py-2 rounded-[0.2rem] bg-gray-200 text-gray-900 text-sm flex items-center">
                            {{ $dev }}
                            <span role="button" tabindex="0" data-remove-dropdown-name="{{ $dev }}" data-remove-dropdown-type="Developers" class="ml-2 cursor-pointer text-gray-500 hover:text-gray-800 select-none">&times;</span>
                            </span>
                            @endforeach
                        @endif
                        </div>
                        <svg class="pointer-events-none h-3 w-3 text-gray-400" xmlns="http://www.w3.org/2000/svg"
                            fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                    </button>
                    <div id="dropdownMenuDevs"
                        class="absolute left-0 w-full bg-white border rounded-sm shadow-lg hidden z-10 max-h-[400px] overflow-y-auto">
                        <ul>
                        @foreach($allDevelopers as $devName)
                        <li class="px-1.5 py-[1px] cursor-pointer text-gray-900 font-medium text-sm bg-white"
                            data-dropdown-name="{{ $devName }}" data-dropdown-type="Developers">
                            <span class="block w-full h-full px-3 py-2 rounded-[0.2rem] hover:bg-red-600 hover:text-white">
                            {{ $devName }}
                            </span>
                        </li>
                        @endforeach
                        </ul>
                    </div>
                    </div>

                    @include('category._collection-filter')
                </form>
            @else
                <form method="GET"
                    action="{{ route('category', [
                        'category'    => $categorySlug ?? Str::slug($category),
                        'listFilter'  => $listFilter,
                        'mediaStatus' => $mediaStatus,
                        'titleOrder'  => $titleOrder,
                        'scoreOrder'  => $scoreOrder,
                        'dateOrder'   => $dateOrder
                    ]) }}">
                    <div class="relative mb-4">
                        <label class="block text-sm font-medium text-gray-900 mb-2">LIST</label>
                        @php
                            $listCategory = strtoupper((string) ($categorySlug ?? $category ?? ''));
                            $isReadingCategory = in_array($listCategory, [
                                'MANGA', 'MANGAS',
                                'MANHWA', 'MANHWAS',
                                'LIGHT-NOVEL', 'LIGHT-NOVELS',
                                'H-MANGA', 'H-MANGAS',
                            ], true);
                        @endphp
                        <select name="list_filter" onchange="redirectWithFilters()"
                                class="appearance-none w-full px-3 py-2 border rounded-sm focus:border-red-600 focus:outline-none focus:ring-2 focus:ring-red-600 h-[2.5rem] text-gray-900 font-medium">
                            <option value="all" {{ (isset($listFilter) && $listFilter=='all') ? 'selected' : '' }}>All</option>
                            @if($isReadingCategory)
                                <option value="CURRENT" {{ (isset($listFilter) && $listFilter=='CURRENT') ? 'selected' : '' }}>Reading</option>
                            @else
                                <option value="CURRENT" {{ (isset($listFilter) && $listFilter=='CURRENT') ? 'selected' : '' }}>Watching</option>
                            @endif
                            <option value="PAUSED" {{ (isset($listFilter) && $listFilter=='PAUSED') ? 'selected' : '' }}>Paused</option>
                            <option value="COMPLETED" {{ (isset($listFilter) && $listFilter=='COMPLETED') ? 'selected' : '' }}>Completed</option>
                            <option value="DROPPED" {{ (isset($listFilter) && $listFilter=='DROPPED') ? 'selected' : '' }}>Dropped</option>
                            <option value="PLANNING" {{ (isset($listFilter) && $listFilter=='PLANNING') ? 'selected' : '' }}>Planning</option>
                        </select>
                        <svg class="pointer-events-none absolute right-3 top-1/2 transform -translate-y-1/2 h-3 w-3 text-gray-400 mt-3.5"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>

                    <div class="relative mb-4">
                        <label class="block text-sm font-medium text-gray-900 mb-2">STATUS</label>
                        <select name="media_status" onchange="redirectWithFilters()"
                                class="appearance-none w-full px-3 py-2 border rounded-sm focus:border-red-600 focus:outline-none focus:ring-2 focus:ring-red-600 h-[2.5rem] text-gray-900 font-medium">
                            <option value="all" {{ (isset($mediaStatus) && $mediaStatus=='all') ? 'selected' : '' }}>All</option>
                            <option value="FINISHED" {{ (isset($mediaStatus) && $mediaStatus=='FINISHED') ? 'selected' : '' }}>Finished</option>
                            <option value="RELEASING" {{ (isset($mediaStatus) && $mediaStatus=='RELEASING') ? 'selected' : '' }}>Releasing</option>
                            <option value="NOT_YET_RELEASED" {{ (isset($mediaStatus) && $mediaStatus=='NOT_YET_RELEASED') ? 'selected' : '' }}>Not Yet Released</option>
                            <option value="CANCELLED" {{ (isset($mediaStatus) && $mediaStatus=='CANCELLED') ? 'selected' : '' }}>Cancelled</option>
                        </select>
                        <svg class="pointer-events-none absolute right-3 top-1/2 transform -translate-y-1/2 h-3 w-3 text-gray-400 mt-3.5"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>

                    <div class="relative mb-4">
                        <label class="block text-sm font-medium text-gray-900 mb-2">TITLE</label>
                        <select name="title_order" onchange="redirectWithFilters()"
                                class="appearance-none w-full px-3 py-2 border rounded-sm focus:border-red-600 focus:outline-none focus:ring-2 focus:ring-red-600 h-[2.5rem] text-gray-900 font-medium">
                            <option value="none" {{ (isset($titleOrder) && $titleOrder=='none') ? 'selected' : '' }}>None</option>
                            <option value="az" {{ (isset($titleOrder) && $titleOrder=='az') ? 'selected' : '' }}>A–Z</option>
                            <option value="za" {{ (isset($titleOrder) && $titleOrder=='za') ? 'selected' : '' }}>Z–A</option>
                        </select>
                        <svg class="pointer-events-none absolute right-3 top-1/2 transform -translate-y-1/2 h-3 w-3 text-gray-400 mt-3.5"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>

                    <div class="relative mb-4">
                        <label class="block text-sm font-medium text-gray-900 mb-2">SCORE</label>
                        <select name="score_order" onchange="redirectWithFilters()"
                                class="appearance-none w-full px-3 py-2 border rounded-sm focus:border-red-600 focus:outline-none focus:ring-2 focus:ring-red-600 h-[2.5rem] text-gray-900 font-medium">
                            <option value="none" {{ (isset($scoreOrder) && $scoreOrder=='none') ? 'selected' : '' }}>None</option>
                            <option value="avg_desc" {{ (isset($scoreOrder) && $scoreOrder=='avg_desc') ? 'selected' : '' }}>Average (High to Low)</option>
                            <option value="avg_asc" {{ (isset($scoreOrder) && $scoreOrder=='avg_asc') ? 'selected' : '' }}>Average (Low to High)</option>
                            <option value="personal_desc" {{ (isset($scoreOrder) && $scoreOrder=='personal_desc') ? 'selected' : '' }}>Personal (High to Low)</option>
                            <option value="personal_asc" {{ (isset($scoreOrder) && $scoreOrder=='personal_asc') ? 'selected' : '' }}>Personal (Low to High)</option>
                        </select>
                        <svg class="pointer-events-none absolute right-3 top-1/2 transform -translate-y-1/2 h-3 w-3 text-gray-400 mt-3.5"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>
                    <div class="relative mb-4">
                        <label class="block text-sm font-medium text-gray-900 mb-2">RELEASE</label>
                        <select name="date_order" onchange="redirectWithFilters()"
                                class="appearance-none w-full px-3 py-2 border rounded-sm focus:border-red-600 focus:outline-none focus:ring-2 focus:ring-red-600 h-[2.5rem] text-gray-900 font-medium">
                            <option value="none" {{ (isset($dateOrder) && $dateOrder=='none') ? 'selected' : '' }}>None</option>
                            <option value="start_desc" {{ (isset($dateOrder) && $dateOrder=='start_desc') ? 'selected' : '' }}>New to Old</option>
                            <option value="start_asc" {{ (isset($dateOrder) && $dateOrder=='start_asc') ? 'selected' : '' }}>Old to New</option>
                        </select>
                        <svg class="pointer-events-none absolute right-3 top-1/2 transform -translate-y-1/2 h-3 w-3 text-gray-400 mt-3.5"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>
                    <div class="relative mb-4">
                        <label class="block text-sm font-medium text-gray-900 mb-2">YEAR</label>
                        <select name="year" onchange="redirectWithFilters()"
                                class="appearance-none w-full px-3 py-2 border rounded-sm focus:border-red-600 focus:outline-none focus:ring-2 focus:ring-red-600 h-[2.5rem] text-gray-900 font-medium">
                            <option value="">All</option>
                            @foreach($allYears as $year)
                                <option value="{{ $year }}" {{ (isset($selectedYears[0]) && $selectedYears[0] == $year) ? 'selected' : '' }}>
                                    {{ $year }}
                                </option>
                            @endforeach
                        </select>
                        <svg class="pointer-events-none absolute right-3 top-1/2 transform -translate-y-1/2 h-3 w-3 text-gray-400 mt-3.5"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>
                    <div class="relative mb-4">
                        <label class="block text-sm font-medium text-gray-900 mb-2">ERA</label>
                        <select name="era" onchange="redirectWithFilters()"
                                class="appearance-none w-full px-3 py-2 border rounded-sm focus:border-red-600 focus:outline-none focus:ring-2 focus:ring-red-600 h-[2.5rem] text-gray-900 font-medium">
                            <option value="">All</option>
                            @foreach($allEras ?? [] as $era)
                                <option value="{{ $era['value'] }}" {{ (($selectedEra ?? '') === $era['value']) ? 'selected' : '' }}>
                                    {{ $era['label'] }}
                                </option>
                            @endforeach
                        </select>
                        <svg class="pointer-events-none absolute right-3 top-1/2 transform -translate-y-1/2 h-3 w-3 text-gray-400 mt-3.5"
                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>

                    <div class="relative mb-4">
                        <label class="block text-sm font-medium text-gray-900 mb-2">TAGS</label>
                        <button id="dropdownButtonTags" type="button"
                            class="w-full min-h-[2.5rem] px-3 py-2 border rounded-sm bg-white text-left flex flex-wrap items-center gap-2">
                            <div id="selectedTags" class="flex flex-wrap gap-2 flex-1">
                                <span class="text-gray-900 font-medium text-sm">Select Tags</span>
                            </div>
                            <svg class="pointer-events-none h-3 w-3 text-gray-400" xmlns="http://www.w3.org/2000/svg"
                                    fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                        </button>
                        <div id="dropdownMenuTags" class="absolute left-0 w-full bg-white border rounded-sm shadow-lg hidden z-10 max-h-[400px] overflow-y-auto">
                            <ul>
                                @foreach($allTags as $tagName)
                                    <li class="px-1.5 py-[1px] cursor-pointer text-gray-900 font-medium text-sm transition-all duration-200 ease-in-out bg-white"
                                        data-dropdown-name="{{ $tagName }}" data-dropdown-type="Tags">
                                        <span class="block w-full h-full px-3 py-2 rounded-[0.2rem] transition-all duration-200 ease-in-out
                                            hover:bg-red-600 hover:text-white hover:font-semibold">
                                            {{ $tagName }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-900 mb-2">GENRES</label>
                        <ul class="space-y-2">
                            @foreach($allGenres as $genre)
                                <li class="group">
                                    <label class="flex items-center bg-white border rounded-sm px-3 font-boldness py-2 text-sm font cursor-pointer">
                                        <input type="checkbox" name="genre[]" value="{{ $genre }}" class="sr-only peer"
                                            onchange="debouncedRedirectWithFilters()"
                                            {{ in_array($genre, $selectedGenres) ? 'checked' : '' }}>
                                        <span class="mr-1 inline-block h-4 w-4 rounded border border-gray-300 bg-gray-50 transition
                                            peer-checked:bg-red-600 peer-checked:border-red-600 group-hover:bg-gray-100 flex items-center justify-center">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-white hidden peer-checked:block" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                            </svg>
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-6">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                            </svg>
                                        </span>
                                        <span class="text-gray-900 ml-3">{{ $genre }}</span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    @if(in_array(strtoupper($category), ['ANIME', 'HENTAI']))
                        <div class="relative mt-4">
                            <label class="block text-sm font-medium text-gray-900 mb-2">STUDIO</label>
                            <!-- Expanding Studio Container -->
                            <button id="dropdownButtonStudio" type="button"
                                class="w-full min-h-[2.5rem] px-3 py-2 border rounded-sm bg-white text-left flex flex-wrap items-center gap-2">
                                <div id="selectedStudio" class="flex flex-wrap gap-2 flex-1">
                                    <span class="text-gray-900 font-medium text-sm">Select Studio</span>
                                </div>
                                <svg class="pointer-events-none h-3 w-3 text-gray-400" xmlns="http://www.w3.org/2000/svg"
                                    fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                            </button>
                            <!-- Dropdown Menu -->
                            <div id="dropdownMenuStudio" class="absolute left-0 w-full bg-white border rounded-sm shadow-lg hidden z-10 max-h-[400px] overflow-y-auto">
                                <ul>
                                    @foreach($allStudios as $studio)
                                        <li class="px-1.5 py-[1px] cursor-pointer text-gray-900 font-medium text-sm transition-all duration-200 ease-in-out bg-white"
                                            data-dropdown-name="{{ $studio }}" data-dropdown-type="Studio">
                                            <span class="block w-full h-full px-3 py-2 rounded-[0.2rem] transition-all duration-200 ease-in-out
                                                hover:bg-red-600 hover:text-white hover:font-semibold">
                                                {{ $studio }}
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif
                    @if(!in_array(strtoupper($category), ['ANIME', 'HENTAI']))
                        <div class="relative mt-4">
                            <label class="block text-sm font-medium text-gray-900 mb-2">AUTHOR</label>
                            <!-- Expanding Author Container -->
                            <button id="dropdownButtonAuthor" type="button"
                                class="w-full min-h-[2.5rem] px-3 py-2 border rounded-sm bg-white text-left flex flex-wrap items-center gap-2">
                                <div id="selectedAuthor" class="flex flex-wrap gap-2 flex-1">
                                    <span class="text-gray-900 font-medium text-sm">Select Author</span>
                                </div>
                                <svg class="pointer-events-none h-3 w-3 text-gray-400" xmlns="http://www.w3.org/2000/svg"
                                    fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                            </button>
                            <!-- Dropdown Menu -->
                            <div id="dropdownMenuAuthor" class="absolute left-0 w-full bg-white border rounded-sm shadow-lg hidden z-10 max-h-[400px] overflow-y-auto">
                                <ul>
                                    @foreach($allAuthors as $author)
                                        <li class="px-1.5 py-[1px] cursor-pointer text-gray-900 font-medium text-sm transition-all duration-200 ease-in-out bg-white"
                                            data-dropdown-name="{{ $author }}" data-dropdown-type="Author">
                                            <span class="block w-full h-full px-3 py-2 rounded-[0.2rem] transition-all duration-200 ease-in-out hover:bg-red-600 hover:text-white hover:font-semibold">
                                                {{ $author }}
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif

                    @include('category._collection-filter')
                </form>
            @endif
        </aside>

        <!-- Main Content -->
        <section class="mt-6 ml-2 flex flex-col items-center">
            <div id="mediaContainer">
                @include('partials.media', ['media' => $media])
            </div>
        </section>
    </div>
</div>

@if(strtoupper($category) === 'DOUJINS' && !$isViewer)
    <div
      id="addDoujinModal"
      class="fixed inset-0 flex items-start justify-center overflow-y-auto bg-black bg-opacity-50 hidden z-50 py-8"
    >
        <div class="relative bg-white p-4 text-left shadow-2xl w-[800px] rounded-lg">
            <div class="flex justify-between items-start pb-4 pt-2 border-b ml-4 mr-4 border-gray-200">
                <div>
                    <h3 class="text-lg font-bold text-gray-800">Add Doujin</h3>
                </div>
                <button id="closeAddDoujinModal" type="button" class="text-gray-400 hover:text-gray-900" aria-label="Close Add Doujin Modal">
                    <span class="sr-only">Close</span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="border-b border-gray-200 mr-4 ml-4">
                <form method="POST" action="{{ route('doujin.upload') }}" enctype="multipart/form-data" class="space-y-4 py-4" id="addDoujinForm">
                    @csrf

                    <div id="addDoujinInlineError"
                         class="app-alert app-alert-error {{ session('doujin_upload_error') ? '' : 'hidden' }}">
                        {{ session('doujin_upload_error') }}
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <label class="block col-span-2">
                            <span class="block mb-2 text-red-600 font-medium">Title</span>
                            <input
                              type="text"
                              name="title_english"
                              value="{{ old('title_english') }}"
                              class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                            />
                        </label>

                        <div class="block space-y-4 self-start">
                            <label class="block">
                                <span class="block mb-2 text-red-600 font-medium">Author</span>
                                <select
                                  id="addDoujinExistingAuthor"
                                  name="existing_author"
                                  class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                                >
                                    <option value="" disabled hidden {{ old('existing_author') ? '' : 'selected' }}>Select existing author</option>
                                    @foreach($allAuthors as $author)
                                        <option value="{{ $author }}" {{ old('existing_author') === $author ? 'selected' : '' }}>
                                            {{ $author }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>

                            <div class="block space-y-4">
                                @foreach([
                                    'author_twitter_url' => 'Twitter',
                                    'author_patreon_url' => 'Patreon',
                                    'author_fanbox_url' => 'Fanbox',
                                    'author_pixiv_url' => 'Pixiv',
                                ] as $field => $label)
                                    <div class="block" data-author-link-group data-field-name="{{ $field }}" data-label="{{ $label }}">
                                        <span class="block mb-2 text-red-600 font-medium">{{ $label }}</span>
                                        <div class="space-y-2" data-author-link-list>
                                            @foreach($doujinAuthorLinkValues($field) as $url)
                                                <div class="flex items-center gap-2" data-author-link-row>
                                                    <input
                                                      type="text"
                                                      name="{{ $field }}[]"
                                                      value="{{ $url }}"
                                                      class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                                                    />
                                                    <button type="button"
                                                            data-add-author-link
                                                            aria-label="Add {{ $label }} link"
                                                            class="shrink-0 rounded-md border border-gray-200 bg-gray-100 px-3 py-2 text-base font-bold text-red-600 hover:bg-red-600 hover:text-white">
                                                        +
                                                    </button>
                                                    <button type="button"
                                                            data-remove-author-link
                                                            aria-label="Remove {{ $label }} link"
                                                            class="hidden shrink-0 rounded-md border border-gray-200 bg-gray-100 px-3 py-2 text-base font-bold text-gray-500 hover:bg-gray-200">
                                                        &times;
                                                    </button>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="block space-y-4 self-start">
                            <label class="block">
                                <span class="block mb-2 text-red-600 font-medium">New Author</span>
                                <input
                                  type="text"
                                  name="new_author"
                                  value="{{ old('new_author') }}"
                                  class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                                />
                            </label>

                            <div class="block">
                                <span class="block mb-2 text-red-600 font-medium">ZIP File</span>
                                <input
                                  id="doujinArchiveInput"
                                  type="file"
                                  name="archive"
                                  accept=".zip"
                                  class="sr-only"
                                />
                                <label
                                  for="doujinArchiveInput"
                                  class="flex w-full cursor-pointer items-center justify-between gap-4 rounded-md border border-gray-200 bg-gray-100 px-3 py-2 text-base text-gray-800 font-medium focus-within:ring-[0.2rem] focus-within:ring-red-600"
                                >
                                    <span
                                      id="doujinArchiveName"
                                      class="min-w-0 flex-1 truncate text-gray-500"
                                      data-placeholder="No ZIP selected"
                                    >
                                        No ZIP selected
                                    </span>
                                    <span class="flatGreen shrink-0 rounded-[0.19rem] px-3 py-2 text-sm font-medium text-white">
                                        Select ZIP
                                    </span>
                                </label>
                            </div>
                        </div>

                    </div>
                </form>
            </div>

            <div class="mb-2 px-4 pt-4 flex items-center justify-end gap-3">
                <button id="cancelAddDoujinModal" type="button" class="px-5 py-3 rounded border border-gray-200 text-gray-700 font-medium hover:bg-gray-100 transition-colors">
                    Cancel
                </button>
                <button id="submitAddDoujinBtn" form="addDoujinForm" type="submit" class="flatGreen transition-200 text-white px-5 py-3 rounded" data-default-label="Add Doujin" data-uploading-label="Uploading...">
                    Add Doujin
                </button>
            </div>
        </div>
    </div>
@endif

<div id="paginationContainer">
    @if($paginatedMedia->lastPage() > 1)
        <div class="flex items-center justify-center space-x-2 ml-[280px] mb-6">
            <span class="text-gray-900 text-lg font-medium">Pages</span>

            @if($paginatedMedia->currentPage() > 1)
                <a href="{{ $paginatedMedia->url(1) }}" class="pagination-arrow mb-1">&laquo;</a>
                <a href="{{ $paginatedMedia->previousPageUrl() }}" class="pagination-arrow mb-1">&lsaquo;</a>
            @endif

            @php
                $maxVisible = 7;
                $start = max(1, $paginatedMedia->currentPage() - intdiv($maxVisible,2));
                $end   = min($paginatedMedia->lastPage(), $start + $maxVisible - 1);
                if($end - $start + 1 < $maxVisible) {
                    $start = max(1, $end - $maxVisible + 1);
                }
            @endphp

            <div class="flex space-x-2 text-lg">
                @for($i = $start; $i <= $end; $i++)
                    @if($i === $paginatedMedia->currentPage())
                        <span class="pagination-btn pagination-active">{{ $i }}</span>
                    @else
                        <a href="{{ $paginatedMedia->url($i) }}" class="pagination-btn non-selected-page-number">
                            {{ $i }}
                        </a>
                    @endif
                @endfor
            </div>

            @if($paginatedMedia->currentPage() < $paginatedMedia->lastPage())
                <a href="{{ $paginatedMedia->nextPageUrl() }}" class="pagination-arrow mb-1">&rsaquo;</a>
                <a href="{{ $paginatedMedia->url($paginatedMedia->lastPage()) }}" class="pagination-arrow mb-1">&raquo;</a>
            @endif
        </div>
    @endif
</div>

<script>
// Debounce helper to avoid too many requests:
function debounce(func, wait) {
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

// Global filters object (for Tags and Studio)
const dropdowns = {
    Tags: {
        button: document.getElementById("dropdownButtonTags"),
        menu: document.getElementById("dropdownMenuTags"),
        selectedContainer: document.getElementById("selectedTags"),
        selectedItems: [],
        placeholder: "Select Tags"
    },
    Studio: {
        button: document.getElementById("dropdownButtonStudio"),
        menu: document.getElementById("dropdownMenuStudio"),
        selectedContainer: document.getElementById("selectedStudio"),
        selectedItems: [],
        placeholder: "Select Studio"
    },
    Author: {
        button: document.getElementById("dropdownButtonAuthor"),
        menu: document.getElementById("dropdownMenuAuthor"),
        selectedContainer: document.getElementById("selectedAuthor"),
        selectedItems: [],
        placeholder: "Select Author"
    },
    Developers: {
        button: document.getElementById("dropdownButtonDevs"),
        menu:   document.getElementById("dropdownMenuDevs"),
        selectedContainer: document.getElementById("selectedDevelopers"),
        selectedItems: [],
        placeholder: "Select Developers"
    },
    Collection: {
        button: document.getElementById("dropdownButtonCollection"),
        menu: document.getElementById("dropdownMenuCollection"),
        selectedContainer: document.getElementById("selectedCollection"),
        selectedItems: [],
        placeholder: "Select Collection"
    },
    CollectionBlacklist: {
        button: document.getElementById("dropdownButtonCollectionBlacklist"),
        menu: document.getElementById("dropdownMenuCollectionBlacklist"),
        selectedContainer: document.getElementById("selectedCollectionBlacklist"),
        selectedItems: [],
        placeholder: "Select Collection Blacklist"
    }
};

Object.keys(dropdowns).forEach(type => {
    dropdowns[type].labelByName = {};
    dropdowns[type].menu?.querySelectorAll("[data-dropdown-name]").forEach(item => {
        dropdowns[type].labelByName[item.dataset.dropdownName] =
            item.querySelector("span")?.textContent.trim() || item.dataset.dropdownName;
    });
});

// Attach a click event listener to each dropdown button (for both Tags and Studio)
Object.keys(dropdowns).forEach(type => {
    if(dropdowns[type].button) {
        dropdowns[type].button.addEventListener("click", (event) => {
            event.stopPropagation();
            dropdowns[type].menu.classList.toggle("hidden");
        });
    }
});

// Update selected display (generic)
function updateSelectedDropdown(type) {
    const dropdown = dropdowns[type];
    if(!dropdown || !dropdown.selectedContainer) return;
    dropdown.selectedContainer.innerHTML = "";
    if (dropdown.selectedItems.length === 0) {
        const placeholder = document.createElement("span");
        placeholder.className = "text-gray-900 font-medium text-sm";
        placeholder.textContent = dropdown.placeholder || `Select ${type}`;
        dropdown.selectedContainer.appendChild(placeholder);
        return;
    }
    dropdown.selectedItems.forEach(name => {
        const tagElement = document.createElement("span");
        tagElement.className = "px-3 py-2 rounded-[0.2rem] bg-gray-200 text-gray-900 text-sm flex items-center transition-all duration-200 ease-in-out";

        const label = document.createElement("span");
        label.textContent = dropdown.labelByName?.[name] || name;

        const removeButton = document.createElement("span");
        removeButton.setAttribute("role", "button");
        removeButton.tabIndex = 0;
        removeButton.className = "ml-2 cursor-pointer text-gray-500 hover:text-gray-800 select-none";
        removeButton.innerHTML = "&times;";
        removeButton.addEventListener("click", (event) => {
            event.stopPropagation();
            removeTag(name, type);
        });

        tagElement.appendChild(label);
        tagElement.appendChild(removeButton);
        dropdown.selectedContainer.appendChild(tagElement);
    });
}
// Update dropdown menu styling (generic)
function updateDropdownMenu(type) {
    const dropdown = dropdowns[type];
    if(!dropdown || !dropdown.menu) return;
    const listItems = dropdown.menu.querySelectorAll("[data-dropdown-name]");
    listItems.forEach(item => {
        const label = item.querySelector("span") || item;
        if (dropdown.selectedItems.includes(item.dataset.dropdownName)) {
            label.classList.add("bg-gray-300", "text-gray-700");
            label.classList.remove("hover:bg-red-600", "hover:text-white");
            label.classList.add("hover:bg-red-600", "hover:text-white");
        } else {
            label.classList.remove("bg-gray-300", "text-gray-700");
            label.classList.add("hover:bg-red-600", "hover:text-white");
        }
    });
}

document.querySelectorAll('[data-dropdown-type][data-dropdown-name]').forEach(item => {
    item.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        toggleTag(item.dataset.dropdownName, item.dataset.dropdownType);
    });
});

document.querySelectorAll('[data-remove-dropdown-type][data-remove-dropdown-name]').forEach(item => {
    item.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        removeTag(item.dataset.removeDropdownName, item.dataset.removeDropdownType);
    });
});

// Toggle tag selection for Tags or Studio, update display, then trigger debounced AJAX update
function toggleTag(name, type) {
    const dropdown = dropdowns[type];
    if (!dropdown) return;
    const index = dropdown.selectedItems.indexOf(name);
    if (index === -1) {
        dropdown.selectedItems.push(name);
    } else {
        dropdown.selectedItems.splice(index, 1);
    }
    updateSelectedDropdown(type);
    updateDropdownMenu(type);
    debouncedRedirectWithFilters();
}
// Remove an item from a dropdown and update filters
function removeTag(name, type) {
    const dropdown = dropdowns[type];
    if(!dropdown) return;
    dropdown.selectedItems = dropdown.selectedItems.filter(t => t !== name);
    updateSelectedDropdown(type);
    updateDropdownMenu(type);
    debouncedRedirectWithFilters();
}

// Close dropdowns when clicking outside
document.addEventListener("click", (event) => {
    Object.keys(dropdowns).forEach((key) => {
        if (dropdowns[key].button && dropdowns[key].menu) {
            if (!dropdowns[key].button.contains(event.target) && !dropdowns[key].menu.contains(event.target)) {
                dropdowns[key].menu.classList.add("hidden");
            }
        }
    });
});

let currentRequestId = 0;
function redirectWithFilters () {
    currentRequestId++;
    const thisId = currentRequestId;

    const listFilter  = document.querySelector('select[name="list_filter"]')?.value ?? 'all';
    const mediaStatus = document.querySelector('select[name="media_status"]')?.value ?? 'all';
    const dateOrder   = document.querySelector('select[name="date_order"]')?.value  ?? 'none';
    const titleOrder  = document.querySelector('select[name="title_order"]')?.value ?? 'none';
    const scoreOrder  = document.querySelector('select[name="score_order"]')?.value ?? 'none';
    const yearOrder   = document.querySelector('select[name="year_order"]')?.value ?? 'none';

    // ← NEW: capture the name_order select for doujins
    const nameOrder   = document.querySelector('select[name="name_order"]')?.value ?? 'none';

    const tags       = dropdowns.Tags.selectedItems.join(',');
    const languages  = Array.from(
                          document.querySelectorAll('input[name="language[]"]:checked')
                       ).map(c => c.value).join(',');
    const genres     = Array.from(
                          document.querySelectorAll('input[name="genre[]"]:checked')
                       ).map(c => c.value);
    const studios    = dropdowns.Studio?.selectedItems.join(',') ?? '';
    const authors    = dropdowns.Author?.selectedItems.join(',') ?? '';
    const developers = dropdowns.Developers.selectedItems.join(',');

    const year       = document.querySelector('select[name="year"]')?.value ?? '';
    const era        = document.querySelector('select[name="era"]')?.value ?? '';
    const collection = dropdowns.Collection?.selectedItems.join(',') ?? '';
    const collectionBlacklist = dropdowns.CollectionBlacklist?.selectedItems.join(',') ?? '';
    const qp = new URLSearchParams();
    if (tags)        qp.append('tags',        tags);
    if (languages)   qp.append('language',    languages);
    if (genres.length) genres.forEach(g => qp.append('genre[]', g));
    if (studios)     qp.append('studio',      studios);
    if (authors)     qp.append('author',      authors);
    if (developers)  qp.append('developers',  developers);
    if (year)        qp.append('year',        year);
    if (era)         qp.append('era',         era);
    if (collection)  qp.append('collection',  collection);
    if (collectionBlacklist) qp.append('collection_blacklist', collectionBlacklist);
    if (yearOrder  !== 'none') qp.append('year_order',  yearOrder);
    if (titleOrder !== 'none') qp.append('title_order', titleOrder);
    if (scoreOrder !== 'none') qp.append('score_order', scoreOrder);

    // ── INSERTED DOUJINS BRANCH HERE ──
    let url;
    if (categorySlug === 'visual-novel') {
        qp.set('list_filter', listFilter);       // MUST be in the query string for VN
        url = `/category/${categorySlug}?${qp.toString()}`;
    } else if (categorySlug === 'doujins') {
        if (nameOrder !== 'none') {
            qp.append('name_order', nameOrder);
        }
        url = `/category/${categorySlug}?${qp.toString()}`;
    } else {
        url = `/category/${categorySlug}/${listFilter}/${mediaStatus}/${titleOrder}/${scoreOrder}/${dateOrder}?${qp.toString()}`;
    }

    history.pushState(null, '', url);
    toggleSpinner(true);

    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.text())
        .then(html => {
            const tmp = document.createElement('div');
            tmp.innerHTML = html;
            document.getElementById('mediaContainer').innerHTML    =
                tmp.querySelector('#mediaContainer').innerHTML;
            document.getElementById('paginationContainer').innerHTML =
                tmp.querySelector('#paginationContainer').innerHTML;
        })
        .catch(console.error)
        .finally(() => {
            if (thisId === currentRequestId) toggleSpinner(false);
        });
}


function toggleSpinner (busy) {
    const o = busy ? '0.5' : '1';
    document.getElementById('mediaContainer').style.opacity    = o;
    document.getElementById('paginationContainer').style.opacity = o;
}

const debouncedRedirectWithFilters = debounce(redirectWithFilters, 500);

window.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(location.search);

    const langParam = urlParams.get('language');
    if (langParam) {
        const langs = langParam.split(',').filter(Boolean);
        document.querySelectorAll('input[name="language[]"]').forEach(cb => {
            cb.checked = langs.includes(cb.value);
        });
    }
});

window.addEventListener('load', function(){
    const urlParams = new URLSearchParams(window.location.search);
    const tagsParam = urlParams.get('tags');
    if (tagsParam) {
        dropdowns.Tags.selectedItems = tagsParam.split(',').filter(tag => tag !== '');
        updateSelectedDropdown('Tags');
        updateDropdownMenu('Tags');
    }
    const studioParam = urlParams.get('studio');
    if (studioParam && dropdowns.Studio) {
        dropdowns.Studio.selectedItems = studioParam.split(',').filter(studio => studio !== '');
        updateSelectedDropdown('Studio');
        updateDropdownMenu('Studio');
    }
    const authorParam = urlParams.get('author');
    if (authorParam && dropdowns.Author) {
        dropdowns.Author.selectedItems = authorParam.split(',').filter(author => author !== '');
        updateSelectedDropdown('Author');
        updateDropdownMenu('Author');
    }
    const devsParam = urlParams.get('developers');
    if (devsParam) {
        dropdowns.Developers.selectedItems = devsParam.split(',').filter(t=>t);
        updateSelectedDropdown('Developers');
        updateDropdownMenu('Developers');
    }
    const collectionParam = urlParams.get('collection');
    if (collectionParam) {
        dropdowns.Collection.selectedItems = collectionParam.split(',').filter(t=>t);
        updateSelectedDropdown('Collection');
        updateDropdownMenu('Collection');
    }
    const collectionBlacklistParam = urlParams.get('collection_blacklist');
    if (collectionBlacklistParam) {
        dropdowns.CollectionBlacklist.selectedItems = collectionBlacklistParam.split(',').filter(t=>t);
        updateSelectedDropdown('CollectionBlacklist');
        updateDropdownMenu('CollectionBlacklist');
    }
});

// Also add event listeners on genre checkboxes:
document.querySelectorAll('input[name="genre[]"]').forEach(cb => {
    cb.addEventListener('change', debouncedRedirectWithFilters);
});

const addDoujinModal = document.getElementById('addDoujinModal');
const openAddDoujinModalButton = document.getElementById('openAddDoujinModal');
const closeAddDoujinModalButton = document.getElementById('closeAddDoujinModal');
const cancelAddDoujinModalButton = document.getElementById('cancelAddDoujinModal');
const addDoujinForm = document.getElementById('addDoujinForm');
const addDoujinInlineError = document.getElementById('addDoujinInlineError');
const doujinArchiveInput = document.getElementById('doujinArchiveInput');
const doujinArchiveName = document.getElementById('doujinArchiveName');
const submitAddDoujinBtn = document.getElementById('submitAddDoujinBtn');
const doujinUploadChunkRoute = @json(route('doujin.upload.chunk'));
const doujinUploadCompleteRoute = @json(route('doujin.upload.complete'));
const doujinUploadChunkSizeBytes = 8 * 1024 * 1024;
let currentDoujinUploadSession = null;
let isUploadingDoujin = false;
const addDoujinAuthorLinks = @json($allAuthorLinks ?? []);
const addDoujinExistingAuthor = document.getElementById('addDoujinExistingAuthor');
const addDoujinAuthorLinkGroups = document.querySelectorAll('#addDoujinForm [data-author-link-group]');

function normalizeAuthorLinkValues(values) {
    if (Array.isArray(values)) {
        return values.map((value) => String(value || '').trim()).filter(Boolean);
    }

    if (typeof values === 'string') {
        return values.split(/\r?\n/).map((value) => value.trim()).filter(Boolean);
    }

    return [];
}

function refreshAuthorLinkGroupButtons(group) {
    const rows = group.querySelectorAll('[data-author-link-row]');
    rows.forEach((row, index) => {
        row.querySelector('[data-add-author-link]')?.classList.toggle('hidden', index !== rows.length - 1);
        row.querySelector('[data-remove-author-link]')?.classList.toggle('hidden', rows.length <= 1);
    });
}

function appendAuthorLinkRow(group, value = '') {
    const list = group.querySelector('[data-author-link-list]');
    const template = list?.querySelector('[data-author-link-row]');
    if (!list || !template) return;

    const row = template.cloneNode(true);
    const input = row.querySelector('input');
    if (input) {
        input.value = value;
        input.name = `${group.dataset.fieldName}[]`;
    }

    list.appendChild(row);
    refreshAuthorLinkGroupButtons(group);
}

function setAuthorLinkGroupValues(group, values) {
    const list = group.querySelector('[data-author-link-list]');
    const template = list?.querySelector('[data-author-link-row]');
    if (!list || !template) return;

    const normalized = normalizeAuthorLinkValues(values);
    list.innerHTML = '';
    const rowValues = normalized.length ? normalized : [''];

    rowValues.forEach((value) => {
        const row = template.cloneNode(true);
        const input = row.querySelector('input');
        if (input) {
            input.value = value;
            input.name = `${group.dataset.fieldName}[]`;
        }
        list.appendChild(row);
    });

    refreshAuthorLinkGroupButtons(group);
}

function initAuthorLinkGroups(groups) {
    groups.forEach((group) => refreshAuthorLinkGroupButtons(group));
}

function fillAddDoujinAuthorLinkFields(authorName) {
    const links = addDoujinAuthorLinks[authorName] || {};
    const keyByField = {
        author_twitter_url: 'twitter',
        author_patreon_url: 'patreon',
        author_fanbox_url: 'fanbox',
        author_pixiv_url: 'pixiv',
    };

    addDoujinAuthorLinkGroups.forEach((group) => {
        setAuthorLinkGroupValues(group, links[keyByField[group.dataset.fieldName]] || []);
    });
}

function updateDoujinArchiveName() {
    if (!doujinArchiveInput || !doujinArchiveName) return;

    const selectedFile = doujinArchiveInput.files?.[0];
    const placeholder = doujinArchiveName.dataset.placeholder ?? 'No ZIP selected';

    doujinArchiveName.textContent = selectedFile ? selectedFile.name : placeholder;
    doujinArchiveName.classList.toggle('text-gray-500', !selectedFile);
    doujinArchiveName.classList.toggle('text-gray-800', Boolean(selectedFile));
}

function setAddDoujinModal(open) {
    if (!addDoujinModal) return;
    if (!open && isUploadingDoujin) return;
    addDoujinModal.classList.toggle('hidden', !open);
    addDoujinModal.classList.toggle('flex', open);
    document.body.classList.toggle('overflow-hidden', open);
}

function setAddDoujinInlineError(message) {
    if (!addDoujinInlineError) return;

    if (!message) {
        addDoujinInlineError.textContent = '';
        addDoujinInlineError.classList.add('hidden');
        return;
    }

    addDoujinInlineError.textContent = message;
    addDoujinInlineError.classList.remove('hidden');
}

function setAddDoujinBusy(isBusy, label = null) {
    isUploadingDoujin = isBusy;

    if (submitAddDoujinBtn) {
        submitAddDoujinBtn.disabled = isBusy;
        submitAddDoujinBtn.classList.toggle('opacity-60', isBusy);
        submitAddDoujinBtn.classList.toggle('cursor-not-allowed', isBusy);
        submitAddDoujinBtn.textContent = isBusy
            ? (label || submitAddDoujinBtn.dataset.uploadingLabel || 'Uploading...')
            : (submitAddDoujinBtn.dataset.defaultLabel || 'Add Doujin');
    }

    if (cancelAddDoujinModalButton) {
        cancelAddDoujinModalButton.disabled = isBusy;
    }

    if (closeAddDoujinModalButton) {
        closeAddDoujinModalButton.disabled = isBusy;
    }
}

function createDoujinUploadId() {
    const fallback = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    const value = window.crypto?.randomUUID ? window.crypto.randomUUID() : fallback;
    return value.replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 80);
}

function doujinFileKey(file) {
    return [file.name, file.size, file.lastModified].join(':');
}

async function parseDoujinUploadResponse(response) {
    let payload = {};
    try {
        payload = await response.json();
    } catch (err) {
        payload = {};
    }

    if (!response.ok) {
        throw new Error(payload.message || 'Upload failed. Check the ZIP structure and try again.');
    }

    return payload;
}

async function uploadDoujinChunks(file, session, token) {
    for (let chunkIndex = 0; chunkIndex < session.totalChunks; chunkIndex++) {
        const start = chunkIndex * doujinUploadChunkSizeBytes;
        const end = Math.min(file.size, start + doujinUploadChunkSizeBytes);
        const chunk = file.slice(start, end);
        const formData = new FormData();
        formData.append('upload_id', session.uploadId);
        formData.append('chunk_index', String(chunkIndex));
        formData.append('total_chunks', String(session.totalChunks));
        formData.append('archive_chunk', chunk, `${file.name}.part${chunkIndex}`);

        const response = await fetch(doujinUploadChunkRoute, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            },
            body: formData,
        });

        await parseDoujinUploadResponse(response);

        const percent = Math.max(1, Math.min(99, Math.round(((chunkIndex + 1) / session.totalChunks) * 100)));
        setAddDoujinBusy(true, `Uploading ${percent}%`);
    }
}

async function completeDoujinUpload(session, file, token) {
    const formData = new FormData(addDoujinForm);
    formData.delete('archive');
    formData.append('upload_id', session.uploadId);
    formData.append('total_chunks', String(session.totalChunks));
    formData.append('original_name', file.name);

    const response = await fetch(doujinUploadCompleteRoute, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-CSRF-TOKEN': token } : {}),
        },
        body: formData,
    });

    return parseDoujinUploadResponse(response);
}

openAddDoujinModalButton?.addEventListener('click', () => setAddDoujinModal(true));
closeAddDoujinModalButton?.addEventListener('click', () => setAddDoujinModal(false));
cancelAddDoujinModalButton?.addEventListener('click', () => setAddDoujinModal(false));
initAuthorLinkGroups(addDoujinAuthorLinkGroups);
addDoujinForm?.addEventListener('click', (event) => {
    const addButton = event.target.closest('[data-add-author-link]');
    const removeButton = event.target.closest('[data-remove-author-link]');

    if (addButton) {
        const group = addButton.closest('[data-author-link-group]');
        if (group) appendAuthorLinkRow(group);
    }

    if (removeButton) {
        const group = removeButton.closest('[data-author-link-group]');
        const row = removeButton.closest('[data-author-link-row]');
        if (group && row && group.querySelectorAll('[data-author-link-row]').length > 1) {
            row.remove();
            refreshAuthorLinkGroupButtons(group);
        }
    }
});
addDoujinExistingAuthor?.addEventListener('change', () => {
    fillAddDoujinAuthorLinkFields(addDoujinExistingAuthor.value);
});
doujinArchiveInput?.addEventListener('change', () => {
    updateDoujinArchiveName();
    currentDoujinUploadSession = null;
    setAddDoujinInlineError('');
});
addDoujinForm?.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (isUploadingDoujin) {
        return;
    }

    if (!doujinArchiveInput?.files?.length) {
        event.preventDefault();
        setAddDoujinInlineError('Upload a ZIP archive.');
        setAddDoujinModal(true);
        return;
    }

    const selectedFile = doujinArchiveInput.files[0];
    if (!selectedFile.name.toLowerCase().endsWith('.zip')) {
        setAddDoujinInlineError('Upload a ZIP archive.');
        setAddDoujinModal(true);
        return;
    }

    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const fileKey = doujinFileKey(selectedFile);
    const canReuseUpload = currentDoujinUploadSession
        && currentDoujinUploadSession.fileKey === fileKey;

    setAddDoujinInlineError('');
    setAddDoujinBusy(true, submitAddDoujinBtn?.dataset.uploadingLabel || 'Uploading...');

    try {
        if (!canReuseUpload) {
            currentDoujinUploadSession = {
                uploadId: createDoujinUploadId(),
                totalChunks: Math.max(1, Math.ceil(selectedFile.size / doujinUploadChunkSizeBytes)),
                fileKey,
            };
            await uploadDoujinChunks(selectedFile, currentDoujinUploadSession, token);
        }

        const payload = await completeDoujinUpload(currentDoujinUploadSession, selectedFile, token);
        currentDoujinUploadSession = null;
        window.location.href = payload.redirect_url || window.location.href;
    } catch (error) {
        currentDoujinUploadSession = null;
        setAddDoujinInlineError(error instanceof Error ? error.message : 'Upload failed. Check the ZIP structure and try again.');
        setAddDoujinModal(true);
    } finally {
        setAddDoujinBusy(false);
    }
});
addDoujinModal?.addEventListener('click', (event) => {
    if (event.target === addDoujinModal) {
        setAddDoujinModal(false);
    }
});

window.addEventListener('DOMContentLoaded', () => {
    updateDoujinArchiveName();

    const shouldOpenAddDoujinModal = @json(session('open_add_doujin_modal', false));
    if (shouldOpenAddDoujinModal) {
        setAddDoujinModal(true);
    }
});
</script>
@endsection
