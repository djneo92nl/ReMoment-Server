<div class="space-y-6">
    <div>
        <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Sync groups</h2>
        <p class="text-sm text-gray-500 dark:text-gray-500 mt-1">
            For devices that can't group natively: one leads, the others play the same queue and are kept in step by the server.
            Progress is matched within about two seconds, so it suits neighbouring rooms rather than one room. Library tracks and radio only, not Spotify.
        </p>
    </div>

    @if($feedback)
        <x-alert :type="$feedbackIsError ? 'error' : 'success'">{{ $feedback }}</x-alert>
    @endif

    @foreach($groups as $group)
        <x-card compact class="px-6 py-5" wire:key="sync-{{ $group->id }}">
            <div class="flex items-center gap-4">
                <div class="flex-1 min-w-0">
                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ $group->name ?: $group->leader->device_name.' + '.$group->members->count() }}</p>
                    <p class="text-sm text-gray-400 dark:text-gray-600 mt-0.5">
                        <i class="fa-solid fa-crown text-xs"></i> {{ $group->leader->device_name }} leads
                    </p>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <x-secondary-button type="button" wire:click="resync({{ $group->id }})" wire:loading.attr="disabled" title="Restart the members on what the leader plays">
                        <i class="fa-solid fa-rotate text-xs"></i>
                        Resync
                    </x-secondary-button>
                    <button wire:click="dissolve({{ $group->id }})" wire:confirm="End this group? The members stop playing."
                            class="p-2 rounded-xl text-gray-400 dark:text-gray-600 hover:text-red-500 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                        <i class="fa-solid fa-trash-can text-sm"></i>
                    </button>
                </div>
            </div>

            <div class="mt-4 space-y-2">
                @foreach($group->members as $member)
                    <div class="flex items-center gap-3 text-sm" wire:key="sync-{{ $group->id }}-{{ $member->device_id }}">
                        <span class="flex-1 min-w-0 truncate text-gray-700 dark:text-gray-300">{{ $member->device->device_name }}</span>
                        <span class="flex items-center gap-1 text-gray-500" title="Trim: positive plays ahead of the leader">
                            <button type="button" wire:click="nudge({{ $group->id }}, {{ $member->device_id }}, -250)" class="px-2 py-1 rounded-lg hover:bg-gray-100 dark:hover:bg-stone-800">−</button>
                            <span class="w-20 text-center tabular-nums">{{ $member->offset_ms > 0 ? '+' : '' }}{{ $member->offset_ms }} ms</span>
                            <button type="button" wire:click="nudge({{ $group->id }}, {{ $member->device_id }}, 250)" class="px-2 py-1 rounded-lg hover:bg-gray-100 dark:hover:bg-stone-800">+</button>
                        </span>
                        <button type="button" wire:click="removeMember({{ $group->id }}, {{ $member->device_id }})" title="Remove from the group"
                                class="p-1.5 rounded-lg text-gray-400 dark:text-gray-600 hover:text-red-500 dark:hover:text-red-400">
                            <i class="fa-solid fa-xmark text-sm"></i>
                        </button>
                    </div>
                @endforeach
            </div>
        </x-card>
    @endforeach

    @if($creating)
        <x-card compact class="p-6">
            <h3 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">New sync group</h3>

            <div class="space-y-5">
                <div>
                    <x-input-label value="Name (optional)" class="mb-1.5" />
                    <x-text-input wire:model="name" type="text" placeholder="e.g. Downstairs" class="w-full max-w-sm" />
                </div>

                @if($candidates->count() < 2)
                    <p class="text-sm text-gray-400 dark:text-gray-600">A group needs at least two free devices that can play the library or radio.</p>
                @else
                    <div>
                        <x-input-label value="Leader" class="mb-2" />
                        <div class="space-y-2">
                            @foreach($candidates as $device)
                                <label class="flex items-center gap-3 cursor-pointer text-sm text-gray-700 dark:text-gray-300">
                                    <input type="radio" wire:model="leaderId" value="{{ $device->id }}" class="w-4 h-4 border-gray-300 dark:border-stone-600">
                                    {{ $device->device_name }}
                                </label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('leaderId')" class="mt-1.5" />
                    </div>

                    <div>
                        <x-input-label value="Follows the leader" class="mb-2" />
                        <div class="space-y-2">
                            @foreach($candidates as $device)
                                <label class="flex items-center gap-3 cursor-pointer text-sm text-gray-700 dark:text-gray-300">
                                    <input type="checkbox" wire:model="memberIds" value="{{ $device->id }}" class="w-4 h-4 rounded border-gray-300 dark:border-stone-600">
                                    {{ $device->device_name }}
                                </label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('memberIds')" class="mt-1.5" />
                    </div>
                @endif

                <div class="flex items-center gap-3 pt-1">
                    <x-primary-button size="lg" type="button" wire:click="create" wire:loading.attr="disabled">Make group</x-primary-button>
                    <button wire:click="$set('creating', false)" class="px-5 py-2.5 rounded-xl text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">Cancel</button>
                </div>
            </div>
        </x-card>
    @else
        <x-secondary-button wire:click="$set('creating', true)">
            <i class="fa-solid fa-plus text-xs"></i>
            New sync group
        </x-secondary-button>
    @endif
</div>
