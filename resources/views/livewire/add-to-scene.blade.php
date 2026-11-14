<div>
    <x-secondary-button size="md" type="button" wire:click="toggle" data-test="add-to-scene-toggle">
        <i class="fa-solid fa-wand-magic-sparkles"></i>Add to scene
    </x-secondary-button>

    @if($open)
        <x-card compact class="mt-4 space-y-4 p-5 sm:p-6" data-test="add-to-scene-panel">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Play this in a scene</h2>
                <button type="button" wire:click="toggle" class="text-xs text-gray-500 underline">Close</button>
            </div>

            @if($message)
                <x-alert :type="$messageIsError ? 'error' : 'success'">
                    {{ $message }}
                    @if($addedTo)
                        <a href="{{ route('scenes.edit', $addedTo) }}" class="underline">Edit the scene</a>
                    @endif
                </x-alert>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label value="Scene" class="mb-1.5" />
                    <x-select wire:model.live="sceneChoice" class="w-full" data-test="scene-choice">
                        <option value="">Choose…</option>
                        @foreach($scenes as $scene)
                            <option value="{{ $scene->id }}">{{ $scene->name }}</option>
                        @endforeach
                        <option value="new">New scene…</option>
                    </x-select>
                    <x-input-error :messages="$errors->get('sceneChoice')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label value="On device" class="mb-1.5" />
                    <x-select wire:model="deviceId" class="w-full" data-test="scene-device">
                        <option value="">Choose…</option>
                        @foreach($devices as $device)
                            <option value="{{ $device->id }}">{{ $device->device_name }}</option>
                        @endforeach
                    </x-select>
                    <x-input-error :messages="$errors->get('deviceId')" class="mt-1.5" />
                </div>
            </div>

            @if($sceneChoice === 'new')
                <div>
                    <x-input-label value="Name of the new scene" class="mb-1.5" />
                    <x-text-input wire:model="newName" type="text" placeholder="e.g. Sunday morning" class="w-full max-w-sm" />
                    <x-input-error :messages="$errors->get('newName')" class="mt-1.5" />
                </div>
            @endif

            <label class="flex items-center gap-3 cursor-pointer">
                <input type="checkbox" wire:model="shuffle"
                       class="w-4 h-4 rounded border-gray-300 dark:border-stone-600 text-gray-900 dark:text-gray-100 focus:ring-gray-400 dark:focus:ring-stone-500">
                <span class="text-sm text-gray-700 dark:text-gray-300">Shuffle</span>
            </label>

            <x-primary-button size="md" type="button" wire:click="add" data-test="scene-add">Add step</x-primary-button>
        </x-card>
    @endif
</div>
