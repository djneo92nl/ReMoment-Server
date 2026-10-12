<?php

namespace App\Domain\Artwork;

use App\Jobs\ProcessArtwork;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The small artwork object of the library API (`/api/library/*`):
 * `{ hash, proxy_120, proxy_320, colors, safe_colors }` for a processed cover, else null. The proxy URLs
 * are root-relative (`/storage/...`), never with a host. `colors`/`safe_colors` are the palette
 * ProcessArtwork stored in the cover's cache entry (as in the full artwork object), or null when the
 * entry expired (the files stay) or predates the palette. Never waits for a download: an unprocessed
 * cover is queued once an hour at most.
 *
 * A list endpoint calls preload() with all its cover URLs first: one cache round trip for the page
 * instead of reads per item.
 */
final class LibraryItemArtwork
{
    private const KEYS = ['proxy_120', 'proxy_320'];

    /**
     * Reads the cache entries of these cover URLs in one round trip, for the forUrl() calls of this
     * request (the memo is request scoped, see AppServiceProvider). Null/empty URLs are skipped.
     *
     * @param  iterable<int, string|null>  $urls
     */
    public static function preload(iterable $urls): void
    {
        $unique = [];
        foreach ($urls as $url) {
            if ($url !== null && $url !== '') {
                $unique[$url] = $url;
            }
        }

        app(ArtworkEntryMemo::class)->remember(ArtworkCache::getMany(array_values($unique)));
    }

    /** Cover URLs of albums (their first image) for preload(). @param  iterable<int, Album|null>  $albums */
    public static function urlsOfAlbums(iterable $albums): array
    {
        $urls = [];
        foreach ($albums as $album) {
            $urls[] = LibraryArtwork::coverUrl($album?->images);
        }

        return array_values(array_filter($urls));
    }

    /**
     * @param  array<int, string>  $sources  For a playlist composite: its covers (see ProcessArtwork)
     * @return array{hash: string, proxy_120: string, proxy_320: string, colors: array|null, safe_colors: array|null}|null
     */
    public static function forUrl(?string $url, array $sources = [], array $keys = self::KEYS): ?array
    {
        if ($url === null || $url === '') {
            return null;
        }

        $memo = app(ArtworkEntryMemo::class);
        $entry = $memo->has($url) ? $memo->get($url) : ArtworkCache::get($url);

        if (!(is_array($entry) && ArtworkCache::isComplete($entry)) && Cache::add('artwork:library-queued:'.md5($url), true, now()->addHour())) {
            try {
                ProcessArtwork::dispatch($url, $sources);
            } catch (\Throwable $e) {
                // A cover that cannot be processed (queue down, unreachable image) must not break the page: it shows its placeholder.
                report($e);
            }

            // A synchronous queue has processed it by now.
            $entry = ArtworkCache::get($url);
        }

        $disk = Storage::disk('public');
        $item = ['hash' => md5($url)];

        foreach ($keys as $key) {
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

        $item['colors'] = self::palette($entry['colors'] ?? null);
        $item['safe_colors'] = self::palette($entry['safe_colors'] ?? null);

        return $item;
    }

    /** @return list<string>|null */
    private static function palette(mixed $colors): ?array
    {
        return is_array($colors) ? array_values($colors) : null;
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

    /**
     * An artist's fanart: the small object plus the processed `proxy_bg` backdrops (blurred, darkened,
     * per client screen size) for clients and the web hero. Null until processed (queued meanwhile).
     */
    public static function fanart(?string $url): ?array
    {
        return self::forUrl($url, [], [...self::KEYS, 'proxy_512', 'proxy_bg', 'proxy_bg_320x480', 'proxy_bg_480x480']);
    }

    /** @return array{hash: string, proxy_120: string, proxy_320: string, colors: array|null, safe_colors: array|null}|null */
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
