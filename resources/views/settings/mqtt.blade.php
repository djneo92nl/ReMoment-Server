<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <x-back-button href="{{ route('settings.index') }}" />
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">MQTT</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">Message broker configuration</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-2xl space-y-6">

        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-2xl px-6 py-4 text-sm text-blue-800 dark:text-blue-300">
            <i class="fa-solid fa-circle-info mr-2"></i>
            Every device publishes now-playing, progress, state and volume updates to its own topic. The server only
            publishes — no subscriptions are consumed. Browsers subscribe over the WebSocket listener for live UI updates.
        </div>

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
