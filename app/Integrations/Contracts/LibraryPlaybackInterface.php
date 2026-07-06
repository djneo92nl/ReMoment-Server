<?php

namespace App\Integrations\Contracts;

use App\Models\Media\Playlist;
use App\Models\Media\Track;

interface LibraryPlaybackInterface
{
    public function playLibraryTrack(Track $track): void;

    public function playLibraryPlaylist(Playlist $playlist): void;
}
