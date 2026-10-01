<?php

namespace App\Domain\Artwork;

use App\Models\Media\Album;
use App\Models\Play;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

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

    /** Files a client caches, as artwork entry key => file name in artwork/{md5(url)}/. */
    public const CLIENT_FILES = [
        'proxy_320' => '320.jpg',
        'proxy_120' => '120.jpg',
        'proxy_bg' => 'bg_1024x600.jpg',
    ];

    /** Path of one processed file on the public disk. */
    public static function path(string $url, string $key): string
    {
        return 'artwork/'.md5($url).'/'.self::CLIENT_FILES[$key];
    }

    /**
     * The client's file URLs for a cover: from its cache entry, or from the
     * files on disk when the entry expired (the cache TTL is 30 days, the
     * files stay) — the same URLs either way. Null unless all are there.
     *
     * @return array<string, string>|null
     */
    public static function clientFiles(string $url, ?array $entry): ?array
    {
        $disk = Storage::disk('public');
        $files = [];

        foreach (array_keys(self::CLIENT_FILES) as $key) {
            if (isset($entry[$key])) {
                $files[$key] = $entry[$key];
            } elseif ($disk->exists($path = self::path($url, $key))) {
                $files[$key] = $disk->url($path);
            } else {
                return null;
            }
        }

        return $files;
    }

    /** An album's cover: the first image, stored as a URL string or as {url}. */
    public static function coverUrl(?array $images): ?string
    {
        $first = $images[0] ?? null;

        return (is_array($first) ? ($first['url'] ?? null) : $first) ?: null;
    }
}
