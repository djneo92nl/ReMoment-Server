<div class="space-y-6">

    {{-- Feedback --}}
    @if($feedback)
        <div class="flex items-center gap-3 rounded-2xl px-5 py-3.5 text-sm
            {{ $feedbackIsError
                ? 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300'
                : 'bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300' }}">
            <i class="fa-solid {{ $feedbackIsError ? 'fa-circle-exclamation' : 'fa-circle-check' }}"></i>
            {{ $feedback }}
        </div>
    @endif

    {{-- Preset list --}}
    @if($presets->isEmpty() && !$creating)
        <div class="bg-white dark:bg-stone-900 rounded-3xl border border-gray-200/70 dark:border-stone-800/80 shadow-sm p-8 text-center">
            <p class="text-sm text-gray-400 dark:text-gray-600">No presets yet. Save a room group to activate it with one tap.</p>
        </div>
    @endif

    @foreach($presets as $preset)
        <div class="bg-white dark:bg-stone-900 rounded-2xl border border-gray-200/70 dark:border-stone-800/80 shadow-sm px-6 py-5 flex items-center gap-4">
            <div class="flex-1 min-w-0">
                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $preset['name'] }}</p>
                <p class="text-sm text-gray-400 dark:text-gray-600 mt-0.5 truncate">
                    {{ collect($preset['devices'])->pluck('name')->join(' · ') ?: 'No devices' }}
                </p>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <button wire:click="activate({{ $preset['id'] }})"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 hover:bg-gray-700 dark:hover:bg-gray-300 transition-colors disabled:opacity-50">
                    <i class="fa-solid fa-play text-xs"></i>
                    Activate
                </button>
                <button wire:click="delete({{ $preset['id'] }})"
                        wire:confirm="Delete this preset?"
                        class="p-2 rounded-xl text-gray-400 dark:text-gray-600 hover:text-red-500 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                    <i class="fa-solid fa-trash-can text-sm"></i>
                </button>
            </div>
        </div>
    @endforeach

    {{-- Create form --}}
    @if($creating)
        <div class="bg-white dark:bg-stone-900 rounded-2xl border border-gray-200/70 dark:border-stone-800/80 shadow-sm p-6">
            <h3 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">New Preset</h3>

            <div class="space-y-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Name</label>
                    <input wire:model="newName" type="text" placeholder="e.g. Morning Routine"
                           class="w-full max-w-sm rounded-xl border border-gray-200 dark:border-stone-700 bg-white dark:bg-stone-800 text-gray-900 dark:text-gray-100 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-gray-300 dark:focus:ring-stone-600 placeholder-gray-300 dark:placeholder-stone-600">
                    @error('newName') <p class="mt-1.5 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Devices</label>
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
                    @error('newDeviceIds') <p class="mt-1.5 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-3 pt-1">
                    <button wire:click="create"
                            class="px-5 py-2.5 rounded-xl text-sm font-medium bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 hover:bg-gray-700 dark:hover:bg-gray-300 transition-colors">
                        Save preset
                    </button>
                    <button wire:click="$set('creating', false)"
                            class="px-5 py-2.5 rounded-xl text-sm text-gray-500 dark:text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    @else
        <button wire:click="$set('creating', true)"
                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-medium border border-gray-200 dark:border-stone-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-stone-800/50 transition-colors">
            <i class="fa-solid fa-plus text-xs"></i>
            New preset
        </button>
    @endif

</div>
