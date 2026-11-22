<?php

namespace App\Domain\Podcasts;

use App\Domain\Artwork\ArtworkCache;
use App\Jobs\ProcessArtwork;
use Illuminate\Support\Facades\Cache;

/**
 * The cover of a podcast or episode: the feed's image through the artwork pipeline, never the publisher's original URL.
 * Never waits for a download: an image not processed yet is null (the placeholder) and queued once an hour at most.
 */
final class PodcastArtwork
{
    /** @return array{proxy_120: ?string, proxy_320: ?string, proxy_512: ?string, colors: mixed}|null */
    public static function resolve(?string $url): ?array
    {
        $url = trim((string) $url);

        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        $entry = ArtworkCache::get($url);

        if (!is_array($entry)) {
            if (Cache::add('artwork:podcast-queued:'.md5($url), true, now()->addHour())) {
                ProcessArtwork::dispatch($url);
            }

            return null;
        }

        return [
            'proxy_120' => $entry['proxy_120'] ?? null,
            'proxy_320' => $entry['proxy_320'] ?? null,
            'proxy_512' => $entry['proxy_512'] ?? null,
            'colors' => $entry['safe_colors'] ?? $entry['colors'] ?? null,
        ];
    }
}
