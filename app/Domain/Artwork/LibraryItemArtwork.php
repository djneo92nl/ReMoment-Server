<?php

namespace App\Domain\Artwork;

use App\Jobs\ProcessArtwork;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The small artwork object of the library API (`/api/library/*`):
 * `{ hash, proxy_120, proxy_320 }` for a processed cover, else null. The proxy URLs are root-relative
 * (`/storage/...`), never with a host. Never
 * waits for a download: an unprocessed cover is queued once an hour at most.
 */
final class LibraryItemArtwork
{
    private const KEYS = ['proxy_120', 'proxy_320'];

    /**
     * @param  array<int, string>  $sources  For a playlist composite: its covers (see ProcessArtwork)
     * @return array{hash: string, proxy_120: string, proxy_320: string}|null
     */
    public static function forUrl(?string $url, array $sources = []): ?array
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (!ArtworkCache::has($url) && Cache::add('artwork:library-queued:'.md5($url), true, now()->addHour())) {
            try {
                ProcessArtwork::dispatch($url, $sources);
            } catch (\Throwable $e) {
                // A cover that cannot be processed (queue down, unreachable image) must not break the page: it shows its placeholder.
                report($e);
            }
        }

        $entry = ArtworkCache::get($url);
        $disk = Storage::disk('public');
        $item = ['hash' => md5($url)];

        foreach (self::KEYS as $key) {
            // Always root-relative (`/storage/...`): a cached URL carries the APP_URL of whoever made it
            // (a queue worker says `http://localhost`), which no client can reach.
            if (isset($entry[$key])) {
                $item[$key] = self::webUrl($entry[$key]);
            } elseif ($disk->exists($path = LibraryArtwork::path($url, $key))) {
                // The cache entry expired (30 days); the files stay.
                $item[$key] = self::webUrl($disk->url($path));
            } else {
                return null;
            }
        }

        return $item;
    }

    /**
     * The web UI's URL for an original image from DLNA, Spotify, a radio station and so on: always
     * our processed proxy at this size (120 or 320), never the original. Null until it is processed
     * (it is queued meanwhile), so callers show their placeholder.
     */
    public static function proxy(?string $url, int $size = 320): ?string
    {
        return self::webUrl(self::forUrl($url)["proxy_{$size}"] ?? null);
    }

    /** @return array{hash: string, proxy_120: string, proxy_320: string}|null */
    public static function forImages(?array $images): ?array
    {
        return self::forUrl(LibraryArtwork::coverUrl($images));
    }

    /**
     * A proxy URL as the web UI should load it: root-relative from `/storage/` on, so
     * it works on whatever host the page was opened with (cached URLs carry APP_URL).
     */
    public static function webUrl(?string $url): ?string
    {
        $pos = $url === null ? false : strpos($url, '/storage/');

        return $pos === false ? $url : substr($url, $pos);
    }
}
