<div class="space-y-6">
    <x-card class="p-5 sm:p-6">
        <h2 class="mb-4 text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600"><i class="fa-solid fa-plus mr-1.5"></i>New tag</h2>

        <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="tag-uid" value="Tag id (empty for a QR code)" />
                <x-text-input id="tag-uid" wire:model="uid" class="w-full" placeholder="04A31F92C45E80" data-test="uid" />
                <x-input-error :messages="$errors->get('uid')" class="mt-1" />
                @if($lastScanned)
                    <button type="button" wire:click="useLastScanned" class="mt-1 text-xs text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-300">Use the tag that was just scanned ({{ $lastScanned['uid'] }})</button>
                @else
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-600" wire:poll.5s>Tap an unknown tag on a reader and it shows up here.</p>
                @endif
            </div>
            <div>
                <x-input-label for="tag-label" value="Label (optional)" />
                <x-text-input id="tag-label" wire:model="label" class="w-full" placeholder="Blue card" />
            </div>

            <div>
                <x-input-label for="tag-type" value="Plays" />
                <x-select id="tag-type" wire:model.live="targetType" class="w-full">
                    @foreach($types as $type => $name)<option value="{{ $type }}">{{ $name }}</option>@endforeach
                </x-select>
            </div>
            <div>
                <x-input-label for="tag-search" value="Find it" />
                @if($targetId)
                    <div class="flex items-center justify-between gap-3 rounded-xl border border-gray-200 dark:border-stone-700 px-3 py-2 text-sm text-gray-800 dark:text-gray-200">
                        <span class="truncate" data-test="chosen">{{ $targetName }}</span>
                        <button type="button" wire:click="$set('targetId', null)" class="flex-none text-xs text-gray-500 underline">Change</button>
                    </div>
                @else
                    <x-text-input id="tag-search" type="search" wire:model.live.debounce.300ms="search" class="w-full" placeholder="Type a name" data-test="search" />
                @endif
                <x-input-error :messages="$errors->get('targetId')" class="mt-1" />
            </div>

            @if($results)
                <ul class="sm:col-span-2 divide-y divide-gray-100 rounded-2xl border border-gray-100 dark:divide-stone-800/70 dark:border-stone-800" data-test="results">
                    @foreach($results as $result)
                        <li class="flex items-center gap-3 px-4 py-2.5">
                            <span class="min-w-0 flex-1 truncate text-sm text-gray-900 dark:text-gray-100">{{ $result['name'] }}</span>
                            <x-secondary-button type="button" size="sm" wire:click="choose({{ $result['id'] }})">Use this</x-secondary-button>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div>
                <x-input-label for="tag-device" value="On" />
                <x-select id="tag-device" wire:model="deviceId" class="w-full">
                    <option value="">The reader's device</option>
                    @foreach($devices as $device)<option value="{{ $device->id }}">{{ $device->device_name }}</option>@endforeach
                </x-select>
            </div>
            <div class="flex items-end justify-between gap-4">
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" wire:model="shuffle" class="rounded border-gray-300 dark:border-stone-600"> Shuffle
                </label>
                <x-primary-button type="submit" size="md" data-test="save"><i class="fa-solid fa-plus"></i>Add tag</x-primary-button>
            </div>
        </form>
    </x-card>

    @if($tags->isEmpty())
        <x-empty-state icon="tags" title="No tags yet" text="Add a tag above: an NFC tag by its id, or leave the id empty and print the QR code." />
    @else
        <x-card flush>
            <div class="divide-y divide-gray-100 dark:divide-stone-800/70" data-test="tags">
                @foreach($tags as $tag)
                    <div class="px-4 py-3 sm:px-5" data-test="tag-{{ $tag['id'] }}">
                        <div class="flex items-center gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $tag['target']['name'] ?? 'No longer there' }}</p>
                                <p class="truncate text-xs text-gray-400 dark:text-gray-600">
                                    {{ $types[$tag['target']['type']] ?? ucfirst($tag['target']['type']) }}
                                    · {{ $tag['label'] ?: $tag['uid'] }}
                                    · {{ $tag['device']['name'] ?? "the reader's device" }}{{ $tag['shuffle'] ? ' · shuffle' : '' }}
                                </p>
                            </div>
                            <button type="button" wire:click="show({{ $tag['id'] }})" class="flex-none text-xs text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-300">QR code</button>
                            <button type="button" wire:click="remove({{ $tag['id'] }})" wire:confirm="Remove this tag?" class="flex-none text-xs text-gray-400 underline hover:text-red-600">Remove</button>
                        </div>
                        @if($showing === $tag['id'] && $qr)
                            <div class="mt-3 flex flex-wrap items-center gap-4">
                                <div class="rounded-xl bg-white p-2">{!! $qr !!}</div>
                                <p class="min-w-0 break-all text-xs text-gray-500">{{ $tag['url'] }}</p>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif
</div>
