<?php

namespace App\Livewire;

use App\Domain\Device\DeviceCache;
use App\Integrations\Contracts\VolumeControlInterface;
use Livewire\Component;

class Nowplaying extends Component
{
    public $device;

    public $volume = 2; // default volume 0-100

    public bool $listenerRunning = false;

    public function mount($device)
    {
        $this->device = $device;
        try {
            $driver = $device->driver;
            if ($driver instanceof VolumeControlInterface) {
                $this->volume = $driver->getVolume();
            }
        } catch (\Throwable) {
            $this->volume = 0;
        }
        $this->listenerRunning = DeviceCache::isListenerRunning($device->id);
    }

    public function render()
    {
        $this->listenerRunning = DeviceCache::isListenerRunning($this->device->id);
        return view('livewire.nowplaying');
    }

    public function updatedVolume($value)
    {
        try {
            $driver = $this->device->driver;
            if ($driver instanceof VolumeControlInterface) {
                $driver->setVolume($value);
            }
        } catch (\Throwable) {
        }
        $this->volume = $value;
    }

    public function play()
    {
        try { $this->device->driver->play(); } catch (\Throwable) {}
    }

    public function pause()
    {
        try { $this->device->driver->pause(); } catch (\Throwable) {}
    }

    public function next()
    {
        try { $this->device->driver->next(); } catch (\Throwable) {}
    }

    public function previous()
    {
        try { $this->device->driver->previous(); } catch (\Throwable) {}
    }

    public function standby()
    {
        try {
            $driver = $this->device->driver;
            if (method_exists($driver, 'standby')) {
                $driver->standby();
            }
        } catch (\Throwable) {}
    }
}
