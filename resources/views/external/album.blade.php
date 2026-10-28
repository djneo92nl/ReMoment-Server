<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Album" subtitle="As the streaming service has it" :back="url()->previous() !== url()->current() ? url()->previous() : route('library.index')" />
    </x-slot>

    <livewire:external-album :provider="$provider" :album-id="$id" :key="'external-album-'.$id" />
</x-app-layout>
