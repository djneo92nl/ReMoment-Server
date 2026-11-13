<div class="space-y-6">
    @if($notice)
        <div role="status" class="rounded-2xl border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20 px-5 py-3 text-sm text-emerald-800 dark:text-emerald-300">{{ $notice }}</div>
    @endif
    @if($problem)
        <div role="alert" class="rounded-2xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 px-5 py-3 text-sm text-red-800 dark:text-red-300">{{ $problem }}</div>
    @endif

    <x-card class="p-5 sm:p-6">
        <h2 class="mb-4 text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600"><i class="fa-solid fa-plus mr-1.5"></i>Add a clip</h2>
        <form wire:submit="upload" class="flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1">
                <x-input-label for="clip-file" value="Sound file (the first {{ \App\Domain\Playback\AnnouncementClips::MAX_SECONDS }} seconds are kept)" />
                <input id="clip-file" type="file" wire:model="file" accept="audio/*" class="block w-full text-sm text-gray-700 dark:text-gray-300" />
                <x-input-error :messages="$errors->get('file')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="clip-label" value="Name" />
                <x-text-input id="clip-label" wire:model="label" maxlength="64" placeholder="Doorbell" />
            </div>
            <x-primary-button type="submit" size="md" wire:loading.attr="disabled" wire:target="upload,file">Add</x-primary-button>
        </form>
    </x-card>

    <div class="flex items-center gap-3">
        <x-input-label for="test-device" value="Try on" />
        <x-select id="test-device" wire:model="deviceId">
            <option value="">Choose a speaker</option>
            @foreach($devices as $device)<option value="{{ $device->id }}">{{ $device->device_name }}</option>@endforeach
        </x-select>
    </div>

    <x-card flush>
        <div class="divide-y divide-gray-100 dark:divide-stone-800/70" data-test="clips">
            @foreach($clips as $clip)
                <div class="flex items-center gap-3 px-4 py-3 sm:px-5" wire:key="clip-{{ $clip['name'] }}">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $clip['label'] }}</p>
                        <p class="truncate text-xs text-gray-400 dark:text-gray-600">{{ $clip['name'] }} · {{ number_format($clip['duration_ms'] / 1000, 1) }} s{{ $clip['builtin'] ? ' · built in' : '' }}</p>
                    </div>
                    <button type="button" wire:click="test('{{ $clip['name'] }}')" class="flex-none text-xs text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-300">Play</button>
                    @unless($clip['builtin'])
                        <button type="button" wire:click="remove('{{ $clip['name'] }}')" wire:confirm="Remove {{ $clip['label'] }}?" class="flex-none text-xs text-gray-400 underline hover:text-red-600">Remove</button>
                    @endunless
                </div>
            @endforeach
        </div>
    </x-card>
</div>
