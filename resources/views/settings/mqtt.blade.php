<x-app-layout>
    <x-slot name="header">
        <x-page-header title="MQTT" subtitle="Message broker configuration" :back="route('settings.index')" />
    </x-slot>

    <div class="max-w-2xl space-y-6">

        @if(session('success'))
            <x-alert type="success">{{ session('success') }}</x-alert>
        @endif
        @if(session('error'))
            <x-alert type="error">{{ session('error') }}</x-alert>
        @endif

        <x-alert type="info">
            Every device publishes now-playing, progress, state and volume updates to its own topic. The server only
            publishes — no subscriptions are consumed. Browsers subscribe over the WebSocket listener for live UI updates.
        </x-alert>

        <x-settings-group>
            <div class="flex items-center justify-between px-5 py-3.5">
                <div class="text-[15px] text-gray-900 dark:text-gray-100">Host</div>
                <div class="font-mono text-sm text-gray-500 dark:text-gray-400">{{ config('mqtt.host') }}</div>
            </div>
            <div class="flex items-center justify-between px-5 py-3.5">
                <div class="text-[15px] text-gray-900 dark:text-gray-100">Port</div>
                <div class="font-mono text-sm text-gray-500 dark:text-gray-400">{{ config('mqtt.port') }}</div>
            </div>
            <div class="flex items-center justify-between px-5 py-3.5">
                <div class="text-[15px] text-gray-900 dark:text-gray-100">Topic prefix</div>
                <div class="font-mono text-sm text-gray-500 dark:text-gray-400">remoment/player/…</div>
            </div>
            <div class="flex items-center justify-between px-5 py-3.5">
                <div class="text-[15px] text-gray-900 dark:text-gray-100">Browser WebSocket</div>
                <div class="font-mono text-sm text-gray-500 dark:text-gray-400">{{ config('mqtt.ws_url') ?: 'ws://{page host}:9001' }}</div>
            </div>
        </x-settings-group>

        <form method="POST" action="{{ route('settings.mqtt.home-assistant') }}" class="space-y-3">
            @csrf
            <x-settings-group>
                <label class="flex items-center justify-between px-5 py-3.5">
                    <span class="text-[15px] text-gray-900 dark:text-gray-100">Home Assistant discovery</span>
                    <input type="checkbox" name="enabled" value="1" @checked(\App\Domain\HomeAssistant\HomeAssistant::enabled())>
                </label>
                <label class="flex items-center justify-between px-5 py-3.5">
                    <span class="text-[15px] text-gray-900 dark:text-gray-100">Discovery prefix</span>
                    <input type="text" name="prefix" value="{{ \App\Domain\HomeAssistant\HomeAssistant::prefix() }}" class="w-48 rounded-lg border-gray-300 font-mono text-sm dark:border-gray-700 dark:bg-gray-800">
                </label>
            </x-settings-group>
            <p class="px-1 text-sm text-gray-500 dark:text-gray-400">
                Publishes stats, health, clients, sources and events as one "ReMoment Server" device to Home Assistant over this broker
                (Home Assistant must use the same Mosquitto). Speakers are never listed. Switching it off removes every entity.
            </p>
            <x-primary-button type="submit">Save</x-primary-button>
        </form>
        @if (\App\Domain\HomeAssistant\HomeAssistant::enabled())
            <form method="POST" action="{{ route('settings.mqtt.home-assistant.publish') }}">
                @csrf
                <x-secondary-button type="submit">Publish now</x-secondary-button>
            </form>
        @endif

        {{-- The rest_command package (what `php artisan ha:package` writes): Home Assistant calls ReMoment's REST API with it. --}}
        <form method="GET" action="{{ route('settings.mqtt.home-assistant.package') }}" class="space-y-3" data-test="ha-package">
            <x-settings-group>
                <label class="flex items-center justify-between gap-4 px-5 py-3.5">
                    <span class="text-[15px] text-gray-900 dark:text-gray-100">Server address for Home Assistant</span>
                    <input type="url" name="base_url" value="{{ \App\Domain\Scenes\HaPackage::defaultBaseUrl() }}" class="w-64 rounded-xl border-gray-300 font-mono text-sm dark:border-gray-700 dark:bg-gray-800" data-test="ha-package-base-url">
                </label>
            </x-settings-group>
            <p class="px-1 text-sm text-gray-500 dark:text-gray-400">
                A <span class="font-mono">remoment.yaml</span> with one <span class="font-mono">rest_command</span> per action (scenes, play on a device, move playback, volume ...), for Home Assistant automations.
                Add it as a package or merge it into <span class="font-mono">configuration.yaml</span>. The address must be one Home Assistant can reach. Not yet tried in a real Home Assistant.
            </p>
            <x-secondary-button type="submit" data-test="ha-package-download">Download package</x-secondary-button>
        </form>
    </div>
</x-app-layout>
