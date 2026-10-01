<?php

namespace App\Domain\Artwork;

use App\Jobs\ProcessArtwork;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The small artwork object of the library API (`/api/library/*`):
 * `{ hash, proxy_120, proxy_320 }` for a processed cover, else null. Never
 * waits for a download: an unprocessed cover is queued once an hour at most.
 */
final class LibraryItemArtwork
{
    private const KEYS = ['proxy_120', 'proxy_320'];

    /** @return array{hash: string, proxy_120: string, proxy_320: string}|null */
    public static function forUrl(?string $url): ?array
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (!ArtworkCache::has($url) && Cache::add('artwork:library-queued:'.md5($url), true, now()->addHour())) {
            ProcessArtwork::dispatch($url);
        }

        $entry = ArtworkCache::get($url);
        $disk = Storage::disk('public');
        $item = ['hash' => md5($url)];

        foreach (self::KEYS as $key) {
            if (isset($entry[$key])) {
                $item[$key] = $entry[$key];
            } elseif ($disk->exists($path = LibraryArtwork::path($url, $key))) {
                // The cache entry expired (30 days); the files stay.
                $item[$key] = $disk->url($path);
            } else {
                return null;
            }
        }

        return $item;
    }

    /** @return array{hash: string, proxy_120: string, proxy_320: string}|null */
    public static function forImages(?array $images): ?array
    {
        return self::forUrl(LibraryArtwork::coverUrl($images));
    }
}
