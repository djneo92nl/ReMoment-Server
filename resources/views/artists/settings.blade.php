<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$artist->name" subtitle="Artist settings" :back="route('artists.show', $artist)" />
    </x-slot>

    <div class="max-w-3xl">
        <livewire:artist-settings :artist="$artist" :key="'artist-settings-'.$artist->id" />
    </div>
</x-app-layout>
