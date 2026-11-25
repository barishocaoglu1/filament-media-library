@php
    $statePath = $getStatePath();
    $items = $getState() ?? [];
    $itemsCount = count($items);
    $isMultiple = $isMultiple();
    $maxItems = $getMaxItems();
    $shouldDisplayAsList = $shouldDisplayAsList();
    $constrained = $isConstrained();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">

    <div class="curator-media-picker w-full">
        <ul
            @class([
                'w-full',
                'grid gap-6 sm:grid-cols-2 md:grid-cols-3' => $isMultiple && ! $shouldDisplayAsList,
                'overflow-visible bg-white border border-gray-300 rounded-lg shadow-sm divide-y divide-gray-300 dark:border-gray-700 dark:text-white dark:divide-gray-700 dark:bg-white/5' => $itemsCount > 0 && $shouldDisplayAsList,
            ])
            x-sortable
            wire:end.stop="mountAction('reorder', { items: $event.target.sortable.toArray() }, { schemaComponent: '{{ $getKey() }}' })"
        >
            @foreach ($items as $uuid => $item)
                @php
                    $pickerItemActions = \Filament\Actions\ActionGroup::make([
                        $getAction('view')(['url' => $item['url']]),
                        $getAction('edit')(['id' => $item['id']]),
                        $getAction('download')(['uuid' => $uuid]),
                        $getAction('remove')(['uuid' => $uuid]),
                    ])
                        ->color('gray')
                        ->size('xs')
                        ->dropdownPlacement('bottom-end')
                        ->dropdownTeleport();
                @endphp
                <li
                    wire:key="{{ $this->getId() }}.{{ $uuid }}.{{ $field::class }}.item"
                    x-sortable-item="{{ $uuid }}"
                    {{ $attributes->merge($getExtraAttributes())->class(['relative w-full overflow-visible']) }}
                >
                    @if ($shouldDisplayAsList)
                        <div class="w-full flex items-center gap-4 text-xs pe-2 overflow-visible">
                            <div class="curator-picker-list-preview flex-shrink-0 h-12 w-12 checkered">
                                <x-curator::display
                                    :item="$item"
                                    :src="$item['thumbnail_url']"
                                    :lazy="true"
                                    icon-classes="size-6"
                                />
                            </div>
                            <div class="curator-picker-list-details min-w-0 overflow-hidden py-2">
                                <p>{{ $item['pretty_name'] }}</p>
                            </div>
                            <div class="curator-picker-list-details flex-shrink-0 ml-auto py-2">
                                <p>{{ curator()->sizeForHumans($item['size']) }}</p>
                            </div>
                            <div class="curator-picker-list-actions flex-shrink-0 overflow-visible">
                                <div class="relative flex items-center overflow-visible">
                                    @if ($isMultiple)
                                        <div
                                            x-sortable-handle
                                            class="flex items-center justify-center flex-none w-8 h-8 transition text-gray-400 hover:text-gray-300"
                                        >
                                            {{ $getAction('reorder') }}
                                        </div>
                                    @endif

                                    <div class="flex items-center justify-center flex-none overflow-visible">
                                        {{ $pickerItemActions }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="relative block w-full">
                            <div
                                @class([
                                    'relative block w-full overflow-hidden border border-gray-300 rounded-lg shadow-sm dark:bg-gray-800 dark:border-gray-700 dark:text-white flex justify-center checkered',
                                    'h-64' => ! curator()->isVideo($item['ext']),
                                ])
                            >
                                <x-curator::display
                                    :item="$item"
                                    :src="$constrained ? $item['large_url'] : $item['medium_url']"
                                    :lazy="true"
                                    icon-classes="size-24"
                                    :constrained="$constrained"
                                />

                                @if (! curator()->isVideo($item['ext']))
                                    <x-curator::display.info-overlay :label="$item['pretty_name']" :size="$item['size']" />
                                @endif
                            </div>

                            <div class="absolute top-0 right-0 z-20">
                                <div class="relative flex items-center bg-gray-950 divide-x divide-gray-700 rounded-bl-lg shadow-md overflow-visible">
                                    @if ($isMultiple)
                                        <div
                                            x-sortable-handle
                                            class="flex items-center justify-center flex-none w-10 h-10 transition text-gray-400 hover:text-gray-300"
                                        >
                                            {{ $getAction('reorder') }}
                                        </div>
                                    @endif

                                    <div class="flex items-center justify-center flex-none overflow-visible">
                                        {{ $pickerItemActions }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        <div
            @class([
                'flex items-center gap-4',
                'mt-4' => $itemsCount > 0
            ])
        >
            @if ($itemsCount === 0 || $isMultiple)
                @if (! $maxItems || $itemsCount < $maxItems)
                    {{ $getAction('launchPanel') }}
                @endif
            @endif
            @if ($itemsCount > 1)
                {{ $getAction('removeAll') }}
            @endif
        </div>
    </div>
</x-dynamic-component>
