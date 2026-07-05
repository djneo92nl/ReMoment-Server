<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Devices</h1>
            </div>
            <div class="flex items-center gap-2">
                <x-secondary-button href="{{ route('devices.discover') }}">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <span class="hidden sm:inline">Discover</span>
                </x-secondary-button>
                <x-primary-button href="{{ route('devices.create') }}">
                    <i class="fa-solid fa-plus"></i>
                    <span class="hidden sm:inline">Add Device</span>
                </x-primary-button>
            </div>
        </div>
    </x-slot>

    @if($devices->isEmpty())
        <div class="flex flex-col items-center justify-center py-24 text-center">
            <div class="w-20 h-20 bg-gray-100 dark:bg-stone-800 rounded-3xl flex items-center justify-center mb-6">
                <i class="fa-solid fa-tv text-3xl text-gray-300 dark:text-stone-600"></i>
            </div>
            <h2 class="text-xl font-medium text-gray-700 dark:text-gray-300 mb-2">No devices yet</h2>
            <p class="text-gray-500 dark:text-gray-500 mb-8">Add a device to start controlling your audio/video setup.</p>
            <x-primary-button href="{{ route('devices.create') }}" size="xl">
                <i class="fa-solid fa-plus"></i>Add your first device
            </x-primary-button>
        </div>
    @else
        {{-- Grid: active devices span 2 columns via the component itself (md:col-span-2 set inside) --}}
        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach($devices as $device)
                <livewire:device-card :device="$device" :key="'dc-'.$device->id" />
            @endforeach
        </div>
    @endif
</x-app-layout>
