<x-app-layout>
    <x-slot name="header">
        <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Settings</h1>
        <p class="mt-1.5 text-gray-500 dark:text-gray-500">Manage your application configuration</p>
    </x-slot>

    <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3 max-w-4xl">

        <!-- Users Card -->
        <a href="{{ route('settings.users') }}"
           class="group bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8 hover:shadow-xl transition-all">
            <div class="flex items-start justify-between mb-6">
                <div class="w-12 h-12 bg-blue-50 dark:bg-blue-900/20 rounded-2xl flex items-center justify-center">
                    <i class="fa-solid fa-users text-blue-500 text-xl"></i>
                </div>
                <i class="fa-solid fa-arrow-up-right-from-square text-gray-300 dark:text-stone-700 group-hover:text-gray-500 dark:group-hover:text-stone-500 transition-colors text-sm mt-1"></i>
            </div>
            <h2 class="text-xl font-medium text-gray-900 dark:text-gray-100 mb-1">Users</h2>
            <p class="text-sm text-gray-500 dark:text-gray-500 mb-4">Manage user accounts and access</p>
            <div class="text-3xl font-medium text-gray-900 dark:text-gray-100">{{ $userCount }}</div>
            <div class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">{{ $userCount === 1 ? 'user' : 'users' }} registered</div>
        </a>

        <!-- Devices Summary Card -->
        <a href="{{ route('devices.index') }}"
           class="group bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8 hover:shadow-xl transition-all">
            <div class="flex items-start justify-between mb-6">
                <div class="w-12 h-12 bg-emerald-50 dark:bg-emerald-900/20 rounded-2xl flex items-center justify-center">
                    <i class="fa-solid fa-tv text-emerald-500 text-xl"></i>
                </div>
                <i class="fa-solid fa-arrow-up-right-from-square text-gray-300 dark:text-stone-700 group-hover:text-gray-500 dark:group-hover:text-stone-500 transition-colors text-sm mt-1"></i>
            </div>
            <h2 class="text-xl font-medium text-gray-900 dark:text-gray-100 mb-1">Devices</h2>
            <p class="text-sm text-gray-500 dark:text-gray-500 mb-4">Registered audio/video devices</p>
            <div class="text-3xl font-medium text-gray-900 dark:text-gray-100">{{ $deviceCount }}</div>
            <div class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">{{ $deviceCount === 1 ? 'device' : 'devices' }} registered</div>
        </a>

        <!-- Listeners Card -->
        <a href="{{ route('settings.listeners') }}"
           class="group bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8 hover:shadow-xl transition-all">
            <div class="flex items-start justify-between mb-6">
                <div class="w-12 h-12 bg-emerald-50 dark:bg-emerald-900/20 rounded-2xl flex items-center justify-center">
                    <i class="fa-solid fa-circle-dot text-emerald-500 text-xl"></i>
                </div>
                <i class="fa-solid fa-arrow-up-right-from-square text-gray-300 dark:text-stone-700 group-hover:text-gray-500 dark:group-hover:text-stone-500 transition-colors text-sm mt-1"></i>
            </div>
            <h2 class="text-xl font-medium text-gray-900 dark:text-gray-100 mb-1">Listeners</h2>
            <p class="text-sm text-gray-500 dark:text-gray-500 mb-4">Real-time device state processes</p>
            @php($running = \App\Models\Device::all()->filter(fn($d) => \App\Domain\Device\DeviceCache::isListenerRunning($d->id))->count())
            <div class="text-3xl font-medium text-gray-900 dark:text-gray-100">{{ $running }}</div>
            <div class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">of {{ $deviceCount }} {{ $deviceCount === 1 ? 'device' : 'devices' }} active</div>
        </a>

        <!-- Spotify Card -->
        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8">
            <div class="flex items-start justify-between mb-6">
                <div class="w-12 h-12 bg-emerald-50 dark:bg-emerald-900/20 rounded-2xl flex items-center justify-center">
                    <i class="fa-brands fa-spotify text-emerald-500 text-xl"></i>
                </div>
            </div>
            <h2 class="text-xl font-medium text-gray-900 dark:text-gray-100 mb-1">Spotify</h2>
            <p class="text-sm text-gray-500 dark:text-gray-500 mb-4">Connect your Spotify account to track playback</p>
            @if($spotifyConnected)
                <div class="flex items-center gap-2 mb-4">
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>Connected
                    </span>
                </div>
                <form method="POST" action="{{ route('spotify.disconnect') }}">
                    @csrf
                    <button type="submit" class="text-xs text-gray-400 hover:text-red-500 dark:hover:text-red-400 transition-colors">
                        Disconnect
                    </button>
                </form>
            @else
                <a href="{{ route('spotify.authorize') }}"
                   class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium rounded-xl transition-colors">
                    <i class="fa-brands fa-spotify"></i> Connect Spotify
                </a>
            @endif
        </div>

        <!-- MQTT / Integration Card -->
        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8">
            <div class="flex items-start justify-between mb-6">
                <div class="w-12 h-12 bg-amber-50 dark:bg-amber-900/20 rounded-2xl flex items-center justify-center">
                    <i class="fa-solid fa-tower-broadcast text-amber-500 text-xl"></i>
                </div>
            </div>
            <h2 class="text-xl font-medium text-gray-900 dark:text-gray-100 mb-1">MQTT</h2>
            <p class="text-sm text-gray-500 dark:text-gray-500 mb-4">Message broker configuration</p>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-2">
                    <dt class="text-gray-500 dark:text-gray-500">Host</dt>
                    <dd class="font-mono font-medium text-gray-700 dark:text-gray-300 text-xs">{{ config('mqtt-client.connections.default.host', env('MQTT_HOST', 'localhost')) }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-gray-500 dark:text-gray-500">Port</dt>
                    <dd class="font-mono font-medium text-gray-700 dark:text-gray-300 text-xs">{{ config('mqtt-client.connections.default.port', env('MQTT_PORT', 1883)) }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-gray-500 dark:text-gray-500">Topic prefix</dt>
                    <dd class="font-mono font-medium text-gray-700 dark:text-gray-300 text-xs">remoment/player/…</dd>
                </div>
            </dl>
        </div>

    </div>
</x-app-layout>
