<div class="space-y-6" data-test="playback-tests">
    <x-card class="p-6 sm:p-8">
        <label class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Player</label>
        <x-select wire:model.live="deviceId" class="mt-2 w-full sm:w-80" data-test="tests-device">
            <option value="">Choose a device</option>
            @foreach($devices as $d)<option value="{{ $d->id }}">{{ $d->device_name }}</option>@endforeach
        </x-select>
    </x-card>

    @if($deviceId)
        <x-card class="p-6 sm:p-8" data-test="tones">
            <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100">Test signals</h2>
            <p class="mt-1 text-sm text-gray-500">30 s WAV files, made by ffmpeg once, played as a stream URL. Needs a player with <code>stream_url</code>.</p>
            @unless($canStreamUrl)<x-alert type="warning" class="mt-4">This player has no <code>stream_url</code> capability.</x-alert>@endunless
            @unless($ffmpeg)<x-alert type="warning" class="mt-4">ffmpeg is not available, so a tone that has not been made yet cannot be.</x-alert>@endunless
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach($tones as $name => $tone)
                    <x-secondary-button type="button" size="md" wire:click="tone('{{ $name }}')" wire:loading.attr="disabled" :disabled="!$canStreamUrl">{{ $tone['label'] }}</x-secondary-button>
                @endforeach
            </div>
        </x-card>

        <x-card class="p-6 sm:p-8" data-test="seek">
            <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100">Seek</h2>
            <p class="mt-1 text-sm text-gray-500">Jumps within what plays now. Whether a player really seeks depends on range requests for that format: see the request log on the Streams tab.</p>
            @unless($canSeek)<x-alert type="warning" class="mt-4">This player has no <code>seek</code> capability.</x-alert>@endunless
            <div class="mt-4 flex flex-wrap items-center gap-2">
                @foreach([10, 25, 50, 90] as $p)
                    <x-secondary-button type="button" size="md" wire:click="seekPercent({{ $p }})" :disabled="!$canSeek">{{ $p }}%</x-secondary-button>
                @endforeach
                <x-text-input wire:model="seconds" type="number" min="0" size="sm" class="w-24" />
                <x-secondary-button type="button" size="md" wire:click="seekTo({{ (int) $seconds }})" :disabled="!$canSeek">Seek to seconds</x-secondary-button>
            </div>
        </x-card>

        <x-card class="p-6 sm:p-8" data-test="gapless">
            <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100">Gapless pair</h2>
            <p class="mt-1 text-sm text-gray-500">Plays the chosen track and the next one of its album as one queue; listen at the join for a gap, click or a restart.</p>
            <div class="mt-4 grid gap-6 sm:grid-cols-2">
                @include('livewire.developer.partials.track-search')
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Quality</label>
                    <x-select wire:model="profile" class="mt-2 w-full"><option value="">Original</option>@foreach($profiles as $name => $p)<option value="{{ $name }}">{{ $p['label'] ?? $name }}</option>@endforeach</x-select>
                </div>
            </div>
            <x-primary-button type="button" size="md" class="mt-4" wire:click="gapless" wire:loading.attr="disabled" data-test="gapless-play">Play pair</x-primary-button>
        </x-card>
    @endif

    @if($result)<x-alert :type="$result['ok'] ? 'success' : 'error'" data-test="tests-result">{{ $result['message'] }}</x-alert>@endif
</div>
