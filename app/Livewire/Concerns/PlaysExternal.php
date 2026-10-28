<?php

namespace App\Livewire\Concerns;

use App\Domain\Library\LibraryPlayback;
use App\Support\SelectedDevice;

/** Play an item of an external catalogue (Spotify) on the player pinned in this browser, without adding it to the library. */
trait PlaysExternal
{
    /** Shown near the buttons when Play could not start (no player pinned, not playable there). */
    public ?string $notice = null;

    public function playExternal(string $type, string $id, bool $shuffle = false): void
    {
        $this->notice = null;
        $device = app(SelectedDevice::class)->device();

        if ($device === null) {
            $this->notice = 'Pick a player in the bar below first.';

            return;
        }

        try {
            app(LibraryPlayback::class)->playExternal($device, 'spotify', $type, $id, $shuffle);
        } catch (\Throwable $e) {
            $this->notice = $e->getMessage();
        }
    }
}
