<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Announcements" subtitle="Clips to play on speakers, after which they go back to what played" :back="route('settings.index')" />
    </x-slot>

    <div class="max-w-3xl">
        <livewire:announcement-manager />
    </div>
</x-app-layout>
