<?php

namespace App\Livewire;

use App\Domain\Device\Cache\Volume;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Models\Device;
use Livewire\Component;

class DeviceCard extends Component
{
    public Device $device;
    public int $volume = 0;
    public bool $listenerRunning = false;
    public bool $standalone = false; // disables md:col-span-2 (use on show page)

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
        $this->volume = (int) (Volume::getVolume($this->device->id) ?: 0);
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
