<div class="space-y-6">

    {{-- Feedback --}}
    @if($feedback)
        <x-alert :type="$feedbackIsError ? 'error' : 'success'">{{ $feedback }}</x-alert>
    @endif

    {{-- Preset list --}}
    @if($presets->isEmpty() && !$creating)
        <x-empty-state icon="layer-group" text="No presets yet. Save a room group to activate it with one tap." />
    @endif

    @foreach($presets as $preset)
        <x-card compact class="px-6 py-5 flex items-center gap-4">
            <div class="flex-1 min-w-0">
                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $preset['name'] }}</p>
                <p class="text-sm text-gray-400 dark:text-gray-600 mt-0.5 truncate">
                    {{ collect($preset['devices'])->pluck('name')->join(' · ') ?: 'No devices' }}
                </p>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <x-primary-button size="md" type="button" wire:click="activate({{ $preset['id'] }})" wire:loading.attr="disabled">
                    <i class="fa-solid fa-play text-xs"></i>
                    Activate
                </x-primary-button>
                <button wire:click="delete({{ $preset['id'] }})"
                        wire:confirm="Delete this preset?"
                        class="p-2 rounded-xl text-gray-400 dark:text-gray-600 hover:text-red-500 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                    <i class="fa-solid fa-trash-can text-sm"></i>
                </button>
            </div>
        </x-card>
    @endforeach

    {{-- Create form --}}
    @if($creating)
        <x-card compact class="p-6">
            <h3 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">New Preset</h3>

            <div class="space-y-5">
                <div>
                    <x-input-label value="Name" class="mb-1.5" />
                    <x-text-input wire:model="newName" type="text" placeholder="e.g. Morning Routine" :invalid="$errors->has('newName')" class="w-full max-w-sm" />
                    <x-input-error :messages="$errors->get('newName')" class="mt-1.5" />
                </div>

                <div>
                    <x-input-label value="Devices" class="mb-2" />
                    @if($multiroomDevices->isEmpty())
                        <p class="text-sm text-gray-400 dark:text-gray-600">No multiroom-capable devices found.</p>
                    @else
                        <div class="space-y-2">
                            @foreach($multiroomDevices as $device)
                                <label class="flex items-center gap-3 cursor-pointer group">
                                    <input type="checkbox" wire:model="newDeviceIds" value="{{ $device['id'] }}"
                                           class="w-4 h-4 rounded border-gray-300 dark:border-stone-600 text-gray-900 dark:text-gray-100 focus:ring-gray-400 dark:focus:ring-stone-500">
                                    <span class="text-sm text-gray-700 dark:text-gray-300 group-hover:text-gray-900 dark:group-hover:text-gray-100 transition-colors">
                                        {{ $device['name'] }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                    <x-input-error :messages="$errors->get('newDeviceIds')" class="mt-1.5" />
                </div>

                <div class="flex items-center gap-3 pt-1">
                    <x-primary-button size="lg" type="button" wire:click="create">Save preset</x-primary-button>
                    <button wire:click="$set('creating', false)"
                            class="px-5 py-2.5 rounded-xl text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
                        Cancel
                    </button>
                </div>
            </div>
        </x-card>
    @else
        <x-secondary-button wire:click="$set('creating', true)">
            <i class="fa-solid fa-plus text-xs"></i>
            New preset
        </x-secondary-button>
    @endif

</div>
