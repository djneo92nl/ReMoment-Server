<x-app-layout>
    <x-slot name="header">
        <x-page-header title="BeoLink stream" subtitle="Play what a Bang & Olufsen source plays on any speaker, browser or app" :back="route('settings.index')" />
    </x-slot>

    <div class="max-w-2xl space-y-6">

        @if(session('success'))
            <x-alert type="success">{{ session('success') }}</x-alert>
        @endif

        <x-card class="p-8">
            <div class="flex items-center justify-between gap-4">
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Receiver</p>
                @if(!$enabled)
                    <span class="text-xs text-gray-500 dark:text-gray-400">Off</span>
                @elseif($receiver)
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>Running
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-red-600 dark:text-red-400">
                        <span class="w-2 h-2 rounded-full bg-red-500"></span>Not answering
                    </span>
                @endif
            </div>

            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                A receiver container joins the multiroom of a B&amp;O source (B&amp;O Radio, the line-in of a Beoplay M3/M5, a stream played through one) and serves its audio as an MP3 stream.
                Only sources without copy protection work; the Beosound Moment's line-in is encrypted. The stream is a few seconds behind the B&amp;O speakers and not in sync with them.
            </p>

            @if(!$enabled)
                <div class="mt-4 text-xs text-gray-500 dark:text-gray-400 space-y-1">
                    <p>Start the receiver on a Linux host, then enable it:</p>
                    <p class="font-mono text-gray-700 dark:text-gray-300 break-all">docker compose --profile beolink-stream up -d</p>
                    <p class="font-mono text-gray-700 dark:text-gray-300">BEOLINK_ENABLED=true</p>
                    <p>The receiver finds B&amp;O products over mDNS, which Docker Desktop does not pass. See <span class="font-mono">docs/architecture/beolink-stream.md</span>.</p>
                </div>
            @elseif($receiver)
                <dl class="mt-4 text-xs grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                    <dt class="text-gray-500 dark:text-gray-400">Shows up as</dt>
                    <dd class="text-gray-900 dark:text-gray-100">{{ $receiver['name'] ?? config('beolink.name') }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">Stream</dt>
                    <dd class="text-gray-900 dark:text-gray-100 break-all">{{ $status['url'] }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">B&amp;O products heard</dt>
                    <dd class="text-gray-900 dark:text-gray-100">{{ count($receiver['peers']) }}</dd>
                </dl>
            @else
                <p class="mt-4 text-xs text-red-600 dark:text-red-400">
                    Nothing answers at {{ config('beolink.api_url') }}. Check that the <span class="font-mono">beolink</span> container runs and that <span class="font-mono">BEOLINK_API_URL</span> points at it.
                </p>
            @endif
        </x-card>

        @if($enabled)
            @php
                $nameOf = fn ($id) => optional($players->firstWhere('id', $id) ?? $hosts->firstWhere('id', $id))->device_name;
            @endphp
            <x-card x-data="{ host: {{ $status['host_device_id'] ?? ($hosts->first()->id ?? 'null') }}, busy: null, playing: @js($status['target_device_ids']), error: null,
                async call(id, stop) {
                    this.busy = id; this.error = null;
                    try {
                        const r = await fetch('{{ url('/api/devices') }}/' + id + '/beolink-stream', {
                            method: stop ? 'DELETE' : 'POST',
                            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                            body: stop ? null : JSON.stringify({ host_device_id: this.host }),
                        });
                        const body = await r.json();
                        if (r.ok) { this.playing = body.target_device_ids || []; } else { this.error = body.message || 'The device did not start the stream.'; }
                    } catch (e) { this.error = 'The server could not be reached.'; }
                    this.busy = null;
                } }">
                <div class="px-8 py-5 space-y-4">
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Play on a device</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Pick the B&amp;O device whose audio you want, then the devices that should play it. The same stream is also available to the web player and the app.
                            The multiroom picker on a playing B&amp;O device offers the same under "Stream to".
                        </p>
                    </div>

                    @if($hosts->isEmpty())
                        <p class="text-xs text-gray-400 dark:text-gray-600">No B&amp;O device has a known multiroom id yet. Run <span class="font-mono">php artisan device:sync-sources</span>.</p>
                    @else
                        <label class="block">
                            <span class="text-xs text-gray-500 dark:text-gray-400">B&amp;O source</span>
                            <select x-model.number="host" class="mt-1 block w-full rounded-xl border-gray-200 dark:border-stone-700 dark:bg-stone-900 text-sm">
                                @foreach($hosts as $h)
                                    <option value="{{ $h->id }}">{{ $h->device_name }}</option>
                                @endforeach
                            </select>
                        </label>

                        @forelse($players as $player)
                            @continue($hosts->contains('id', $player->id))
                            <div class="flex items-center justify-between gap-4">
                                <span class="text-sm text-gray-900 dark:text-gray-100">{{ $player->device_name }}</span>
                                <span class="flex items-center gap-3">
                                    <span x-show="playing.includes({{ $player->id }})" x-cloak class="text-xs text-emerald-600 dark:text-emerald-400">Playing the stream</span>
                                    <x-secondary-button type="button" x-show="!playing.includes({{ $player->id }})" x-on:click="call({{ $player->id }}, false)" x-bind:disabled="busy !== null || host === null">
                                        <i class="fa-solid fa-play"></i> Play stream
                                    </x-secondary-button>
                                    <x-secondary-button type="button" x-show="playing.includes({{ $player->id }})" x-cloak x-on:click="call({{ $player->id }}, true)" x-bind:disabled="busy !== null">
                                        <i class="fa-solid fa-stop"></i> Stop
                                    </x-secondary-button>
                                </span>
                            </div>
                        @empty
                            <p class="text-xs text-gray-400 dark:text-gray-600">No device can play a stream yet.</p>
                        @endforelse
                    @endif

                    <p x-show="error" x-cloak x-text="error" class="text-xs text-red-600 dark:text-red-400"></p>
                </div>
            </x-card>

            @if($status['host_device_id'])
                <x-card class="p-8">
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Listening to</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        {{ $nameOf($status['host_device_id']) ?? 'Device #'.$status['host_device_id'] }}
                        @if($status['target_device_ids'])
                            · played on {{ collect($status['target_device_ids'])->map(fn ($id) => $nameOf($id) ?? '#'.$id)->implode(', ') }}
                        @endif
                    </p>
                    <form method="POST" action="{{ route('settings.beolink.leave') }}" class="mt-4">
                        @csrf
                        <button type="submit" class="text-sm text-gray-400 hover:text-red-500 dark:hover:text-red-400 transition-colors">Make the receiver leave</button>
                    </form>
                </x-card>
            @endif

            @if($receiver && count($receiver['peers']) > 0)
                <x-card class="p-8">
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">B&amp;O products the receiver has heard</p>
                    <ul class="mt-3 space-y-1 text-xs text-gray-500 dark:text-gray-400">
                        @foreach($receiver['peers'] as $peer)
                            <li class="flex justify-between gap-4">
                                <span class="text-gray-900 dark:text-gray-100">{{ $peer['friendlyName'] ?? $peer['name'] ?? 'Unnamed' }}</span>
                                <span class="font-mono break-all text-right">{{ $peer['ipAddress'] ?? '' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endif

            <x-card class="p-8">
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Visible to B&amp;O sources</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    A source plays only to listeners that are in the configuration the B&amp;O network shares. Setting <span class="font-mono">BEOLINK_ENROL=true</span> lets the receiver add its own entry once.
                    That changes a document every B&amp;O product holds, so it is off until you set it; the receiver's log says whether the entry exists.
                </p>
            </x-card>
        @endif
    </div>
</x-app-layout>
