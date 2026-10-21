<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Settings" subtitle="Manage your application configuration" />
    </x-slot>

    <div class="max-w-2xl space-y-8">

        <x-settings-group title="Devices">
            @if($deviceCount === 0 && \App\Models\Setting::get('setup_wizard_dismissed') === null)
                <x-settings-row href="{{ route('setup.index') }}" icon="fa-solid fa-wand-magic-sparkles"
                                 label="Setup Wizard">
                    Get started
                </x-settings-row>
            @endif
            <x-settings-row href="{{ route('settings.devices') }}" icon="fa-solid fa-tv"
                             label="Devices">
                {{ $deviceCount }} {{ $deviceCount === 1 ? 'device' : 'devices' }} &middot; add, edit, remove, discover
            </x-settings-row>
            <x-settings-row href="{{ route('settings.clients') }}" icon="fa-solid fa-microchip"
                             label="Client Devices">
                @if($pendingClientCount > 0)
                    <span class="text-amber-500 font-medium">{{ $pendingClientCount }} pending</span>
                @else
                    {{ $clientCount }} {{ $clientCount === 1 ? 'client' : 'clients' }}
                @endif
            </x-settings-row>
        </x-settings-group>

        <x-settings-group title="Music library">
            <x-settings-row href="{{ route('settings.storage') }}" icon="fa-solid fa-hard-drive"
                             label="Files & Import">
                Browse, import, {{ $storageLocationCount }} {{ $storageLocationCount === 1 ? 'location' : 'locations' }}
                &middot; leads: {{ ['files' => 'Local files', 'dlna' => 'DLNA', 'spotify' => 'Spotify', 'all' => 'everything'][\App\Domain\Library\LeadingSource::leading()] }}
            </x-settings-row>
            <x-settings-row href="{{ route('settings.analysis') }}" icon="fa-solid fa-wave-square"
                             label="Audio analysis">
                Stages, progress and problems &middot; {{ config('analysis.enabled') ? 'on' : 'off' }}
            </x-settings-row>
            <x-settings-row href="{{ route('settings.library-health') }}" icon="fa-solid fa-stethoscope"
                             label="Library Health">
                @if($libraryIssueCount > 0)
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>{{ number_format($libraryIssueCount) }} to review
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>Nothing to review
                    </span>
                @endif
            </x-settings-row>
        </x-settings-group>

        <x-settings-group title="Other sources & services">
            <x-settings-row href="{{ route('settings.dlna') }}" icon="fa-solid fa-server"
                             label="DLNA">
                {{ $dlnaServerCount }} {{ $dlnaServerCount === 1 ? 'server' : 'servers' }}
            </x-settings-row>
            <x-settings-row href="{{ route('settings.spotify') }}" icon="fa-brands fa-spotify"
                             label="Spotify">
                @if($spotifyConnected)
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>Connected
                    </span>
                @else
                    Not Connected
                @endif
            </x-settings-row>
            <x-settings-row href="{{ route('settings.lastfm') }}" icon="fa-brands fa-lastfm"
                             label="Last.fm">
                @if($lastfmConnected)
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>Connected
                    </span>
                @else
                    Not Connected
                @endif
            </x-settings-row>
        </x-settings-group>

        <x-settings-group title="System">
            <x-settings-row href="{{ route('settings.health') }}" icon="fa-solid fa-heart-pulse"
                             label="Health">
                @if($healthProblems === [])
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>Scheduler and queue running
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 text-red-500">
                        <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>{{ implode(' and ', $healthProblems) }} not running
                    </span>
                @endif
            </x-settings-row>
            <x-settings-row href="{{ route('settings.mqtt') }}" icon="fa-solid fa-tower-broadcast"
                             label="MQTT">
                {{ config('mqtt.host') }}
            </x-settings-row>
        </x-settings-group>

        <x-settings-group title="Access">
            <x-settings-row href="{{ route('settings.users') }}" icon="fa-solid fa-key"
                             label="Admin & Apps">
                {{ $appTokenCount }} {{ $appTokenCount === 1 ? 'app' : 'apps' }} signed in
            </x-settings-row>
        </x-settings-group>

    </div>
</x-app-layout>
