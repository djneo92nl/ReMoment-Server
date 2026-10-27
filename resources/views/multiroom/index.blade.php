<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Multiroom" subtitle="Save room groups and activate them with one tap" />
    </x-slot>

    <div class="space-y-10">
        <livewire:multiroom-presets />
        <livewire:sync-group-manager />
    </div>
</x-app-layout>
