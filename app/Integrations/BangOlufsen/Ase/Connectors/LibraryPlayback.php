<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

use App\Models\Media\Track;

trait LibraryPlayback
{
    public function playLibraryTrack(Track $track): void
    {
        $url = $track->getDlnaUrl();

        if (! $url) {
            throw new \RuntimeException("Track {$track->id} has no DLNA URL.");
        }

        $this->playDlnaTrack($url);
    }
}
