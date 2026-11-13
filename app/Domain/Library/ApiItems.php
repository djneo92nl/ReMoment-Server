<?php

namespace App\Domain\Library;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;

/** The small list shapes of the library API (AlbumItem, ArtistItem, SearchTrack), shared by every endpoint that lists them. */
class ApiItems
{
    public static function album(Album $album): array
    {
        return [
            'id' => $album->id,
            'name' => $album->name,
            'artist_name' => $album->artist?->name,
            'year' => $album->released_at?->year,
            'artwork' => LibraryItemArtwork::forImages($album->images),
            'favorite' => $album->favorited_at !== null,
        ];
    }

    public static function artist(Artist $artist): array
    {
        return [
            'id' => $artist->id,
            'name' => $artist->name,
            'album_count' => (int) $artist->albums_count,
            'artwork' => LibraryItemArtwork::forUrl($artist->avatarUrl()),
            'favorite' => $artist->favorited_at !== null,
        ];
    }

    public static function track(Track $track): array
    {
        return [
            'id' => $track->id,
            'name' => $track->name,
            'artist_name' => $track->artist?->name,
            'album_id' => $track->album_id,
            'album_name' => $track->album?->name,
            'duration' => $track->duration,
            'artwork' => LibraryItemArtwork::forImages($track->album?->images),
        ];
    }
}
