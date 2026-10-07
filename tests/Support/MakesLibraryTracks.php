<?php

namespace Tests\Support;

use App\Models\Media\Album;
use App\Models\Media\Metadata;
use App\Models\Media\Track;

/** Test fixture: a library track playable from DLNA (a stream URL in its metadata) and/or Spotify (a URI as its id). */
trait MakesLibraryTracks
{
    /**
     * @param  int|null  $artistId  defaults to the album's artist
     */
    protected function makeLibraryTrack(?Album $album, string $name, bool $dlna = true, ?string $spotifyId = null, ?int $artistId = null): Track
    {
        $track = Track::create([
            'album_id' => $album?->id,
            'artist_id' => $artistId ?? $album->artist_id,
            'external_id' => $spotifyId ? "spotify:track:{$spotifyId}" : uniqid('dlna:', true),
            'name' => $name,
            'duration' => 200,
            'source' => $spotifyId ? 'spotify' : 'dlna',
        ]);

        if ($dlna) {
            Metadata::create([
                'metadatable_type' => Track::class,
                'metadatable_id' => $track->id,
                'key' => 'dlna_url',
                'value' => "http://nas.test/{$track->id}.flac",
                'type' => 'url',
                'source' => 'dlna:1',
            ]);
        }

        return $track;
    }
}
