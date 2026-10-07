<?php

namespace Tests\Support;

use App\Domain\Artwork\ArtworkCache;

/** Test fixtures for "this cover is processed": a complete artwork cache entry. */
trait CachesArtwork
{
    /** @param  array<string, mixed>  $overrides  keys that differ from the placeholder entry */
    protected function completeArtworkEntry(array $overrides = []): array
    {
        return $overrides + array_merge(
            array_fill_keys(ArtworkCache::REQUIRED_KEYS, '/storage/x.jpg'),
            ['colors' => ['#111111'], 'safe_colors' => ['#999999']],
        );
    }

    protected function cacheProcessedArtwork(string $url, array $overrides = []): void
    {
        ArtworkCache::put($url, $this->completeArtworkEntry($overrides));
    }
}
