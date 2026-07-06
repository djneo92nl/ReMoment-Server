<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

use App\Models\Media\Playlist;
use App\Models\Media\Track;

trait LibraryPlayback
{
    public function playLibraryTrack(Track $track): void
    {
        $url = $track->getDlnaUrl();

        if (!$url) {
            throw new \RuntimeException("Track {$track->id} has no DLNA URL.");
        }

        $this->playDlnaTrack($url);
    }

    public function playLibraryPlaylist(Playlist $playlist): void
    {
        $tracks = $playlist->tracks()->get();

        if ($tracks->isEmpty()) {
            throw new \RuntimeException("Playlist {$playlist->id} has no tracks.");
        }

        foreach ($tracks as $i => $track) {
            $url = $track->getDlnaUrl();

            if (!$url) {
                continue;
            }

            $this->playDlnaTrack($url, instant: $i === 0);
        }
    }
}
