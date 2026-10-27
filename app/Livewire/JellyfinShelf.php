<?php

namespace App\Livewire;

use App\Integrations\Jellyfin\JellyfinTv;
use App\Models\Device;
use Livewire\Component;

/**
 * "Continue watching" and "Next up" from the Jellyfin server, on the page of a TV while its Jellyfin app is in
 * front and plays nothing. Tapping a tile starts it in the app on that TV. The lists are read from the server
 * after the page has rendered (wire:init) and kept for a minute; the card shows or hides itself as the TV's
 * now playing changes.
 */
class JellyfinShelf extends Component
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

    public function play(string $itemId, int|string $ticks = 0): void
    {
        try {
            JellyfinTv::play($this->device, $itemId, (int) $ticks);
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e instanceof \RuntimeException && !str_contains($e->getMessage(), 'HTTP') ? $e->getMessage() : 'Could not start it: '.$e->getMessage();
        }
    }

    public function render()
    {
        $visible = JellyfinTv::available($this->device) && JellyfinTv::idle($this->device);
        $lists = $visible && $this->ready ? JellyfinTv::lists($this->device) : null;

        return view('livewire.jellyfin-shelf', ['visible' => $visible, 'lists' => $lists]);
    }
}
