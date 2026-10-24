<?php

namespace App\Domain\Library;

use Closure;
use Illuminate\Support\Facades\Cache;

use function Illuminate\Support\defer;

/**
 * Cache for the expensive library overviews (counts, covers, genres): kept for hours, and marked stale when the
 * library changes. A stale entry is still served at once and refreshed after the response, so a visit never waits
 * for a recompute; only the very first one does.
 *
 * "The library changed" is a write to one of TABLES, seen through the query log (see observe()), so imports,
 * merges, pivot syncs and mass updates all count without every call site having to say so. Plays and metadata are
 * not in TABLES: they change all day and would keep every entry stale.
 */
final class LibraryCache
{
    private const CHANGED = 'library:changed-at';

    /** Tables whose writes change what the library shows. */
    private const TABLES = ['albums', 'artists', 'tracks', 'playlists', 'playlist_track', 'genres', 'genreables', 'files', 'track_files'];

    /** Seconds an entry is left alone after it was computed, however busy the library is (an import writes all the time). */
    public const MIN_REFRESH = 120;

    /** Seconds after which an entry is recomputed even when no write was seen: the net under a missed write. */
    public const DEFAULT_MAX_AGE = 21600;

    public static function touch(): void
    {
        Cache::forever(self::CHANGED, self::now());
    }

    /** Called with every executed SQL statement; marks the library changed when it writes to a library table. */
    public static function observe(string $sql): void
    {
        if (!preg_match('/^\s*(?:insert\s+(?:ignore\s+)?into|update|delete\s+from)\s+`?(\w+)`?(\s+set\s+`?(\w+))?/i', $sql, $m)) {
            return;
        }

        // Play bookkeeping (last played, favourite) is not a change of the library itself.
        if (in_array(strtolower($m[3] ?? ''), ['last_played_at', 'favorited_at'], true)) {
            return;
        }

        if (in_array(strtolower($m[1]), self::TABLES, true)) {
            self::touch();
        }
    }

    /**
     * @template T
     *
     * @param  Closure():T  $compute  must return a value that serializes (arrays, collections)
     * @param  int  $maxAge  seconds; short for what plays change (a recency order)
     * @return T
     */
    public static function remember(string $key, Closure $compute, int $maxAge = self::DEFAULT_MAX_AGE): mixed
    {
        $entry = Cache::get($key);

        if (!is_array($entry) || !array_key_exists('v', $entry)) {
            return self::refresh($key, $compute, wait: true);
        }

        $age = self::now() - $entry['at'];
        $changed = $age > self::MIN_REFRESH && $entry['at'] < (float) Cache::get(self::CHANGED, 0);

        if ($age > $maxAge || $changed) {
            defer(fn () => self::refresh($key, $compute, wait: false), 'library-cache:'.$key);
        }

        return $entry['v'];
    }

    /** Seconds with fractions, from the app clock (so tests can travel). */
    private static function now(): float
    {
        return (float) now()->format('U.u');
    }

    private static function refresh(string $key, Closure $compute, bool $wait): mixed
    {
        $lock = Cache::lock($key.':lock', 120);

        if (!$lock->get()) {
            // Someone is computing it already: a cold visit computes for itself rather than wait, a refresh gives up.
            return $wait ? $compute() : null;
        }

        try {
            $at = self::now();   // a write while computing leaves the entry stale again
            $value = $compute();
            Cache::forever($key, ['at' => $at, 'v' => $value]);

            return $value;
        } finally {
            $lock->release();
        }
    }
}
