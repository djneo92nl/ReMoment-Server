<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Device Drivers" subtitle="Add, discover, and remove devices per driver" :back="route('settings.index')" />
    </x-slot>

    <div class="max-w-4xl">
        <livewire:device-driver-manager />
    </div>
</x-app-layout>
