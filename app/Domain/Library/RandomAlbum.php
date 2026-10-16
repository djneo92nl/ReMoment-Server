<?php

namespace App\Domain\Library;

use App\Models\Device;
use App\Models\Media\Album;
use Illuminate\Database\Eloquent\Builder;

/**
 * One random album with a visible track in the scope. The one implementation behind `GET /api/library/random-album`
 * and the library page's Shuffle: with a device it prefers an album that device can play, else any.
 */
class RandomAlbum
{
    /** @param  list<string>  $hidden  sources hidden for the request (LibrarySources) */
    public static function pick(array $hidden, ?Device $device = null): ?Album
    {
        $album = null;

        if ($device !== null && LibraryPlayback::canPlayAny($device)) {
            $album = self::randomOf(LibrarySources::albums(Album::query()->with('artist'), $hidden)
                ->whereHas('tracks', fn (Builder $q) => LibraryPlayback::playableTracks(LibrarySources::tracks($q, $hidden), $device)));
        }

        return $album ?? self::randomOf(LibrarySources::albums(Album::query()->whereHas('tracks')->with('artist'), $hidden));
    }

    /** A random offset into the id order, so no rows are loaded to choose. */
    private static function randomOf(Builder $albums): ?Album
    {
        $count = (clone $albums)->count();

        return $count === 0 ? null : $albums->orderBy('albums.id')->offset(random_int(0, $count - 1))->first();
    }
}
