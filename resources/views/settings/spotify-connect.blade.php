<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <x-back-button href="{{ route('settings.index') }}" />
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Spotify Connect</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">Map Spotify Connect speakers to local devices</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-4xl space-y-6">

        @if(session('success'))
            <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-2xl px-6 py-4 text-sm text-emerald-800 dark:text-emerald-300">
                <i class="fa-solid fa-circle-check mr-2"></i>{{ session('success') }}
            </div>
        @endif

        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-2xl px-6 py-4 text-sm text-blue-800 dark:text-blue-300">
            <i class="fa-solid fa-circle-info mr-2"></i>
            When Spotify is playing on a mapped speaker, the Spotify Connect virtual device is hidden and playback is
            attributed to the corresponding local device instead. The speaker must be active (playing or recently active)
            to appear in the list below.
        </div>

        <form method="POST" action="{{ route('settings.spotify-connect.save') }}">
            @csrf

            <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 overflow-hidden">

                @if(empty($spotifyDevices))
                    <div class="flex flex-col items-center justify-center py-20 text-center px-8">
                        <i class="fa-brands fa-spotify text-4xl text-gray-200 dark:text-stone-700 mb-4"></i>
                        @if(\App\Services\SpotifyTokenService::class && !app(\App\Services\SpotifyTokenService::class)->isConnected())
                            <p class="text-gray-500 dark:text-gray-500">Spotify is not connected.</p>
                            <a href="{{ route('spotify.authorize') }}" class="mt-4 text-sm text-indigo-600 dark:text-indigo-400 hover:underline">Connect Spotify →</a>
                        @else
                            <p class="text-gray-500 dark:text-gray-500">No Spotify Connect devices found.</p>
                            <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Start playing on a device and reload this page.</p>
                        @endif
                    </div>
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
                                <div class="w-8 h-8 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center flex-shrink-0">
                                    <i class="fa-solid fa-{{ $type === 'computer' ? 'laptop' : ($type === 'smartphone' ? 'mobile-screen' : 'volume-high') }} text-emerald-500 text-xs"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $name }}</p>
                                    <p class="text-xs text-gray-400 dark:text-gray-600 capitalize">{{ $type }}</p>
                                </div>
                            </div>

                            <div class="col-span-5">
                                <select name="mappings[{{ $name }}]"
                                        class="w-full text-sm bg-gray-50 dark:bg-stone-800 border border-gray-200 dark:border-stone-700 rounded-xl px-3 py-2 text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    <option value="">— no mapping —</option>
                                    @foreach($localDevices as $device)
                                        <option value="{{ $device->id }}" @selected($currentDeviceId == $device->id)>
                                            {{ $device->device_name }}
                                        </option>
                                    @endforeach
                                </select>
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
                        <button type="submit"
                                class="flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-2xl text-sm font-medium transition-colors">
                            <i class="fa-solid fa-floppy-disk"></i>
                            Save mappings
                        </button>
                    </div>
                @endif
            </div>
        </form>
    </div>
</x-app-layout>
