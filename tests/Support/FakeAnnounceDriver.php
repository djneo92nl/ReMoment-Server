<?php

namespace Tests\Support;

use App\Integrations\Contracts\PlayStreamUrlInterface;

/** FakeMovableDriver that can also play a stream URL: what an announcement needs (play the clip, then get its playback back). */
class FakeAnnounceDriver extends FakeMovableDriver implements PlayStreamUrlInterface
{
    /** @var array<int, array{0: string, 1: string, 2: ?string}> */
    public static array $played = [];

    public function playStreamUrl(string $url, string $title, ?string $art = null): void
    {
        self::$played[] = [$url, $title, $art];
    }
}
