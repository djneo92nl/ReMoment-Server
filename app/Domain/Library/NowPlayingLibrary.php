<?php

namespace App\Domain\Library;

use App\Domain\Media\NowPlaying;

class NowPlayingLibrary
{
    /**
     * The library record behind the playing track, `{ track_id, album_id, artist_id }` (surviving ids; album_id null for an album-less track), or null when nothing matches.
     * Same lookup as the waveform and play history: external id, else artist + album + name.
     *
     * @return array{track_id: int, album_id: int|null, artist_id: int|null}|null
     */
    public static function for(?NowPlaying $nowPlaying): ?array
    {
        $track = $nowPlaying?->track;
        $name = is_string($track?->name) ? trim($track->name) : '';
        $artist = $track?->artist?->name;
        if ($name === '' || !is_string($artist) || $artist === '') {
            return null;
        }

        try {
            $found = LibraryIdentity::lookupTrack($artist, $nowPlaying->album?->name, $name, $track->id, $track->source, $track->duration);
        } catch (\Throwable) {
            return null;
        }

        if ($found === null) {
            return null;
        }

        return ['track_id' => $found->id, 'album_id' => $found->album_id, 'artist_id' => $found->artist_id];
    }
}
