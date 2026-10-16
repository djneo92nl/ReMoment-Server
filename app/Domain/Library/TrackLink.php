<?php

namespace App\Domain\Library;

use App\Domain\Media\NowPlaying;
use App\Models\Media\Track;

/** Where a played track lives in the library: its album page, scrolled to and highlighted on the track. */
final class TrackLink
{
    public static function for(?Track $track): ?string
    {
        return $track?->album_id ? route('albums.show', $track->album_id).'#track-'.$track->id : null;
    }

    /** Looks the playing track up in the library only; never creates anything. */
    public static function forNowPlaying(?NowPlaying $nowPlaying): ?string
    {
        $np = $nowPlaying?->track;
        $name = trim((string) $np?->name);
        $artist = trim((string) $np?->artist?->name);

        if ($name === '' || $artist === '') {
            return null;
        }

        return self::for(LibraryIdentity::lookupTrack(
            $artist,
            $nowPlaying->album?->name,
            $name,
            NowPlayingTrackResolver::externalId($nowPlaying),
            $np->source,
            $np->duration,
        ));
    }
}
