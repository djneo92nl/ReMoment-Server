<x-app-layout>
    <x-slot name="header">
        <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Setup</h1>
        <p class="mt-1.5 text-gray-500 dark:text-gray-500">Get ReMoment ready to use</p>
    </x-slot>

    <div class="max-w-2xl">
        <livewire:setup-wizard />
    </div>
</x-app-layout>
