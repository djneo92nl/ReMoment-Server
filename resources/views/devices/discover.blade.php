<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Discover Devices" subtitle="Scan your network for compatible devices and add them in one click" :back="route('devices.index')" />
    </x-slot>

    <livewire:discover-devices />
</x-app-layout>
