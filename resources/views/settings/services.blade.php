<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Services & keys" subtitle="The accounts and API keys of the services ReMoment uses" :back="route('settings.index')" />
    </x-slot>

    <div class="max-w-2xl">
        <livewire:service-credentials-manager />
    </div>
</x-app-layout>
