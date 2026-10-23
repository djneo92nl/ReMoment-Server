<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Tracks">
            <x-slot name="actions"><x-library-scope :scope="$scope" /></x-slot>
        </x-page-header>
    </x-slot>

    <livewire:track-browser :scope="$scope" />
</x-app-layout>
