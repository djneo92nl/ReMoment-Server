<?php

namespace Tests\Support;

use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\PlaysSpotifyItemsInterface;
use App\Models\Device;

/** A renderer that takes Spotify track items; records them, and whether it says it plays the stream. */
class FakeItemsDriver implements MusicPlayerDriverInterface, PlaysSpotifyItemsInterface
{
    /** @var array<int, array<int, mixed>> */
    public static array $items = [];

    public static bool $playsStream = true;

    public static bool $throw = false;

    public function __construct(public Device $device) {}

    public function playsSpotifyStream(): bool
    {
        return self::$playsStream;
    }

    public function playSpotifyItem(string $url, string $title, ?string $artist, ?string $album, ?string $art, ?int $duration): void
    {
        if (self::$throw) {
            throw new \RuntimeException('unreachable');
        }

        self::$items[] = [$url, $title, $artist, $album, $art, $duration];
    }
}
