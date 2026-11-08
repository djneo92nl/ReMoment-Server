<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Developer" subtitle="Find what a player can do, simulate playback, publish MQTT, inspect devices and streams, tidy caches" :back="route('settings.index')" />
    </x-slot>

    <div class="max-w-4xl">
        <x-tabs wrap :tabs="['quality' => ['Quality test', 'sliders'], 'probe' => ['Capability probe', 'ruler'], 'tests' => ['Playback tests', 'vial'], 'simulate' => ['Simulate', 'play'], 'mqtt' => ['MQTT', 'tower-broadcast'], 'inspect' => ['Devices', 'microchip'], 'stream' => ['Streams', 'link'], 'maintenance' => ['Maintenance', 'broom']]">
            <x-tabs.panel name="quality"><livewire:developer.quality-test /></x-tabs.panel>
            <x-tabs.panel name="probe"><livewire:developer.capability-probe /></x-tabs.panel>
            <x-tabs.panel name="tests"><livewire:developer.playback-tests /></x-tabs.panel>
            <x-tabs.panel name="simulate"><livewire:developer.simulate-now-playing /></x-tabs.panel>
            <x-tabs.panel name="mqtt"><livewire:developer.mqtt-console /></x-tabs.panel>
            <x-tabs.panel name="inspect"><livewire:developer.device-inspector /></x-tabs.panel>
            <x-tabs.panel name="stream">
                <div class="space-y-6">
                    <livewire:developer.stream-url-tester />
                    <livewire:developer.stream-diagnostics-panel />
                </div>
            </x-tabs.panel>
            <x-tabs.panel name="maintenance"><livewire:developer.maintenance /></x-tabs.panel>
        </x-tabs>
    </div>
</x-app-layout>
