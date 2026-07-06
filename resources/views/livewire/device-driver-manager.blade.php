<div class="space-y-6">

    @if(session('success'))
        <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-2xl px-6 py-4 text-sm text-emerald-800 dark:text-emerald-300">
            <i class="fa-solid fa-circle-check mr-2"></i>{{ session('success') }}
        </div>
    @endif

    @foreach($groups as $driverName => $group)
        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 overflow-hidden">

            <div class="flex items-center justify-between gap-4 px-8 py-6 border-b border-gray-100 dark:border-stone-800">
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $driverName }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-500">{{ $group['devices']->count() }} {{ $group['devices']->count() === 1 ? 'device' : 'devices' }} registered</p>
                </div>

                @if($group['discoverable'])
                    <button wire:click="discover('{{ $driverName }}')" wire:loading.attr="disabled" wire:target="discover('{{ $driverName }}')"
                            class="flex items-center gap-2 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-2xl text-sm font-medium transition-colors disabled:opacity-60">
                        <span wire:loading.remove wire:target="discover('{{ $driverName }}')">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <span class="hidden sm:inline">Discover</span>
                        </span>
                        <span wire:loading wire:target="discover('{{ $driverName }}')" class="flex items-center gap-2">
                            <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            Scanning&hellip;
                        </span>
                    </button>
                @endif
            </div>

            {{-- Registered devices --}}
            @if($group['devices']->isEmpty())
                <div class="px-8 py-8 text-sm text-gray-400 dark:text-gray-600">No {{ $driverName }} devices registered yet.</div>
            @else
                <div class="divide-y divide-gray-50 dark:divide-stone-800/50">
                    @foreach($group['devices'] as $device)
                        <div class="flex items-center justify-between gap-4 px-8 py-4 hover:bg-gray-50/50 dark:hover:bg-stone-800/20 transition-colors {{ $device->hidden ? 'opacity-40' : '' }}">
                            <div>
                                <a href="{{ route('devices.show', $device) }}" class="text-sm font-medium text-gray-900 dark:text-gray-100 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                    {{ $device->device_name }}
                                </a>
                                <div class="text-xs text-gray-400 dark:text-gray-600 mt-0.5 font-mono">
                                    {{ $device->ip_address ?: 'virtual' }} &middot; {{ $device->device_product_type }}
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                <button wire:click="toggleHidden({{ $device->id }})"
                                        title="{{ $device->hidden ? 'Unhide' : 'Hide' }}"
                                        class="flex items-center justify-center w-8 h-8 rounded-xl text-gray-500 dark:text-gray-500 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-stone-800 transition-colors">
                                    <i class="fa-solid {{ $device->hidden ? 'fa-eye' : 'fa-eye-slash' }} text-xs"></i>
                                </button>
                                <button wire:click="deleteDevice({{ $device->id }})"
                                        wire:confirm="Remove {{ addslashes($device->device_name) }}? This cannot be undone."
                                        class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-500 dark:text-gray-500 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-xl transition-colors">
                                    <i class="fa-solid fa-trash-can"></i>
                                    <span class="hidden sm:inline">Remove</span>
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Discovered but not yet added --}}
            @if(!empty($discovered[$driverName]))
                <div class="px-8 py-5 bg-indigo-50/50 dark:bg-indigo-900/10 border-t border-indigo-100 dark:border-indigo-900/30 space-y-3">
                    <p class="text-xs font-medium uppercase tracking-wider text-indigo-500 dark:text-indigo-400">Found on network</p>
                    @forelse($discovered[$driverName] as $i => $row)
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $row['name'] }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-500 font-mono ml-2">{{ $row['ip'] }}</span>
                                <span class="text-xs text-gray-400 dark:text-gray-600 ml-2">{{ $row['brand'] }} {{ $row['product'] }}</span>
                            </div>
                            <button wire:click="addDiscovered('{{ $driverName }}', {{ $i }})"
                                    class="flex items-center gap-1.5 px-3 py-1.5 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 hover:bg-emerald-100 dark:hover:bg-emerald-900/40 rounded-xl text-xs font-medium transition-colors">
                                <i class="fa-solid fa-plus"></i>Add
                            </button>
                        </div>
                    @empty
                        <p class="text-sm text-gray-400 dark:text-gray-600">All found devices have been added.</p>
                    @endforelse
                </div>
            @elseif(isset($discovered[$driverName]))
                <div class="px-8 py-5 bg-gray-50/50 dark:bg-stone-800/20 border-t border-gray-100 dark:border-stone-800 text-sm text-gray-400 dark:text-gray-600">
                    No new {{ $driverName }} devices found on the network.
                </div>
            @endif

            {{-- Add by IP --}}
            @if(!$group['virtual'])
                <form wire:submit="addManual('{{ $driverName }}')" class="flex flex-wrap items-end gap-3 px-8 py-5 border-t border-gray-100 dark:border-stone-800">
                    <div class="flex-1 min-w-[10rem]">
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-500 mb-1.5">Name</label>
                        <input type="text" wire:model="manualForm.{{ $driverName }}.name" placeholder="e.g. Living Room"
                               class="w-full rounded-xl border {{ $errors->has("manualForm.$driverName.name") ? 'border-red-400' : 'border-gray-200 dark:border-stone-700' }} dark:bg-stone-800 dark:text-gray-100 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900 dark:focus:ring-stone-500 focus:border-transparent">
                    </div>
                    <div class="flex-1 min-w-[10rem]">
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-500 mb-1.5">IP address</label>
                        <input type="text" wire:model="manualForm.{{ $driverName }}.ip" placeholder="192.168.1.x"
                               class="w-full rounded-xl border {{ $errors->has("manualForm.$driverName.ip") ? 'border-red-400' : 'border-gray-200 dark:border-stone-700' }} dark:bg-stone-800 dark:text-gray-100 px-3.5 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-gray-900 dark:focus:ring-stone-500 focus:border-transparent">
                    </div>
                    <div class="flex-1 min-w-[10rem]">
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-500 mb-1.5">Product</label>
                        <select wire:model="manualForm.{{ $driverName }}.product_key"
                                class="w-full rounded-xl border {{ $errors->has("manualForm.$driverName.product_key") ? 'border-red-400' : 'border-gray-200 dark:border-stone-700' }} dark:bg-stone-800 dark:text-gray-100 px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900 dark:focus:ring-stone-500 focus:border-transparent">
                            <option value="">Select product&hellip;</option>
                            @foreach($group['products'] as $product)
                                <option value="{{ $product['key'] }}">{{ $product['brand'] }} {{ $product['product'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-primary-button type="submit">
                        <i class="fa-solid fa-plus"></i>
                        <span class="hidden sm:inline">Add</span>
                    </x-primary-button>
                </form>
            @else
                <div class="px-8 py-5 border-t border-gray-100 dark:border-stone-800 text-sm text-gray-400 dark:text-gray-600">
                    Virtual devices are provisioned automatically — see
                    <a href="{{ route('settings.spotify-connect') }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">Spotify Connect</a>.
                </div>
            @endif
        </div>
    @endforeach

</div>
