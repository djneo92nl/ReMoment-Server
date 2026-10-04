<?php

namespace App\Livewire;

use App\Domain\Device\SpotifyRouting;
use App\Domain\Device\State;
use App\Integrations\Contracts\QueueInterface;
use App\Models\Device;
use Livewire\Component;

class DeviceQueue extends Component
{
    public Device $device;

    public int $limit = 10;

    /** Deferred until wire:init so the page renders before the device round trip. */
    public bool $ready = false;

    public function load(): void
    {
        $this->ready = true;
    }

    public function render()
    {
        $items = [];
        $error = null;

        if ($this->ready && $this->device->state !== State::Unreachable) {
            try {
                $driver = SpotifyRouting::driverFor($this->device, QueueInterface::class);
                if ($driver instanceof QueueInterface) {
                    $items = array_map(fn ($item) => $item->toArray(), $driver->getUpNext($this->limit));
                }
            } catch (\Throwable) {
                $error = 'Could not read the queue from the device.';
            }
        }

        return view('livewire.device-queue', compact('items', 'error'));
    }
}
