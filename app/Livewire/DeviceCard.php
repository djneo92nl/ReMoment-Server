<?php

namespace App\Livewire;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;
use Livewire\Component;

class DeviceCard extends Component
{
    public Device $device;
    public int $volume = 0;
    public bool $listenerRunning = false;

    public function mount(Device $device): void
    {
        $this->device = $device;
        $this->refresh();
    }

    public function render()
    {
        $this->refresh();
        return view('livewire.device-card');
    }

    public function play(): void
    {
        $this->withDriver(fn ($d) => $d->play());
    }

    public function pause(): void
    {
        $this->withDriver(fn ($d) => $d->pause());
    }

    public function next(): void
    {
        $this->withDriver(fn ($d) => $d->next());
    }

    public function previous(): void
    {
        $this->withDriver(fn ($d) => $d->previous());
    }

    public function standby(): void
    {
        $this->withDriver(fn ($d) => $d->standby());
    }

    private function refresh(): void
    {
        $this->listenerRunning = DeviceCache::isListenerRunning($this->device->id);

        $state = $this->device->state;
        if ($state !== State::Unreachable) {
            try {
                $driver = $this->device->driver;
                if ($driver instanceof VolumeControlInterface) {
                    $this->volume = $driver->getVolume();
                }
            } catch (\Throwable) {
                $this->volume = 0;
            }
        }
    }

    private function withDriver(callable $callback): void
    {
        try {
            $driver = $this->device->driver;
            if ($driver instanceof MediaControlsInterface) {
                $callback($driver);
            }
        } catch (\Throwable) {
            // silently ignore driver errors in card context
        }
    }
}
