<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Devices">
            <x-slot name="actions">
                <x-secondary-button href="{{ route('devices.discover') }}">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <span class="hidden sm:inline">Discover</span>
                </x-secondary-button>
                <x-primary-button href="{{ route('devices.create') }}">
                    <i class="fa-solid fa-plus"></i>
                    <span class="hidden sm:inline">Add Device</span>
                </x-primary-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @if($devices->isEmpty())
        <x-empty-state icon="tv"
                       :title="$hiddenCount > 0 ? 'No visible devices' : 'No devices yet'"
                       :text="$hiddenCount > 0 ? 'All your devices are hidden — use the link below to show them.' : 'Add a device to start controlling your audio/video setup.'">
            <x-primary-button href="{{ route('devices.create') }}" size="xl">
                <i class="fa-solid fa-plus"></i>Add your first device
            </x-primary-button>
        </x-empty-state>
    @else
        {{-- Grid: active devices span 2 columns via the component itself (md:col-span-2 set inside) --}}
        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach($devices as $device)
                @php
                    // The grid item is this wrapper, so the wide card for a playing device is set here.
                    $active = in_array($device->state, [\App\Domain\Device\State::Playing, \App\Domain\Device\State::Paused], true)
                        && \App\Domain\Device\DeviceCache::isListenerRunning($device->id);
                @endphp
                <div class="{{ $device->hidden ? 'opacity-40' : '' }} {{ $active ? 'md:col-span-2' : '' }}">
                    <livewire:device-card :device="$device" :key="'dc-'.$device->id" />
                </div>
            @endforeach
        </div>
    @endif

    @if($hiddenCount > 0)
        <div class="flex justify-center mt-8">
            <a href="{{ route('devices.index', $showHidden ? [] : ['hidden' => 1]) }}"
               class="text-sm text-gray-400 dark:text-gray-600 hover:text-gray-600 dark:hover:text-gray-400 transition-colors">
                {{ $showHidden ? 'Hide hidden devices' : "{$hiddenCount} hidden ".($hiddenCount === 1 ? 'device' : 'devices')." — show" }}
            </a>
        </div>
    @endif
</x-app-layout>
