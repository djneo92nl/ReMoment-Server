<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$device->device_name" subtitle="Settings" :back="route('devices.show', $device)" />
    </x-slot>

    <div class="max-w-3xl">
        <livewire:device-settings :device="$device" :key="'settings-'.$device->id" />
    </div>
</x-app-layout>
