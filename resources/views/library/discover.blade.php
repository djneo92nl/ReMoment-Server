<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Discover" subtitle="Albums, artists and songs worth another listen" :back="route('library.index')" />
    </x-slot>

    <livewire:discover-hub :scope="$scope" :key="'discover-'.$scope" />
</x-app-layout>
