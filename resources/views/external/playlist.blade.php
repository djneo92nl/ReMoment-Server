<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Playlist" subtitle="As the streaming service has it" :back="route('playlists.index', ['source' => 'streaming'])" />
    </x-slot>

    <livewire:external-playlist :provider="$provider" :playlist-id="$id" :key="'external-playlist-'.$id" />
</x-app-layout>
