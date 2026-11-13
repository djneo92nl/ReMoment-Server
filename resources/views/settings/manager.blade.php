<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Manager" subtitle="The containers of this install, restart, logs and the host's health, through the manager service on the host" :back="route('settings.index')" />
    </x-slot>

    <div class="max-w-4xl">
        <livewire:manager-panel />
    </div>
</x-app-layout>
