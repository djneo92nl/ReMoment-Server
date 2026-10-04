<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start gap-4">
            <x-back-button href="{{ route('devices.show', $device) }}" class="mt-1" />
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">{{ $device->device_name }}</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">Settings</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-3xl">
        <livewire:device-settings :device="$device" :key="'settings-'.$device->id" />
    </div>
</x-app-layout>
