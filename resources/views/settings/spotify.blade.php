<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Spotify" subtitle="Connect your Spotify account to track playback" :back="route('settings.index')" />
    </x-slot>

    <div class="max-w-2xl space-y-6">

        @if(session('success'))
            <x-alert type="success">{{ session('success') }}</x-alert>
        @endif

        @if(session('error'))
            <x-alert type="error">{{ session('error') }}</x-alert>
        @endif

        @php
            $hasApp = \App\Services\SpotifyTokenService::hasCredentials();
        @endphp

        <x-card class="p-8" x-data="{ open: {{ $hasApp ? 'false' : 'true' }} }">
            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Your Spotify app</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Spotify limits each app to 5 users and needs the app owner on Premium, so every install uses its own app:
                create one at <a href="https://developer.spotify.com/dashboard" target="_blank" rel="noopener" class="underline">developer.spotify.com/dashboard</a>
                with this redirect URI, then paste its Client ID and secret.
            </p>
            <p class="text-xs text-gray-400 dark:text-gray-600 mt-2 break-all select-all">{{ route('spotify.callback') }}</p>

            @if($hasApp)
                <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-3" x-show="!open">
                    App configured ({{ \Illuminate\Support\Str::limit(\App\Services\SpotifyTokenService::clientId(), 8, '…') }})
                    · <button type="button" class="underline" x-on:click="open = true">Change</button>
                </p>
            @endif

            <form method="POST" action="{{ route('spotify.credentials') }}" class="mt-4 space-y-3" x-show="open" x-cloak>
                @csrf
                <input type="text" name="client_id" value="{{ old('client_id', \App\Services\SpotifyTokenService::clientId()) }}" placeholder="Client ID" autocomplete="off"
                       class="w-full rounded-xl border-gray-300 dark:border-stone-700 dark:bg-stone-900 text-sm">
                <input type="password" name="client_secret" placeholder="{{ $hasApp ? 'Client secret (leave empty to keep)' : 'Client secret' }}" autocomplete="off"
                       class="w-full rounded-xl border-gray-300 dark:border-stone-700 dark:bg-stone-900 text-sm">
                @if($errors->any())
                    <p class="text-xs text-red-600 dark:text-red-400">{{ $errors->first() }}</p>
                @endif
                <x-primary-button size="md" type="submit">Save</x-primary-button>
            </form>
        </x-card>

        <x-card class="p-8">
            <div class="flex items-center gap-4 mb-6">
                <div class="w-12 h-12 bg-gray-100 dark:bg-stone-800 rounded-2xl flex items-center justify-center">
                    <i class="fa-brands fa-spotify text-gray-500 text-xl"></i>
                </div>
                @if($connected)
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>Connected
                    </span>
                @endif
            </div>

            @if($connected)
                <p class="text-sm text-gray-500 mb-6">
                    Playback is tracked via the Spotify Web API. Map a Spotify Connect speaker to a local device below
                    so playback is attributed correctly, or sync your saved library.
                </p>
                <form method="POST" action="{{ route('spotify.disconnect') }}">
                    @csrf
                    <button type="submit" class="text-sm text-gray-400 hover:text-red-500 dark:hover:text-red-400 transition-colors">
                        Disconnect
                    </button>
                </form>
            @else
                <p class="text-sm text-gray-500 mb-6">
                    Connect your Spotify account to track playback.
                </p>
                @if(!$hasApp)
                    <p class="text-xs text-gray-400 mb-3">Save your Spotify app above first.</p>
                @endif
                <x-primary-button size="md" href="{{ route('spotify.authorize') }}"><i class="fa-brands fa-spotify"></i> Connect Spotify</x-primary-button>
            @endif
        </x-card>

        @if(\App\Domain\Spotify\LibrespotStream::enabled())
            @php
                $streamDevices = $localDevices->filter(fn ($d) => in_array('stream_url', \App\Domain\Device\DeviceCapabilities::for($d), true));
            @endphp
            <x-card x-data="{ busy: null, done: null, error: null,
                async play(id) {
                    this.busy = id; this.done = null; this.error = null;
                    try {
                        const r = await fetch('{{ url('/api/devices') }}/' + id + '/spotify-stream', { method: 'POST', headers: { 'Accept': 'application/json' } });
                        const body = await r.json();
                        if (r.ok) { this.done = id; } else { this.error = body.message || 'The speaker did not start the stream.'; }
                    } catch (e) { this.error = 'The server could not be reached.'; }
                    this.busy = null;
                } }">
                <div class="px-8 py-5 space-y-4">
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Spotify stream</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Play the stream on a speaker below, then pick <span class="font-medium">{{ config('librespot.name') }}</span> in the Spotify app.
                            The speaker needs no Spotify of its own, and from then on counts as the Spotify device (one card, Spotify's own pause and skip). Expect a few seconds of delay.
                        </p>
                        <p class="text-xs text-gray-400 dark:text-gray-600 mt-1 break-all">{{ \App\Domain\Spotify\LibrespotStream::url() }}</p>
                    </div>

                    @forelse($streamDevices as $device)
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-sm text-gray-900 dark:text-gray-100">{{ $device->device_name }}</span>
                            <span class="flex items-center gap-3">
                                <span x-show="done === {{ $device->id }}" x-cloak class="text-xs text-emerald-600 dark:text-emerald-400">Playing the stream</span>
                                <x-secondary-button type="button" x-on:click="play({{ $device->id }})" x-bind:disabled="busy !== null">
                                    <i class="fa-brands fa-spotify"></i>
                                    Play stream
                                </x-secondary-button>
                            </span>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400 dark:text-gray-600">No device can play a stream yet.</p>
                    @endforelse

                    <p x-show="error" x-cloak x-text="error" class="text-xs text-red-600 dark:text-red-400"></p>
                </div>
            </x-card>
        @endif

        @if($connected)
            <x-settings-group>
                <x-settings-row href="{{ route('settings.spotify-connect') }}"
                                 icon="fa-solid fa-tower-broadcast"
                                 label="Speaker Mappings">Map Connect speakers to devices</x-settings-row>
                <x-settings-row href="{{ route('settings.spotify-library') }}"
                                 icon="fa-solid fa-book"
                                 label="Library Sync">Import saved tracks and playlists</x-settings-row>
            </x-settings-group>
        @endif
    </div>
</x-app-layout>
