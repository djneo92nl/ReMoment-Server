<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Devices" subtitle="Add, edit, remove and discover devices" :back="route('settings.index')">
            <x-slot name="actions">
                <x-primary-button href="{{ route('devices.create', ['from' => 'settings']) }}">
                    <i class="fa-solid fa-plus"></i>
                    <span class="hidden sm:inline">Add Device</span>
                </x-primary-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="max-w-4xl">
        <livewire:device-driver-manager />
    </div>
</x-app-layout>
