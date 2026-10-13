<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

use App\Integrations\Common\PlaysDlnaLibrary;
use App\Models\Media\Track;

trait LibraryPlayback
{
    use PlaysDlnaLibrary;

    protected function startDlna(string $url, ?Track $track = null): void
    {
        $this->playDlnaTrack($url, track: $track);
    }

    protected function enqueueDlna(string $url, ?Track $track = null): void
    {
        $this->playDlnaTrack($url, instant: false, track: $track);
    }

    /** The speaker keeps what it played before (Tidal, earlier albums); a library album replaces it. */
    protected function clearDlnaQueue(): void
    {
        $this->clearPlayQueue();
    }
}
