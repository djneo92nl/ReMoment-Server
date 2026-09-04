<?php

namespace App\Integrations\Contracts;

use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Support\Collection;

interface LibraryPlaybackInterface
{
    public function playLibraryTrack(Track $track): void;

    /**
     * Play an ad-hoc, ordered collection of local/DLNA tracks as a gapless queue.
     *
     * @param  Collection<int, Track>  $tracks
     */
    public function playLibraryTracks(Collection $tracks): void;

    public function playLibraryPlaylist(Playlist $playlist): void;
}
