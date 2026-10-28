<?php

namespace App\Livewire\Concerns;

use App\Domain\Catalogue\WantedTracks;

/** The star on a streaming track: put it on the wishlist (something to buy or rip) or take it off. Streaming tracks are never favorited: favorites are local. */
trait WantsStreamingTracks
{
    /** @param  array{title?: mixed, artist?: mixed, album?: mixed, image?: mixed}  $track */
    public function toggleWant(array $track): void
    {
        $title = trim((string) ($track['title'] ?? ''));
        $artist = trim((string) ($track['artist'] ?? ''));
        if ($title === '' || $artist === '') {
            return;
        }

        if (WantedTracks::isWished($artist, $title)) {
            WantedTracks::unwishByName($artist, $title);

            return;
        }

        WantedTracks::wish($artist, $title, ($track['album'] ?? null) ?: null, ($track['image'] ?? null) ?: null, 'streaming');
    }
}
