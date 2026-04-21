<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('devices.index') }}"
               class="flex items-center justify-center w-9 h-9 rounded-xl bg-gray-100 dark:bg-stone-800 text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-stone-700 transition-colors flex-shrink-0">
                <i class="fa-solid fa-arrow-left text-sm"></i>
            </a>
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Discover Devices</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">Scan your network for compatible devices and add them in one click</p>
            </div>
        </div>
    </x-slot>

    <livewire:discover-devices />
</x-app-layout>
