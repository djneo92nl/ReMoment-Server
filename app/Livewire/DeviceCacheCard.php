<?php

namespace App\Livewire;

use App\Domain\Device\DeviceCache;
use App\Models\Device;
use Livewire\Component;

class DeviceCacheCard extends Component
{
    public Device $device;

    public function mount(Device $device): void
    {
        $this->device = $device;
    }

    public function render()
    {
        return view('livewire.device-cache-card', [
            'listenerRunning' => DeviceCache::isListenerRunning($this->device->id),
            'state'           => DeviceCache::getState($this->device->id)?->value,
            'nowPlaying'      => DeviceCache::getNowPlaying($this->device->id),
            'lastSeen'        => DeviceCache::getLastSeen($this->device->id),
        ]);
    }
}
