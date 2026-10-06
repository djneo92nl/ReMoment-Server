<?php

namespace App\Domain\Library;

use App\Domain\Artwork\LibraryArtwork;
use App\Models\Media\Album;
use App\Models\Media\Track;
use App\Models\Play;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Removes tracks and albums that came from an external source (Spotify, radio) from the library.
 * DLNA tracks stay: the scan owns them. History keeps its plays: a removed track's plays turn
 * into text plays (names, cover, duration), exactly like plays of tracks the library never had.
 * Albums and artists left without tracks are removed with it.
 */
class LibraryRemover
{
    /** @return bool false when the track is available from DLNA and so was kept */
    public static function track(Track $track): bool
    {
        if (!$track->isRemovable()) {
            return false;
        }

        DB::transaction(function () use ($track) {
            $album = $track->album;
            $artist = $track->artist;

            Play::query()->where('track_id', $track->id)->update([
                'track_id' => null,
                'track_name' => $track->name,
                'artist_name' => $artist?->name,
                'album_name' => $album?->name,
                'image_url' => LibraryArtwork::coverUrl($track->images) ?? LibraryArtwork::coverUrl($album?->images),
                'duration' => $track->duration,
            ]);

            self::delete($track);

            if ($album !== null && !$album->tracks()->exists()) {
                self::delete($album);
            }

            if ($artist !== null && !$artist->tracks()->exists() && !$artist->albums()->exists()) {
                self::delete($artist);
            }
        });

        return true;
    }

    /** Removes every track of an album that has none on DLNA; returns how many, null when the album has DLNA tracks. */
    public static function album(Album $album): ?int
    {
        $tracks = $album->tracks()->with('metadata')->get();

        if ($tracks->contains(fn (Track $t) => !$t->isRemovable())) {
            return null;
        }

        foreach ($tracks as $track) {
            self::track($track);
        }

        // An album without tracks (nothing to remove them by) goes too.
        $album = Album::find($album->id);
        if ($album !== null && !$album->tracks()->exists()) {
            self::delete($album);
        }

        return $tracks->count();
    }

    private static function delete(Model $model): void
    {
        $model->metadata()->delete();
        $model->genreRelation()->detach();
        $model->delete();
    }
}
