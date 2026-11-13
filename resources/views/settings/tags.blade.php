<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Tags" subtitle="NFC tags and QR codes that play something" :back="route('settings.index')" />
    </x-slot>

    <div class="max-w-3xl">
        <livewire:play-tag-manager />
    </div>
</x-app-layout>
