<div class="space-y-6">

    <div class="flex items-center justify-between px-1">
        <p class="text-sm font-medium text-gray-500 dark:text-gray-500">{{ $doneCount }} of 3 steps done</p>
        <div class="flex gap-1.5">
            @for ($i = 0; $i < 3; $i++)
                <span class="w-8 h-1.5 rounded-full {{ $i < $doneCount ? 'bg-emerald-500' : 'bg-gray-200 dark:bg-stone-800' }}"></span>
            @endfor
        </div>
    </div>

    {{-- Step 1: Devices --}}
    <x-settings-group>
        <div class="p-5 md:p-6">
            <div class="flex items-start gap-4">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 {{ $devicesDone ? 'bg-emerald-50 dark:bg-emerald-900/20' : 'bg-gray-100 dark:bg-stone-800' }}">
                    <i class="fa-solid {{ $devicesDone ? 'fa-check text-emerald-500' : 'fa-tv text-gray-500' }}"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="font-medium text-gray-900 dark:text-gray-100">Add your devices</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-500">
                        @if($devicesDone)
                            {{ $deviceCount }} {{ $deviceCount === 1 ? 'device' : 'devices' }} added.
                        @else
                            Add the speakers and players you want to control &mdash; Bang &amp; Olufsen, Sonos, or Spotify.
                        @endif
                    </p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <x-primary-button size="md" href="{{ route('devices.discover') }}">
                            <i class="fa-solid fa-magnifying-glass"></i>Scan Network
                        </x-primary-button>
                        <x-secondary-button size="md" href="{{ route('devices.create') }}">
                            Add Manually
                        </x-secondary-button>
                    </div>
                </div>
            </div>
        </div>
    </x-settings-group>

    {{-- Step 2: Client Devices --}}
    <x-settings-group>
        <div class="p-5 md:p-6">
            <div class="flex items-start gap-4">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 {{ $clientsDone ? 'bg-emerald-50 dark:bg-emerald-900/20' : 'bg-gray-100 dark:bg-stone-800' }}">
                    <i class="fa-solid {{ $clientsDone ? 'fa-check text-emerald-500' : 'fa-microchip text-gray-500' }}"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="font-medium text-gray-900 dark:text-gray-100">Client devices</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-500">
                        Optional. A client is anything that displays now-playing info or sends transport
                        commands &mdash; an ESP32 display, a Raspberry Pi, or a software integration. Clients
                        register themselves and wait here as <span class="font-medium">pending</span> until you approve them.
                    </p>
                    @if($pendingClientCount > 0)
                        <p class="mt-2 text-sm text-amber-500 font-medium">{{ $pendingClientCount }} waiting for approval</p>
                    @elseif($clientCount > 0)
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-500">{{ $clientCount }} {{ $clientCount === 1 ? 'client' : 'clients' }} approved.</p>
                    @endif
                    <div class="mt-4 flex flex-wrap gap-3">
                        <x-primary-button size="md" href="{{ route('settings.clients') }}">
                            Manage Client Devices
                        </x-primary-button>
                        @unless($clientsDone)
                            <x-secondary-button size="md" type="button" wire:click="skipClients">
                                Skip for now
                            </x-secondary-button>
                        @endunless
                    </div>
                </div>
            </div>
        </div>
    </x-settings-group>

    {{-- Step 3: Library --}}
    <x-settings-group>
        <div class="p-5 md:p-6">
            <div class="flex items-start gap-4">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 {{ $libraryDone ? 'bg-emerald-50 dark:bg-emerald-900/20' : 'bg-gray-100 dark:bg-stone-800' }}">
                    <i class="fa-solid {{ $libraryDone ? 'fa-check text-emerald-500' : 'fa-record-vinyl text-gray-500' }}"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="font-medium text-gray-900 dark:text-gray-100">Set up your library</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-500">
                        Connect a source so artists, albums, and tracks show up to browse and play.
                    </p>
                    <div class="mt-4 space-y-2">
                        <a href="{{ route('settings.dlna') }}" class="flex items-center gap-3 rounded-xl border border-gray-200 dark:border-stone-700 px-4 py-3 hover:bg-gray-50 dark:hover:bg-stone-800/40 transition-colors">
                            <i class="fa-solid fa-server text-purple-500 w-4 text-center"></i>
                            <span class="flex-1 text-sm text-gray-900 dark:text-gray-100">DLNA Library</span>
                            <span class="text-sm text-gray-400 dark:text-gray-600">{{ $dlnaServerCount }} {{ $dlnaServerCount === 1 ? 'server' : 'servers' }}</span>
                        </a>
                        <a href="{{ route('settings.spotify') }}" class="flex items-center gap-3 rounded-xl border border-gray-200 dark:border-stone-700 px-4 py-3 hover:bg-gray-50 dark:hover:bg-stone-800/40 transition-colors">
                            <i class="fa-brands fa-spotify text-emerald-500 w-4 text-center"></i>
                            <span class="flex-1 text-sm text-gray-900 dark:text-gray-100">Spotify</span>
                            <span class="text-sm text-gray-400 dark:text-gray-600">
                                @if($spotifyConnected)
                                    <span class="inline-flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>Connected</span>
                                @else
                                    Not Connected
                                @endif
                            </span>
                        </a>
                    </div>
                    <p class="mt-3 text-xs text-gray-400 dark:text-gray-600">
                        Also want scrobbling? <a href="{{ route('settings.lastfm') }}" class="underline hover:text-gray-600 dark:hover:text-gray-400">Connect Last.fm</a>.
                    </p>
                    @unless($libraryDone)
                        <div class="mt-4">
                            <x-secondary-button size="md" type="button" wire:click="skipLibrary">
                                Skip for now
                            </x-secondary-button>
                        </div>
                    @endunless
                </div>
            </div>
        </div>
    </x-settings-group>

    <div class="flex justify-end pt-2">
        <x-primary-button size="xl" type="button" wire:click="finish">
            {{ $allDone ? 'Finish' : "Skip setup, I'll do this later" }}
        </x-primary-button>
    </div>

</div>
