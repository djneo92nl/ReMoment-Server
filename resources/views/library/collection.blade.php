<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Collection" subtitle="CDs, records and tapes on your shelves" />
    </x-slot>

    <div class="space-y-6">
        <livewire:rip-disc />
        <livewire:physical-collection />
    </div>
</x-app-layout>
