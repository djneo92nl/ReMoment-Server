<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Audio analysis" subtitle="How far each stage has come on your own files, what runs now and what failed" :back="route('settings.index')" />
    </x-slot>

    <div class="max-w-4xl">
        <livewire:analysis-status />
    </div>
</x-app-layout>
