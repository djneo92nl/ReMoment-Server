<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Podcasts" subtitle="Shows you follow, played on the pinned player" :back="route('library.index')" />
    </x-slot>

    <livewire:podcast-library />
</x-app-layout>
