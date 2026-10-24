<x-app-layout>
    <x-slot name="header">
        <x-page-header title="NTS shows" subtitle="Archive and official tracklists">
            <x-slot name="actions">
                <form method="POST" action="{{ route('radio.import-nts') }}">
                    @csrf
                    <x-secondary-button type="submit" title="Add the NTS live channels and mixtapes, or refresh their streams"><i class="fa-solid fa-rotate"></i> Refresh stations</x-secondary-button>
                </form>
                <x-secondary-button href="{{ route('radio.index') }}"><i class="fa-solid fa-arrow-left"></i> Radio</x-secondary-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <livewire:nts-browser />
</x-app-layout>
