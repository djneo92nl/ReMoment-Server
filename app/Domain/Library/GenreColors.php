<?php

namespace App\Domain\Library;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\LibraryArtwork;
use App\Models\Media\Album;
use Illuminate\Support\Facades\Cache;

/**
 * The `colors` of a genre in the library API: up to MAX_COLORS hex colours that the covers of the genre's
 * albums share most, so a client can tint a genre tile.
 *
 * Input is what the artwork pipeline already stored per cover (the `colors` palette of the cover's cache
 * entry, read in one bulk cache read, never recomputed); covers without an entry add nothing.
 *
 * Algorithm (per genre, over the albums that `album_count` counts in the requested scope):
 *  1. Sample at most SAMPLE_SIZE albums: most played first (plays of their tracks), then lowest id.
 *  2. Per album take the first PALETTE_ENTRIES (2) colours of its cover's palette.
 *  3. Bucket each colour by quantising every channel to steps of BUCKET_STEP (32): floor(channel / 32).
 *  4. Count the albums per bucket (an album counts once per bucket, even if both its colours fall in it).
 *  5. A bucket is vivid when its representative has HSV saturation >= 0.25 and value between 0.2 and 0.95.
 *     The representative is the most frequent exact hex inside the bucket (ties: lower hex).
 *  6. Rank vivid buckets first (most albums first), then the others (most albums first); ties keep the
 *     order of first appearance in the sample. Return the representatives of the top 3.
 *
 * Computed values are cached per genre + hidden sources for CACHE_TTL_MINUTES (60), keyed without any
 * album state, so a new album shows up at the latest after the TTL.
 */
final class GenreColors
{
    public const MAX_COLORS = 3;

    public const SAMPLE_SIZE = 200;

    public const PALETTE_ENTRIES = 2;

    public const BUCKET_STEP = 32;

    public const MIN_SATURATION = 0.25;

    public const MIN_VALUE = 0.2;

    public const MAX_VALUE = 0.95;

    public const CACHE_TTL_MINUTES = 60;

    /**
     * @param  array<int, int>  $genreIds
     * @param  array<int, string>  $hidden  hidden sources of the request (scope + client), as for LibrarySources
     * @return array<int, list<string>> genre id => colours (empty array when none)
     */
    public static function forGenres(array $genreIds, array $hidden): array
    {
        $ids = array_values(array_unique(array_map('intval', $genreIds)));
        if ($ids === []) {
            return [];
        }

        $keys = [];
        foreach ($ids as $id) {
            $keys[$id] = self::cacheKey($id, $hidden);
        }

        $cached = Cache::many(array_values($keys));
        $result = [];
        $missing = [];
        foreach ($keys as $id => $key) {
            if (is_array($cached[$key] ?? null)) {
                $result[$id] = $cached[$key];
            } else {
                $missing[] = $id;
            }
        }

        foreach (self::compute($missing, $hidden) as $id => $colors) {
            Cache::put($keys[$id], $colors, now()->addMinutes(self::CACHE_TTL_MINUTES));
            $result[$id] = $colors;
        }

        return $result;
    }

    private static function cacheKey(int $genreId, array $hidden): string
    {
        sort($hidden);

        return 'genre-colors:v1:'.$genreId.':'.md5(implode(',', $hidden));
    }

    /**
     * One query for the (genre, album, cover) rows of all genres, one cache read for all their covers.
     *
     * @param  list<int>  $ids
     * @return array<int, list<string>>
     */
    private static function compute(array $ids, array $hidden): array
    {
        if ($ids === []) {
            return [];
        }

        $query = Album::query()
            ->join('genreables', fn ($join) => $join->on('genreables.genreable_id', '=', 'albums.id')
                ->where('genreables.genreable_type', (new Album)->getMorphClass()))
            ->whereIn('genreables.genre_id', $ids)
            ->select(['genreables.genre_id', 'albums.id', 'albums.images'])
            ->withCount('plays');
        LibrarySources::albums($query, $hidden);

        $perGenre = [];
        foreach ($query->orderByDesc('plays_count')->orderBy('albums.id')->cursor() as $album) {
            $genre = (int) $album->genre_id;
            $url = LibraryArtwork::coverUrl($album->images);
            if ($url !== null && count($perGenre[$genre] ?? []) < self::SAMPLE_SIZE) {
                $perGenre[$genre][] = $url;
            }
        }

        $entries = ArtworkCache::getMany(array_values(array_unique(array_merge([], ...array_values($perGenre)))));

        $result = [];
        foreach ($ids as $id) {
            $palettes = [];
            foreach ($perGenre[$id] ?? [] as $url) {
                $palettes[] = $entries[$url]['colors'] ?? null;
            }
            $result[$id] = self::dominant($palettes);
        }

        return $result;
    }

    /**
     * The dominant colours of albums' palettes (one palette per album, in sample order), see the class doc.
     *
     * @param  iterable<mixed>  $palettes
     * @return list<string>
     */
    public static function dominant(iterable $palettes): array
    {
        $buckets = [];
        foreach ($palettes as $palette) {
            if (!is_array($palette)) {
                continue;
            }

            $seen = [];
            foreach (array_slice(array_values($palette), 0, self::PALETTE_ENTRIES) as $hex) {
                $rgb = self::parse($hex);
                if ($rgb === null) {
                    continue;
                }

                $hex = sprintf('#%02x%02x%02x', ...$rgb);
                $bucket = implode('.', array_map(fn (int $c) => intdiv($c, self::BUCKET_STEP), $rgb));
                $buckets[$bucket] ??= ['albums' => 0, 'hexes' => []];
                $buckets[$bucket]['hexes'][$hex] = ($buckets[$bucket]['hexes'][$hex] ?? 0) + 1;
                if (!isset($seen[$bucket])) {
                    $seen[$bucket] = true;
                    $buckets[$bucket]['albums']++;
                }
            }
        }

        $ranked = [];
        $order = 0;
        foreach ($buckets as $bucket) {
            $hexes = $bucket['hexes'];
            uksort($hexes, fn (string $a, string $b) => [$hexes[$b], $a] <=> [$hexes[$a], $b]);
            $hex = array_key_first($hexes);
            $ranked[] = ['hex' => $hex, 'albums' => $bucket['albums'], 'vivid' => self::isVivid(self::parse($hex)), 'order' => $order++];
        }

        usort($ranked, fn (array $a, array $b) => [$b['vivid'], $b['albums'], $a['order']] <=> [$a['vivid'], $a['albums'], $b['order']]);

        return array_column(array_slice($ranked, 0, self::MAX_COLORS), 'hex');
    }

    /** @return array{int, int, int}|null */
    private static function parse(mixed $hex): ?array
    {
        if (!is_string($hex) || preg_match('/^#?([0-9a-f]{6})$/i', $hex, $m) !== 1) {
            return null;
        }

        return [hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2))];
    }

    /** HSV saturation >= MIN_SATURATION and value within MIN_VALUE..MAX_VALUE. */
    private static function isVivid(array $rgb): bool
    {
        $max = max($rgb) / 255;
        $min = min($rgb) / 255;
        $saturation = $max > 0 ? ($max - $min) / $max : 0.0;

        return $saturation >= self::MIN_SATURATION && $max >= self::MIN_VALUE && $max <= self::MAX_VALUE;
    }
}
