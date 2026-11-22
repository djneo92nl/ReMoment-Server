<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Edit album" subtitle="Tags, cover and names of every file of this album" :back="route('settings.storage')" />
    </x-slot>

    <div class="max-w-4xl">
        <livewire:album-tag-editor :album="$album" />
    </div>
</x-app-layout>
