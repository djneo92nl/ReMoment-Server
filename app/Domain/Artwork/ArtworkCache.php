<?php

namespace App\Domain\Artwork;

use App\Domain\Media\NowPlaying;
use Illuminate\Support\Facades\Cache;

final class ArtworkCache
{
    private const TTL = 2592000; // 30 days

    public static function put(string $originalUrl, array $data): void
    {
        Cache::put(self::key($originalUrl), $data, self::TTL);
    }

    public static function get(string $originalUrl): ?array
    {
        return Cache::get(self::key($originalUrl));
    }

    public static function has(string $originalUrl): bool
    {
        return Cache::has(self::key($originalUrl));
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
