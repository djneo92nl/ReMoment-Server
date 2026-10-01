<?php

namespace App\Domain\Artwork;

use App\Models\Media\Album;
use App\Models\Play;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Album covers of the library in "most recently played first" order, as
 * used by `artwork:prerender`, the client pre-cache list and the SD export.
 */
final class LibraryArtwork
{
    /**
     * Albums with images: played albums by their last play (newest first),
     * then never-played albums by id. Rows carry `id`, `images` and
     * `last_played_at` (null when never played).
     */
    public static function albumsByRecency(): Builder
    {
        $lastPlayed = Play::query()
            ->join('tracks', 'tracks.id', '=', 'plays.track_id')
            ->whereNotNull('tracks.album_id')
            ->groupBy('tracks.album_id')
            ->select('tracks.album_id')
            ->selectRaw('MAX(plays.played_at) as last_played_at');

        return Album::query()
            ->leftJoinSub($lastPlayed, 'recent', 'recent.album_id', '=', 'albums.id')
            ->whereNotNull('albums.images')
            ->select('albums.id', 'albums.images', 'recent.last_played_at')
            ->orderByRaw('CASE WHEN recent.last_played_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('recent.last_played_at')
            ->orderBy('albums.id');
    }

    /**
     * Cover URLs of the last $limit distinct albums played, newest first,
     * without duplicates (albums can share a cover).
     *
     * @return Collection<int, string>
     */
    public static function recentCoverUrls(?int $limit = null): Collection
    {
        return self::albumsByRecency()
            ->whereNotNull('recent.last_played_at')
            ->limit($limit ?? (int) config('artwork.recent_albums'))
            ->get()
            ->map(fn (Album $album) => self::coverUrl($album->images))
            ->filter()
            ->unique()
            ->values();
    }

    /** An album's cover: the first image, stored as a URL string or as {url}. */
    public static function coverUrl(?array $images): ?string
    {
        $first = $images[0] ?? null;

        return (is_array($first) ? ($first['url'] ?? null) : $first) ?: null;
    }
}
