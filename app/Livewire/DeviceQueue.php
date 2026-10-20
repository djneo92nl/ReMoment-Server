<?php

namespace App\Livewire;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Device\DeviceCapabilities;
use App\Domain\Device\SpotifyRouting;
use App\Domain\Device\State;
use App\Integrations\Contracts\QueueInterface;
use App\Livewire\Concerns\EditsQueue;
use App\Models\Device;
use Livewire\Component;

class DeviceQueue extends Component
{
    use EditsQueue;

    public Device $device;

    public int $limit = 10;

    /** Deferred until wire:init so the page renders before the device round trip. */
    public bool $ready = false;

    protected function queueDevice(): ?Device
    {
        return $this->device;
    }

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
                    $items = array_map(function ($item) {
                        $row = $item->toArray();
                        $row['image'] = LibraryItemArtwork::proxy($row['image'], 120);

                        return $row;
                    }, $driver->getUpNext($this->limit));
                }
            } catch (\Throwable) {
                $error = 'Could not read the queue from the device.';
            }
        }

        $capabilities = DeviceCapabilities::for($this->device);

        return view('livewire.device-queue', compact('items', 'error', 'capabilities'));
    }
}
