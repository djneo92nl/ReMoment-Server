<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$device->device_name" subtitle="Settings" :back="route('devices.show', $device)" />
    </x-slot>

    <div class="max-w-3xl space-y-6">
        <livewire:device-settings :device="$device" :key="'settings-'.$device->id" />

        @if(auth()->check() && in_array('volume_control', \App\Domain\Device\DeviceCapabilities::for($device)))
            <livewire:device-volume-limits :device="$device" :key="'volume-limits-'.$device->id" />
        @endif

        @if(auth()->check() && \App\Domain\Profile\Profile::allows('library') && in_array('queue_add', \App\Domain\Device\DeviceCapabilities::for($device)))
            <livewire:device-party-settings :device="$device" :key="'party-'.$device->id" />
        @endif
    </div>
</x-app-layout>
