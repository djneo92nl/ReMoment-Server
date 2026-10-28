<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Artist" subtitle="As the streaming service has it" :back="url()->previous() !== url()->current() ? url()->previous() : route('library.index')" />
    </x-slot>

    <livewire:external-artist :provider="$provider" :artist-id="$id" :key="'external-artist-'.$id" />
</x-app-layout>
