<x-card tag="section" class="p-8" title="Party">
    <div class="flex items-center justify-between gap-4">
        <div>
            <div class="text-sm text-gray-700 dark:text-gray-300">Guests add tracks to the queue</div>
            <div class="text-xs text-gray-400 dark:text-gray-600">A page behind a QR code: search and add, nothing else. No skip, no volume.</div>
        </div>
        <x-toggle :on="$party['enabled']" label="Party" wire:click="toggle" />
    </div>

    @if($party['enabled'])
        <form wire:submit="save" class="mt-5 flex flex-wrap items-end gap-3">
            <div>
                <x-input-label for="party-limit" value="Tracks per guest" />
                <x-text-input id="party-limit" type="number" min="1" max="50" wire:model="limit" class="w-24" />
            </div>
            <div>
                <x-input-label for="party-window" value="Per (minutes)" />
                <x-text-input id="party-window" type="number" min="1" max="240" wire:model="windowMinutes" class="w-24" />
            </div>
            <x-secondary-button type="submit" size="md">Save</x-secondary-button>
        </form>
        <x-input-error :messages="$errors->get('limit')" class="mt-1" />
        <x-input-error :messages="$errors->get('windowMinutes')" class="mt-1" />

        <div class="mt-5 flex flex-wrap items-center gap-4">
            <div class="rounded-xl bg-white p-2">{!! $qr !!}</div>
            <div class="min-w-0 flex-1 space-y-2">
                <a href="{{ $url }}" target="_blank" rel="noopener" class="block break-all text-sm text-gray-700 dark:text-gray-300 underline" data-test="party-url">{{ $url }}</a>
                <button type="button" wire:click="newLink" wire:confirm="Make a new link? The old one stops working." class="text-xs text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-300">New link</button>
            </div>
        </div>
    @endif
</x-card>
