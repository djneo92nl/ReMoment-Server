<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Support\Collection;

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

    public function playLibraryTracks(Collection $tracks): void
    {
        $playable = $tracks->filter(fn (Track $track) => (bool) $track->getDlnaUrl())->values();

        if ($playable->isEmpty()) {
            throw new \RuntimeException('No tracks with a playable DLNA URL.');
        }

        foreach ($playable as $i => $track) {
            $this->playDlnaTrack($track->getDlnaUrl(), instant: $i === 0);
        }
    }

    public function playLibraryPlaylist(Playlist $playlist): void
    {
        $tracks = $playlist->tracks()->get();

        if ($tracks->isEmpty()) {
            throw new \RuntimeException("Playlist {$playlist->id} has no tracks.");
        }

        $this->playLibraryTracks($tracks);
    }
}
