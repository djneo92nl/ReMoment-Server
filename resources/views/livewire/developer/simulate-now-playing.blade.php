<x-card class="p-6 sm:p-8 space-y-6" data-test="simulate">
    <x-alert type="warning">Sends events as a listener would (cache, MQTT, clients, history). A running listener for the device overwrites it at its next poll, and a played-for-real track is stored in the history.</x-alert>

    <x-select wire:change="fromHistory($event.target.value)" class="w-full" data-test="sim-history">
        <option value="">Fill from a play in the history…</option>
        @foreach($history as $h)<option value="{{ $h->id }}">{{ $h->track_name }} · {{ $h->artist_name }}</option>@endforeach
    </x-select>

    <div class="grid gap-4 sm:grid-cols-2">
        <x-select wire:model="deviceId" data-test="sim-device"><option value="">Choose a device</option>@foreach($devices as $d)<option value="{{ $d->id }}">{{ $d->device_name }}</option>@endforeach</x-select>
        <x-select wire:model="state">@foreach($states as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach</x-select>
        <x-text-input wire:model="track" placeholder="Track" />
        <x-text-input wire:model="artist" placeholder="Artist" />
        <x-text-input wire:model="album" placeholder="Album" />
        <x-text-input wire:model="image" placeholder="Artwork URL (optional)" />
        <x-text-input wire:model="duration" type="number" min="1" placeholder="Duration (s)" />
        <x-text-input wire:model="progress" type="number" min="0" max="100" placeholder="Progress %" />
        <x-text-input wire:model="volume" type="number" min="0" max="100" placeholder="Volume" />
    </div>

    <div class="flex flex-wrap gap-2">
        <x-primary-button type="button" size="md" wire:click="nowPlaying" data-test="sim-now-playing">Send now playing</x-primary-button>
        @foreach([0, 25, 50, 75, 100] as $step)
            <x-secondary-button type="button" size="md" wire:click="$set('progress', {{ $step }}); $wire.sendProgress()">{{ $step }}%</x-secondary-button>
        @endforeach
        <x-secondary-button type="button" size="md" wire:click="sendState">State</x-secondary-button>
        <x-secondary-button type="button" size="md" wire:click="sendVolume">Volume</x-secondary-button>
        <x-secondary-button type="button" size="md" wire:click="end">End</x-secondary-button>
    </div>

    @if($message)<x-alert type="success" data-test="sim-message">{{ $message }}</x-alert>@endif
</x-card>
