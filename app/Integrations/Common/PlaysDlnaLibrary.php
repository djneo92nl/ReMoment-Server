<?php

namespace App\Integrations\Common;

use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Support\Collection;

/**
 * LibraryPlaybackInterface for players that take a DLNA URL: the first track starts at once, the rest
 * is appended to the queue. The driver only says how to start one URL and how to append one.
 */
trait PlaysDlnaLibrary
{
    /** Plays the stream now, replacing what plays. */
    abstract protected function startDlna(string $url): void;

    /** Appends the stream to the queue. */
    abstract protected function enqueueDlna(string $url): void;

    public function playLibraryTrack(Track $track): void
    {
        $url = $track->getDlnaUrl();

        if (!$url) {
            throw new \RuntimeException("Track {$track->id} has no DLNA URL.");
        }

        $this->startDlna($url);
    }

    public function playLibraryTracks(Collection $tracks): void
    {
        $playable = $tracks->filter(fn (Track $track) => (bool) $track->getDlnaUrl())->values();

        if ($playable->isEmpty()) {
            throw new \RuntimeException('No tracks with a playable DLNA URL.');
        }

        foreach ($playable as $i => $track) {
            $i === 0 ? $this->startDlna($track->getDlnaUrl()) : $this->enqueueDlna($track->getDlnaUrl());
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
