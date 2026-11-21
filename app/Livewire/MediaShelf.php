<?php

namespace App\Livewire;

use App\Domain\MediaServers\DeviceMedia;
use App\Domain\MediaServers\MediaServers;
use App\Models\Device;
use Livewire\Component;

/**
 * "Continue watching" and "Next up" from the media servers (Jellyfin) linked to a TV, on its page while a server's app is
 * in front and plays nothing. Tapping a tile starts it in the app on that TV. The lists are read from the servers
 * after the page has rendered (wire:init) and kept for a minute; the card shows or hides itself as the TV's now
 * playing changes. It draws whatever `DeviceMedia::shelves()` returns, so a new server type needs no change here.
 */
class MediaShelf extends Component
{
    public Device $device;

    public bool $ready = false;

    public ?string $error = null;

    public function mount(Device $device): void
    {
        $this->device = $device;
    }

    public function load(): void
    {
        $this->ready = true;
    }

    /** @param  string  $item  the tile's namespaced id (`3:abc`); $position in seconds */
    public function play(string $item, int|float|string $position = 0): void
    {
        try {
            DeviceMedia::play($this->device, $item, null, (float) $position);
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e instanceof \RuntimeException && !str_contains($e->getMessage(), 'HTTP') ? $e->getMessage() : 'Could not start it: '.$e->getMessage();
        }
    }

    public function render()
    {
        $servers = MediaServers::forDevice($this->device);
        $visible = $servers->isNotEmpty() && DeviceMedia::idle($this->device);
        $lists = $visible && $this->ready ? DeviceMedia::shelves($this->device) : null;

        return view('livewire.media-shelf', [
            'visible' => $visible,
            'lists' => $lists,
            'title' => $servers->count() === 1 ? $servers->first()->name : 'Media servers',
        ]);
    }
}
