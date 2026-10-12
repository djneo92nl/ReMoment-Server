<?php

namespace App\Domain\Artwork;

/**
 * Cache entries read in one go by LibraryItemArtwork::preload(), by original URL. Valid for the
 * request that loaded them only (a new request object empties it, which also covers an app instance
 * that serves several requests, as in tests), and bound as a scoped singleton (AppServiceProvider)
 * so a worker does not keep it between jobs.
 */
final class ArtworkEntryMemo
{
    /** @var array<string, array|null> */
    private array $entries = [];

    private ?object $request = null;

    /** @param  array<string, array|null>  $entries */
    public function remember(array $entries): void
    {
        $this->sync();
        $this->entries = $entries + $this->entries;
    }

    public function has(string $url): bool
    {
        $this->sync();

        return array_key_exists($url, $this->entries);
    }

    public function get(string $url): ?array
    {
        return $this->entries[$url] ?? null;
    }

    private function sync(): void
    {
        $current = app()->bound('request') ? app('request') : null;

        if ($current !== $this->request) {
            $this->request = $current;
            $this->entries = [];
        }
    }
}
