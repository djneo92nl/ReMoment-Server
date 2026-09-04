<x-app-layout>
    <x-slot name="header">
        <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Settings</h1>
        <p class="mt-1.5 text-gray-500 dark:text-gray-500">Manage your application configuration</p>
    </x-slot>

    <div class="max-w-2xl space-y-8">

        <x-settings-group title="Devices">
            <x-settings-row href="{{ route('devices.index') }}"
                             icon="fa-solid fa-tv" icon-bg="bg-emerald-50 dark:bg-emerald-900/20" icon-color="text-emerald-500"
                             label="Devices">
                {{ $deviceCount }} {{ $deviceCount === 1 ? 'device' : 'devices' }}
            </x-settings-row>
            <x-settings-row href="{{ route('settings.devices') }}"
                             icon="fa-solid fa-satellite-dish" icon-bg="bg-indigo-50 dark:bg-indigo-900/20" icon-color="text-indigo-500"
                             label="Device Drivers">
                {{ $deviceCount }} {{ $deviceCount === 1 ? 'device' : 'devices' }}
            </x-settings-row>
            <x-settings-row href="{{ route('settings.listeners') }}"
                             icon="fa-solid fa-circle-dot" icon-bg="bg-emerald-50 dark:bg-emerald-900/20" icon-color="text-emerald-500"
                             label="Listeners">
                {{ $listenerCount }} of {{ $deviceCount }} active
            </x-settings-row>
            <x-settings-row href="{{ route('settings.dlna') }}"
                             icon="fa-solid fa-server" icon-bg="bg-purple-50 dark:bg-purple-900/20" icon-color="text-purple-500"
                             label="DLNA Library">
                {{ $dlnaServerCount }} {{ $dlnaServerCount === 1 ? 'server' : 'servers' }}
            </x-settings-row>
        </x-settings-group>

        <x-settings-group title="Integrations">
            <x-settings-row href="{{ route('settings.spotify') }}"
                             icon="fa-brands fa-spotify" icon-bg="bg-emerald-50 dark:bg-emerald-900/20" icon-color="text-emerald-500"
                             label="Spotify">
                @if($spotifyConnected)
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>Connected
                    </span>
                @else
                    Not Connected
                @endif
            </x-settings-row>
            <x-settings-row href="{{ route('settings.lastfm') }}"
                             icon="fa-brands fa-lastfm" icon-bg="bg-red-50 dark:bg-red-900/20" icon-color="text-red-600"
                             label="Last.fm">
                @if($lastfmConnected)
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>Connected
                    </span>
                @else
                    Not Connected
                @endif
            </x-settings-row>
            <x-settings-row href="{{ route('settings.mqtt') }}"
                             icon="fa-solid fa-tower-broadcast" icon-bg="bg-amber-50 dark:bg-amber-900/20" icon-color="text-amber-500"
                             label="MQTT">
                {{ config('mqtt-client.connections.default.host', env('MQTT_HOST', 'localhost')) }}
            </x-settings-row>
        </x-settings-group>

        <x-settings-group title="Access">
            <x-settings-row href="{{ route('settings.users') }}"
                             icon="fa-solid fa-users" icon-bg="bg-blue-50 dark:bg-blue-900/20" icon-color="text-blue-500"
                             label="Users">
                {{ $userCount }} {{ $userCount === 1 ? 'user' : 'users' }}
            </x-settings-row>
            <x-settings-row href="{{ route('settings.clients') }}"
                             icon="fa-solid fa-microchip" icon-bg="bg-sky-50 dark:bg-sky-900/20" icon-color="text-sky-500"
                             label="Client Devices">
                @if($pendingClientCount > 0)
                    <span class="text-amber-500 font-medium">{{ $pendingClientCount }} pending</span>
                @else
                    {{ $clientCount }} {{ $clientCount === 1 ? 'client' : 'clients' }}
                @endif
            </x-settings-row>
        </x-settings-group>

    </div>
</x-app-layout>
