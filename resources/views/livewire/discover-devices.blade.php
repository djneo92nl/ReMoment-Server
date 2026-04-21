<div>
    {{-- Scan button --}}
    <div class="flex items-center gap-4 mb-8">
        <button wire:click="scan" wire:loading.attr="disabled"
                class="flex items-center gap-2 px-6 py-2.5 bg-gray-900 dark:bg-stone-700 text-white rounded-2xl text-sm font-medium hover:bg-gray-700 dark:hover:bg-stone-600 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
            <span wire:loading.remove wire:target="scan">
                <i class="fa-solid fa-magnifying-glass mr-1"></i>Scan Network
            </span>
            <span wire:loading wire:target="scan" class="flex items-center gap-2">
                <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                Scanning...
            </span>
        </button>

        @if($done && count($results) > 0)
            <div class="flex items-center gap-3 ml-auto">
                <button wire:click="toggleAll(true)" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 transition-colors">Select all</button>
                <span class="text-gray-300 dark:text-stone-600">|</span>
                <button wire:click="toggleAll(false)" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 transition-colors">Deselect all</button>
            </div>
        @endif
    </div>

    {{-- Scanning hint --}}
    <div wire:loading wire:target="scan" class="text-sm text-gray-500 dark:text-gray-400 mb-6 -mt-4">
        Listening for devices on the network — this takes a few seconds&hellip;
    </div>

    {{-- Results --}}
    @if($done)
        @if(count($results) === 0)
            <div class="flex flex-col items-center justify-center py-16 text-center bg-white dark:bg-stone-900 rounded-3xl border border-gray-200/70 dark:border-stone-800/80">
                <div class="w-16 h-16 bg-gray-100 dark:bg-stone-800 rounded-2xl flex items-center justify-center mb-4">
                    <i class="fa-solid fa-satellite-dish text-2xl text-gray-300 dark:text-stone-600"></i>
                </div>
                <p class="text-gray-700 dark:text-gray-300 font-medium mb-1">No new devices found</p>
                <p class="text-sm text-gray-500 dark:text-gray-500">All discoverable devices are already registered, or none responded.</p>
            </div>
        @else
            <div class="bg-white dark:bg-stone-900 rounded-3xl border border-gray-200/70 dark:border-stone-800/80 overflow-hidden mb-6">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-stone-800">
                            <th class="w-10 px-5 py-3.5"></th>
                            <th class="px-4 py-3.5 text-left font-medium text-gray-500 dark:text-gray-400">Name</th>
                            <th class="px-4 py-3.5 text-left font-medium text-gray-500 dark:text-gray-400 hidden sm:table-cell">IP Address</th>
                            <th class="px-4 py-3.5 text-left font-medium text-gray-500 dark:text-gray-400 hidden md:table-cell">Brand / Model</th>
                            <th class="px-4 py-3.5 text-left font-medium text-gray-500 dark:text-gray-400 hidden lg:table-cell">Driver</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-stone-800">
                        @foreach($results as $i => $row)
                            <tr class="{{ $row['selected'] ? '' : 'opacity-40' }} transition-opacity">
                                <td class="px-5 py-3.5">
                                    <input type="checkbox"
                                           wire:model="results.{{ $i }}.selected"
                                           class="rounded border-gray-300 dark:border-stone-600 text-gray-900 dark:bg-stone-800 focus:ring-0 cursor-pointer">
                                </td>
                                <td class="px-4 py-3">
                                    <input type="text"
                                           wire:model="results.{{ $i }}.name"
                                           class="w-full bg-transparent border-0 border-b border-transparent hover:border-gray-200 dark:hover:border-stone-700 focus:border-gray-300 dark:focus:border-stone-600 focus:ring-0 text-gray-900 dark:text-gray-100 text-sm px-0 py-0.5 transition-colors">
                                </td>
                                <td class="px-4 py-3.5 font-mono text-gray-500 dark:text-gray-400 hidden sm:table-cell">{{ $row['ip'] }}</td>
                                <td class="px-4 py-3.5 text-gray-700 dark:text-gray-300 hidden md:table-cell">
                                    {{ $row['brand'] }} {{ $row['product'] }}
                                </td>
                                <td class="px-4 py-3.5 hidden lg:table-cell">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-lg text-xs font-medium bg-gray-100 dark:bg-stone-800 text-gray-600 dark:text-gray-400">
                                        {{ $row['driver_name'] }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('devices.index') }}"
                   class="px-5 py-2.5 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 transition-colors">
                    Cancel
                </a>
                <button wire:click="addSelected"
                        wire:loading.attr="disabled"
                        @if(collect($results)->where('selected', true)->isEmpty()) disabled @endif
                        class="flex items-center gap-2 px-6 py-2.5 bg-gray-900 dark:bg-stone-700 text-white rounded-2xl text-sm font-medium hover:bg-gray-700 dark:hover:bg-stone-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                    <span wire:loading.remove wire:target="addSelected">
                        <i class="fa-solid fa-plus mr-1"></i>
                        Add {{ collect($results)->where('selected', true)->count() }} Device{{ collect($results)->where('selected', true)->count() !== 1 ? 's' : '' }}
                    </span>
                    <span wire:loading wire:target="addSelected">Saving&hellip;</span>
                </button>
            </div>
        @endif
    @endif
</div>
