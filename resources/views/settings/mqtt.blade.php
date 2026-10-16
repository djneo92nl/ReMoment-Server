<x-app-layout>
    <x-slot name="header">
        <x-page-header title="MQTT" subtitle="Message broker configuration" :back="route('settings.index')" />
    </x-slot>

    <div class="max-w-2xl space-y-6">

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
    </div>
</x-app-layout>
