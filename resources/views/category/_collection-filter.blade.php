@if(!empty($collectionOptions))
    @php
        $collectionLabelByValue = collect($collectionOptions)
            ->mapWithKeys(fn (array $option) => [(string) $option['value'] => $option['label']]);

        $collectionFilters = [
            [
                'type' => 'Collection',
                'label' => 'COLLECTION',
                'selected' => $selectedCollection ?? [],
                'placeholder' => 'Select Collection',
            ],
            [
                'type' => 'CollectionBlacklist',
                'label' => 'COLLECTION BLACKLIST',
                'selected' => $selectedCollectionBlacklist ?? [],
                'placeholder' => 'Select Collection Blacklist',
            ],
        ];
    @endphp

    @foreach($collectionFilters as $collectionFilter)
        @php
            $selectedCollectionValues = collect($collectionFilter['selected'])
                ->map(fn ($value) => (string) $value)
                ->filter(fn (string $value) => $collectionLabelByValue->has($value))
                ->values();
        @endphp

        <div class="relative mb-4 {{ $loop->first ? 'mt-4' : '' }}">
            <label class="block text-sm font-medium text-gray-900 mb-2">{{ $collectionFilter['label'] }}</label>

            <button id="dropdownButton{{ $collectionFilter['type'] }}" type="button"
                    class="w-full min-h-[2.5rem] px-3 py-2 border rounded-sm bg-white text-left flex flex-wrap items-center gap-2">
                <div id="selected{{ $collectionFilter['type'] }}" class="flex flex-wrap gap-2 flex-1">
                    @if($selectedCollectionValues->isEmpty())
                        <span class="text-gray-900 font-medium text-sm">{{ $collectionFilter['placeholder'] }}</span>
                    @else
                        @foreach($selectedCollectionValues as $selectedCollectionValue)
                            <span class="px-3 py-2 rounded-[0.2rem] bg-gray-200 text-gray-900 text-sm flex items-center transition-all duration-200 ease-in-out">
                                {{ $collectionLabelByValue[$selectedCollectionValue] }}
                                <span
                                    role="button"
                                    tabindex="0"
                                    data-remove-dropdown-name="{{ $selectedCollectionValue }}"
                                    data-remove-dropdown-type="{{ $collectionFilter['type'] }}"
                                    class="ml-2 cursor-pointer text-gray-500 hover:text-gray-800 select-none"
                                >
                                    &times;
                                </span>
                            </span>
                        @endforeach
                    @endif
                </div>
                <svg class="pointer-events-none h-3 w-3 text-gray-400"
                     xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="4" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
            </button>

            <div id="dropdownMenu{{ $collectionFilter['type'] }}"
                 class="absolute left-0 w-full bg-white border rounded-sm shadow-lg hidden z-10 max-h-[400px] overflow-y-auto">
                <ul>
                    @foreach($collectionOptions as $collectionOption)
                        <li class="px-1.5 py-[1px] cursor-pointer text-gray-900 font-medium text-sm transition-all duration-200 ease-in-out bg-white"
                            data-dropdown-name="{{ $collectionOption['value'] }}"
                            data-dropdown-type="{{ $collectionFilter['type'] }}">
                            <span class="block w-full h-full px-3 py-2 rounded-[0.2rem] transition-all duration-200 ease-in-out hover:bg-red-600 hover:text-white hover:font-semibold">
                                {{ $collectionOption['label'] }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endforeach
@endif
