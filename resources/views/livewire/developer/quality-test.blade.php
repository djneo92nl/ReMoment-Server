<div class="space-y-6" data-test="quality-test">
    <x-card class="p-6 sm:p-8">
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">To find the most a player can do, work up each group (sample rate, bit depth, format) until it stops or glitches. Sample rates and bit depths above the original's are converted up, so they test the player, not the music. Play one of your own tracks on a player as the original or as a transcode, to hear what a format or bitrate does on that speaker. The transcode is made by ffmpeg when you press play.</p>

        @unless($canTranscode)
            <x-alert type="warning" class="mb-6" data-test="transcode-off">Transcoding is off (<code>PROXIES_ENABLED</code> or ffmpeg missing): only the original can be played.</x-alert>
        @endunless

        <div class="grid gap-6 sm:grid-cols-2">
            <div>
                <label class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Track</label>
                <x-text-input wire:model.live.debounce.300ms="search" placeholder="Search your own files" class="mt-2 w-full" data-test="track-search" />
                @if($tracks->isNotEmpty())
                    <ul class="mt-2 divide-y divide-gray-100 dark:divide-stone-800 rounded-xl border border-gray-200 dark:border-stone-700">
                        @foreach($tracks as $t)
                            <li><button type="button" wire:click="choose({{ $t->id }})" class="w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-stone-800">
                                <span class="text-gray-900 dark:text-gray-100">{{ $t->name }}</span>
                                <span class="text-gray-500"> · {{ $t->artist?->name }}</span>
                            </button></li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div>
                <label class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Player</label>
                <x-select wire:model.live="deviceId" class="mt-2 w-full" data-test="device-select">
                    <option value="">Choose a device</option>
                    @foreach($devices as $device)
                        <option value="{{ $device->id }}">{{ $device->device_name }}</option>
                    @endforeach
                </x-select>
            </div>
        </div>
    </x-card>

    @if($track)
        <x-card class="p-6 sm:p-8" data-test="ladder">
            <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100">{{ $track->name }} <span class="text-gray-500">· {{ $track->artist?->name }}</span></h2>

            <div class="mt-5 divide-y divide-gray-100 dark:divide-stone-800 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div>
                        <div class="text-gray-900 dark:text-gray-100">Original</div>
                        <div class="text-xs text-gray-500 tabular-nums">
                            @if($original)
                                {{ strtoupper($original->extension) }}{{ $original->codec ? ' · '.$original->codec : '' }}{{ $original->bitrate ? ' · '.round($original->bitrate / 1000).' kbit/s' : '' }}{{ $original->sample_rate ? ' · '.($original->sample_rate / 1000).' kHz' : '' }}{{ $original->bit_depth ? ' · '.$original->bit_depth.' bit' : '' }} · {{ number_format($original->size / 1048576, 1) }} MB
                            @else
                                no file on disk
                            @endif
                        </div>
                    </div>
                    <x-primary-button type="button" size="sm" wire:click="play" wire:loading.attr="disabled" :disabled="!$deviceId" data-test="play-original">Play</x-primary-button>
                </div>

                @foreach($rungs as $group => $items)
                    <div class="pt-5 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">{{ $group }}</div>
                    @foreach($items as $rung)
                        <div class="flex flex-wrap items-center justify-between gap-3 py-3" data-test="rung-{{ $rung['name'] }}">
                            <div>
                                <div class="text-gray-900 dark:text-gray-100">{{ $rung['label'] }}</div>
                                <div class="text-xs text-gray-500 tabular-nums">{{ $rung['ready'] ? 'made · '.number_format($rung['size'] / 1048576, 1).' MB' : 'not made yet' }}</div>
                            </div>
                            <div class="flex gap-2">
                                @unless($rung['ready'])
                                    <x-secondary-button type="button" size="sm" wire:click="generate('{{ $rung['name'] }}')" wire:loading.attr="disabled" :disabled="!$canTranscode">Make</x-secondary-button>
                                @endunless
                                <x-primary-button type="button" size="sm" wire:click="play('{{ $rung['name'] }}')" wire:loading.attr="disabled" :disabled="!$deviceId || !$canTranscode">Play</x-primary-button>
                            </div>
                        </div>
                    @endforeach
                @endforeach
            </div>

            <p wire:loading class="mt-4 text-sm text-gray-500"><i class="fa-solid fa-spinner fa-spin mr-2"></i>Working…</p>
        </x-card>
    @endif

    @if($result)
        <x-alert :type="$result['ok'] ? 'success' : 'error'" data-test="result">
            {{ $result['message'] }}
            @if($result['url'])<div class="mt-1 break-all font-mono text-xs opacity-80">{{ $result['url'] }}</div>@endif
        </x-alert>
    @endif
</div>
