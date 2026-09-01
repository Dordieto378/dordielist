@if(!empty($collectionOptions))
    <div class="relative mb-4">
        <label class="block text-sm font-medium text-gray-900 mb-2">COLLECTION</label>
        <select name="collection" onchange="redirectWithFilters()"
                class="appearance-none w-full px-3 py-2 border rounded-sm focus:border-red-600 focus:outline-none focus:ring-2 focus:ring-red-600 h-[2.5rem] text-gray-900 font-medium">
            <option value="" {{ ($selectedCollection ?? '') === '' ? 'selected' : '' }}>All</option>
            @foreach($collectionOptions as $collectionOption)
                <option value="{{ $collectionOption['value'] }}"
                        {{ ($selectedCollection ?? '') === $collectionOption['value'] ? 'selected' : '' }}>
                    {{ $collectionOption['label'] }}
                </option>
            @endforeach
        </select>
        <svg class="pointer-events-none absolute right-3 top-1/2 transform -translate-y-1/2 h-3 w-3 text-gray-400 mt-3.5"
             xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
        </svg>
    </div>

    <div class="relative mb-4">
        <label class="block text-sm font-medium text-gray-900 mb-2">COLLECTION BLACKLIST</label>
        <select name="collection_blacklist" onchange="redirectWithFilters()"
                class="appearance-none w-full px-3 py-2 border rounded-sm focus:border-red-600 focus:outline-none focus:ring-2 focus:ring-red-600 h-[2.5rem] text-gray-900 font-medium">
            <option value="" {{ ($selectedCollectionBlacklist ?? '') === '' ? 'selected' : '' }}>None</option>
            @foreach($collectionOptions as $collectionOption)
                <option value="{{ $collectionOption['value'] }}"
                        {{ ($selectedCollectionBlacklist ?? '') === $collectionOption['value'] ? 'selected' : '' }}>
                    {{ $collectionOption['label'] }}
                </option>
            @endforeach
        </select>
        <svg class="pointer-events-none absolute right-3 top-1/2 transform -translate-y-1/2 h-3 w-3 text-gray-400 mt-3.5"
             xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
        </svg>
    </div>
@endif
