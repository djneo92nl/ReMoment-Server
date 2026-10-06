<?php

namespace App\Domain\Library;

use App\Models\Setting;

class LibrarySettings
{
    public const ADD_PLAYED_TRACKS = 'library_add_played_tracks';

    /**
     * Whether a played track that isn't in the library is added to it (default off).
     * Off, the play is still logged, with its names and cover as text.
     */
    public static function addPlayedTracks(): bool
    {
        return Setting::get(self::ADD_PLAYED_TRACKS) === '1';
    }
}
