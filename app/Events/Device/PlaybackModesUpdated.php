<?php

namespace App\Events\Device;

use App\Domain\Device\PlaybackModes;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by device listeners with the shuffle / repeat / liked state they
 * observe, and by the REST API after a mode was changed. `$routed` is true
 * for Spotify's modes reported for the local speaker Spotify is routed to;
 * while routed, that speaker's own (non-routed) reports are held back.
 */
class PlaybackModesUpdated
{
    use Dispatchable;

    public function __construct(
        public string $deviceId,
        public PlaybackModes $modes,
        public bool $routed = false,
    ) {}
}
