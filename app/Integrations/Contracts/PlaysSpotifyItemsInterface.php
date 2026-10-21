<?php

namespace App\Integrations\Contracts;

/** A renderer that plays the Spotify stream as one item per track, so each track carries its own title and art. */
interface PlaysSpotifyItemsInterface
{
    /** Is the device playing the Spotify stream (or one of its track items) right now? Only then are items handed to it. */
    public function playsSpotifyStream(): bool;

    /** The next track's item: queued behind the one that plays (gapless where the renderer can), or started when none plays. */
    public function playSpotifyItem(string $url, string $title, ?string $artist, ?string $album, ?string $art, ?int $duration): void;
}
