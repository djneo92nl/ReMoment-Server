<div class="space-y-6">

    @if(session('success'))
        <x-alert type="success">{{ session('success') }}</x-alert>
    @endif

    {{-- Discovered devices: found by scans (this page, device:discover, the hourly scan), added only from here --}}
    <x-card flush>
        <div class="flex flex-wrap items-center justify-between gap-4 px-8 py-6">
            <div>
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Discovered devices</h2>
                <p class="text-sm text-gray-500">
                    {{ $pending->count() }} waiting to be added
                    @if($scanSummary) &middot; {{ $scanSummary }} @endif
                </p>
            </div>
            <div class="flex items-center gap-2">
                @if($pending->count() > 1)
                    <x-secondary-button type="button" wire:click="addAll" wire:confirm="Add all {{ $pending->count() }} devices?">
                        <i class="fa-solid fa-plus"></i>Add all
                    </x-secondary-button>
                @endif
                <x-primary-button type="button" wire:click="scan" wire:loading.attr="disabled" wire:target="scan">
                    <span wire:loading.remove wire:target="scan">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <span class="hidden sm:inline">Scan network</span>
                    </span>
                    <span wire:loading wire:target="scan" class="flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Scanning&hellip;
                    </span>
                </x-primary-button>
            </div>
        </div>

        @if(count($diagnostics) > 0)
            <div class="px-8 pb-5">
                <x-alert type="warning" icon="circle-info">
                    <ul class="space-y-1">
                        @foreach($diagnostics as $note)
                            <li>{{ $note }}</li>
                        @endforeach
                    </ul>
                </x-alert>
            </div>
        @endif

        @if($pending->isEmpty())
            <div class="px-8 py-6 border-t border-gray-100 dark:border-stone-800 text-sm text-gray-400 dark:text-gray-600">
                Nothing waiting. Scans run hourly in the background, or press Scan network.
            </div>
        @else
            <div class="divide-y divide-gray-50 dark:divide-stone-800/50 border-t border-gray-100 dark:border-stone-800">
                @foreach($pending as $record)
                    <div wire:key="pending-{{ $record->id }}" class="flex flex-wrap items-center justify-between gap-3 px-8 py-4">
                        <div class="min-w-0 flex-1">
                            <input type="text" wire:model="names.{{ $record->id }}" placeholder="{{ $record->device_name }}"
                                   class="w-full max-w-xs bg-transparent border-0 border-b border-transparent hover:border-gray-200 dark:hover:border-stone-700 focus:border-gray-300 dark:focus:border-stone-600 focus:ring-0 text-gray-900 dark:text-gray-100 placeholder-gray-900 dark:placeholder-gray-100 text-sm font-medium px-0 py-0.5 transition-colors">
                            <div class="text-xs text-gray-400 dark:text-gray-600 mt-0.5 font-mono">
                                {{ $record->ip_address }} &middot; {{ $record->device_brand_name }} {{ $record->device_product_type }}
                            </div>
                        </div>
                        <x-badge class="hidden md:inline-flex">{{ $record->device_driver_name }}</x-badge>
                        <div class="flex items-center gap-1">
                            <button wire:click="ignore({{ $record->id }})" title="Ignore"
                                    class="px-3 py-1.5 text-xs font-medium text-gray-500 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-stone-800 rounded-xl transition-colors">
                                Ignore
                            </button>
                            <x-secondary-button size="sm" wire:click="addDiscovered({{ $record->id }})">
                                <i class="fa-solid fa-plus"></i>Add
                            </x-secondary-button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="px-8 py-5 border-t border-gray-100 dark:border-stone-800">
            <x-input-label for="discover-hosts" class="mb-1.5">
                Known device IPs <span class="text-gray-400 dark:text-stone-500">(optional, e.g. a Sonos; helps when network scanning is blocked)</span>
            </x-input-label>
            <x-text-input id="discover-hosts" type="text" wire:model="hosts" placeholder="192.168.1.29" class="w-full max-w-md" />
        </div>

        @if($ignored->isNotEmpty())
            <details class="border-t border-gray-100 dark:border-stone-800">
                <summary class="px-8 py-4 text-sm text-gray-500 cursor-pointer select-none">{{ $ignored->count() }} ignored</summary>
                <div class="divide-y divide-gray-50 dark:divide-stone-800/50">
                    @foreach($ignored as $record)
                        <div wire:key="ignored-{{ $record->id }}" class="flex items-center justify-between gap-4 px-8 py-3 opacity-70">
                            <div class="text-sm text-gray-700 dark:text-gray-300">
                                {{ $record->device_name }}
                                <span class="text-xs text-gray-400 font-mono ml-2">{{ $record->ip_address }}</span>
                            </div>
                            <button wire:click="restore({{ $record->id }})" class="text-xs font-medium text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">Restore</button>
                        </div>
                    @endforeach
                </div>
            </details>
        @endif
    </x-card>

    @foreach($groups as $groupKey => $group)
        <x-card flush x-data="{ open: {{ $group['devices']->isNotEmpty() ? 'true' : 'false' }} }">

            <button type="button" @click="open = !open" class="flex w-full items-center justify-between gap-4 px-8 py-5 text-left">
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $group['label'] }}</h2>
                    <p class="text-sm text-gray-500">{{ $group['devices']->count() }} {{ $group['devices']->count() === 1 ? 'device' : 'devices' }} registered @if(count($group['drivers']) > 1) &middot; {{ implode(', ', array_keys($group['drivers'])) }}@endif</p>
                </div>
                <i class="fa-solid fa-chevron-down text-xs text-gray-400 transition-transform" :class="open && 'rotate-180'"></i>
            </button>

            <div x-show="open" x-cloak class="border-t border-gray-100 dark:border-stone-800">

            {{-- Registered devices --}}
            @if($group['devices']->isEmpty())
                <div class="px-8 py-8 text-sm text-gray-400 dark:text-gray-600">No {{ $group['label'] }} devices registered yet.</div>
            @else
                <div class="divide-y divide-gray-50 dark:divide-stone-800/50">
                    @foreach($group['devices'] as $device)
                        <div class="flex items-center justify-between gap-4 px-8 py-4 hover:bg-gray-50/50 dark:hover:bg-stone-800/20 transition-colors {{ $device->hidden ? 'opacity-40' : '' }}">
                            <div>
                                <a href="{{ route('devices.show', $device) }}" class="text-sm font-medium text-gray-900 dark:text-gray-100 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                    {{ $device->device_name }}
                                </a>
                                <div class="text-xs text-gray-400 dark:text-gray-600 mt-0.5 font-mono">
                                    {{ $device->ip_address ?: 'virtual' }} &middot; {{ $device->device_product_type }}@if(count($group['drivers']) > 1) &middot; {{ $device->device_driver_name }}@endif
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                <a href="{{ route('devices.edit', [$device, 'from' => 'settings']) }}" title="Edit"
                                   class="flex items-center justify-center w-8 h-8 rounded-xl text-gray-500 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-stone-800 transition-colors">
                                    <i class="fa-solid fa-pen-to-square text-xs"></i>
                                </a>
                                <button wire:click="toggleHidden({{ $device->id }})"
                                        title="{{ $device->hidden ? 'Unhide' : 'Hide' }}"
                                        class="flex items-center justify-center w-8 h-8 rounded-xl text-gray-500 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-stone-800 transition-colors">
                                    <i class="fa-solid {{ $device->hidden ? 'fa-eye' : 'fa-eye-slash' }} text-xs"></i>
                                </button>
                                <button wire:click="deleteDevice({{ $device->id }})"
                                        wire:confirm="Remove {{ addslashes($device->device_name) }}? This cannot be undone."
                                        class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-500 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-xl transition-colors">
                                    <i class="fa-solid fa-trash-can"></i>
                                    <span class="hidden sm:inline">Remove</span>
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Add by IP --}}
            @if(!$group['virtual'])
                <form wire:submit="addManual('{{ $groupKey }}')" class="flex flex-wrap items-end gap-3 px-8 py-5 border-t border-gray-100 dark:border-stone-800">
                    <div class="flex-1 min-w-[10rem]">
                        <x-input-label value="Name" class="mb-1.5" />
                        <x-text-input type="text" wire:model="manualForm.{{ $groupKey }}.name" placeholder="e.g. Living Room"
                                      :invalid="$errors->has('manualForm.'.$groupKey.'.name')" class="w-full" />
                    </div>
                    <div class="flex-1 min-w-[10rem]">
                        <x-input-label value="IP address" class="mb-1.5" />
                        <x-text-input type="text" wire:model="manualForm.{{ $groupKey }}.ip" placeholder="192.168.1.x"
                                      :invalid="$errors->has('manualForm.'.$groupKey.'.ip')" class="w-full font-mono" />
                    </div>
                    <div class="flex-1 min-w-[10rem]">
                        <x-input-label value="Product" class="mb-1.5" />
                        <x-select wire:model="manualForm.{{ $groupKey }}.product_key" class="w-full py-2.5" :invalid="$errors->has('manualForm.'.$groupKey.'.product_key')">
                            <option value="">Select product&hellip;</option>
                            @foreach(collect($group['products'])->groupBy('driver_name') as $driver => $products)
                                <optgroup label="{{ $driver }}">
                                    @foreach($products as $product)
                                        <option value="{{ $product['key'] }}">{{ $product['brand'] }} {{ $product['product'] }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </x-select>
                    </div>
                    <x-primary-button type="submit">
                        <i class="fa-solid fa-plus"></i>
                        <span class="hidden sm:inline">Add</span>
                    </x-primary-button>
                </form>
            @else
                <div class="px-8 py-5 border-t border-gray-100 dark:border-stone-800 text-sm text-gray-400 dark:text-gray-600">
                    Virtual devices are provisioned automatically — see
                    <a href="{{ route('settings.spotify-connect') }}" class="text-gray-900 dark:text-gray-100 underline">Spotify Connect</a>.
                </div>
            @endif
            </div>
        </x-card>
    @endforeach

</div>
