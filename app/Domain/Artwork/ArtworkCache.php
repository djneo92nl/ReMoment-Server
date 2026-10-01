<?php

namespace App\Domain\Artwork;

use App\Domain\Media\NowPlaying;
use Illuminate\Support\Facades\Cache;

final class ArtworkCache
{
    private const TTL = 2592000; // 30 days

    /**
     * Keys every entry written by ProcessArtwork has. Entries cached before a
     * key was added count as missing, so they get regenerated on next play
     * (DispatchArtworkProcessing) or by `library:backfill-artwork`.
     */
    public const REQUIRED_KEYS = ['proxy_512', 'proxy_320', 'proxy_120', 'proxy_bg', 'colors', 'safe_colors'];

    public static function put(string $originalUrl, array $data): void
    {
        Cache::put(self::key($originalUrl), $data, self::TTL);
    }

    public static function get(string $originalUrl): ?array
    {
        return Cache::get(self::key($originalUrl));
    }

    /**
     * Entries for several URLs in one cache round trip.
     *
     * @param  array<int, string>  $originalUrls
     * @return array<string, array|null> keyed by original URL
     */
    public static function getMany(array $originalUrls): array
    {
        if ($originalUrls === []) {
            return [];
        }

        $keys = [];
        foreach ($originalUrls as $url) {
            $keys[$url] = self::key($url);
        }

        $entries = Cache::many(array_values($keys));

        return array_map(fn (string $key) => $entries[$key] ?? null, $keys);
    }

    /** Whether a complete entry exists. A partial (outdated) entry is still returned by get(). */
    public static function has(string $originalUrl): bool
    {
        $entry = self::get($originalUrl);

        return is_array($entry) && self::isComplete($entry);
    }

    public static function isComplete(array $entry): bool
    {
        return array_diff(self::REQUIRED_KEYS, array_keys($entry)) === [];
    }

    public static function forget(string $originalUrl): void
    {
        Cache::forget(self::key($originalUrl));
    }

    public static function extractImageUrl(NowPlaying $nowPlaying): ?string
    {
        $candidates = [
            $nowPlaying->album?->images[0] ?? null,
            $nowPlaying->track?->images[0] ?? null,
            $nowPlaying->radio?->images[0] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }

            if (is_array($candidate) && isset($candidate['url']) && $candidate['url'] !== '') {
                return $candidate['url'];
            }
        }

        return null;
    }

    private static function key(string $originalUrl): string
    {
        return 'artwork:'.md5($originalUrl);
    }
}
