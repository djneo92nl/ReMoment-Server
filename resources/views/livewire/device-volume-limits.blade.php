<x-card tag="section" class="p-8" title="Volume limits">
    @if($notice)
        <div class="mb-4 rounded-2xl border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20 px-5 py-3 text-sm text-emerald-800 dark:text-emerald-300">{{ $notice }}</div>
    @endif

    <p class="text-sm text-gray-500">
        The volume never goes above these, from ReMoment or on the device itself. Leave a field empty for no limit.
        @if($limit !== null) The limit is {{ $limit }} now. @endif
    </p>

    <form wire:submit="save" class="mt-5 space-y-4">
        <div class="flex items-center justify-between gap-4">
            <x-input-label for="volume-max" value="Maximum volume" />
            <x-text-input id="volume-max" type="number" min="0" max="100" wire:model="max" class="w-24" />
        </div>
        <x-input-error :messages="$errors->get('max')" />

        <div class="flex items-center justify-between gap-4">
            <x-input-label for="volume-quiet-max" value="Maximum during quiet hours" />
            <x-text-input id="volume-quiet-max" type="number" min="0" max="100" wire:model="quietMax" class="w-24" />
        </div>
        <x-input-error :messages="$errors->get('quietMax')" />

        <div class="flex items-center justify-between gap-4">
            <span class="text-sm text-gray-700 dark:text-gray-300">Quiet hours</span>
            <span class="flex items-center gap-2">
                <x-text-input type="time" wire:model="quietFrom" aria-label="Quiet hours start" />
                <span class="text-sm text-gray-500">to</span>
                <x-text-input type="time" wire:model="quietUntil" aria-label="Quiet hours end" />
            </span>
        </div>
        <x-input-error :messages="$errors->get('quietFrom')" />
        <x-input-error :messages="$errors->get('quietUntil')" />

        <div class="flex justify-end">
            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="save">Save</x-primary-button>
        </div>
    </form>
</x-card>
