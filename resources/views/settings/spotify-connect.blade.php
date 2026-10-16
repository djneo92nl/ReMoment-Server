<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Spotify Connect" subtitle="Map Spotify Connect speakers to local devices" :back="route('settings.spotify')" />
    </x-slot>

    <div class="max-w-4xl space-y-6">

        <x-alert type="success">{{ session('success') }}</x-alert>

        <x-alert type="info">
            When Spotify is playing on a mapped speaker, the Spotify Connect virtual device is hidden and playback is
            attributed to the corresponding local device instead. The speaker must be active (playing or recently active)
            to appear in the list below.
        </x-alert>

        <form method="POST" action="{{ route('settings.spotify-connect.save') }}">
            @csrf

            <x-card flush>

                @if(empty($spotifyDevices))
                    @if(\App\Services\SpotifyTokenService::class && !app(\App\Services\SpotifyTokenService::class)->isConnected())
                        <x-empty-state bare icon="fa-brands fa-spotify" title="Spotify is not connected.">
                            <a href="{{ route('spotify.authorize') }}" class="text-sm text-gray-900 dark:text-gray-100 underline">Connect Spotify →</a>
                        </x-empty-state>
                    @else
                        <x-empty-state bare icon="fa-brands fa-spotify" title="No Spotify Connect devices found." text="Start playing on a device and reload this page." />
                    @endif
                @else
                    <div class="grid grid-cols-12 gap-4 px-8 py-4 border-b border-gray-100 dark:border-stone-800">
                        <div class="col-span-5 text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Spotify Connect device</div>
                        <div class="col-span-5 text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Maps to local device</div>
                        <div class="col-span-2 text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Active</div>
                    </div>

                    @foreach($spotifyDevices as $spotifyDevice)
                        @php
                            $name = $spotifyDevice['name'];
                            $isActive = $spotifyDevice['is_active'] ?? false;
                            $type = strtolower($spotifyDevice['type'] ?? 'speaker');
                            $currentDeviceId = $mappings[$name] ?? null;
                        @endphp
                        <div class="grid grid-cols-12 gap-4 items-center px-8 py-5 border-b border-gray-50 dark:border-stone-800/50 last:border-0 hover:bg-gray-50/50 dark:hover:bg-stone-800/20 transition-colors">

                            <div class="col-span-5 flex items-center gap-3">
                                <div class="w-8 h-8 rounded-xl bg-gray-100 dark:bg-stone-800 flex items-center justify-center flex-shrink-0">
                                    <i class="fa-solid fa-{{ $type === 'computer' ? 'laptop' : ($type === 'smartphone' ? 'mobile-screen' : 'volume-high') }} text-gray-500 text-xs"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $name }}</p>
                                    <p class="text-xs text-gray-400 dark:text-gray-600 capitalize">{{ $type }}</p>
                                </div>
                            </div>

                            <div class="col-span-5">
                                <x-select name="mappings[{{ $name }}]" class="w-full">
                                    <option value="">— no mapping —</option>
                                    @foreach($localDevices as $device)
                                        <option value="{{ $device->id }}" @selected($currentDeviceId == $device->id)>
                                            {{ $device->device_name }}
                                        </option>
                                    @endforeach
                                </x-select>
                            </div>

                            <div class="col-span-2">
                                @if($isActive)
                                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>Active
                                    </span>
                                @else
                                    <span class="text-xs text-gray-300 dark:text-stone-600">Idle</span>
                                @endif
                            </div>
                        </div>
                    @endforeach

                    <div class="px-8 py-5 border-t border-gray-100 dark:border-stone-800 flex justify-end">
                        <x-primary-button>
                            <i class="fa-solid fa-floppy-disk"></i>
                            Save mappings
                        </x-primary-button>
                    </div>
                @endif
            </x-card>
        </form>
    </div>
</x-app-layout>
