<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Health" subtitle="Background processes, external services, queue and device listeners" :back="route('settings.index')" />
    </x-slot>

    <div class="max-w-5xl space-y-6">
        <livewire:external-services-health />
        <livewire:system-health />
    </div>
</x-app-layout>
