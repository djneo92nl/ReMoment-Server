<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <x-back-button href="{{ route('settings.index') }}" />
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Device Drivers</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">Add, discover, and remove devices per driver</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-4xl">
        <livewire:device-driver-manager />
    </div>
</x-app-layout>
