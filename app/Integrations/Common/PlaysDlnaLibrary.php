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
    /** Plays the stream now, replacing what plays. $track is the library track, for players that can show its details. */
    abstract protected function startDlna(string $url, ?Track $track = null): void;

    /** Appends the stream to the queue. */
    abstract protected function enqueueDlna(string $url, ?Track $track = null): void;

    /** Empties the player's own queue before a library track or collection starts; players that replace it themselves do nothing. */
    protected function clearDlnaQueue(): void {}

    public function playLibraryTrack(Track $track): void
    {
        $url = $track->getDlnaUrl();

        if (!$url) {
            throw new \RuntimeException("Track {$track->id} has no DLNA URL.");
        }

        $this->clearDlnaQueue();
        $this->startDlna($url, $track);
    }

    public function playLibraryTracks(Collection $tracks): void
    {
        $playable = $tracks->filter(fn (Track $track) => (bool) $track->getDlnaUrl())->values();

        if ($playable->isEmpty()) {
            throw new \RuntimeException('No tracks with a playable DLNA URL.');
        }

        $this->clearDlnaQueue();

        foreach ($playable as $i => $track) {
            $i === 0 ? $this->startDlna($track->getDlnaUrl(), $track) : $this->enqueueDlna($track->getDlnaUrl(), $track);
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
