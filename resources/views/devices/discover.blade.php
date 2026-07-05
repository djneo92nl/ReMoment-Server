<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <x-back-button href="{{ route('devices.index') }}" />
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Discover Devices</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">Scan your network for compatible devices and add them in one click</p>
            </div>
        </div>
    </x-slot>

    <livewire:discover-devices />
</x-app-layout>
