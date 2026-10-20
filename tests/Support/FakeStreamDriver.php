<?php

namespace Tests\Support;

use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\PlayStreamUrlInterface;
use App\Models\Device;

/** A driver that can play a live stream URL; records what it was given. */
class FakeStreamDriver implements MusicPlayerDriverInterface, PlayStreamUrlInterface
{
    /** @var array<int, array{0: string, 1: string, 2: ?string}> */
    public static array $played = [];

    public function __construct(public Device $device) {}

    public function playStreamUrl(string $url, string $title, ?string $art = null): void
    {
        self::$played[] = [$url, $title, $art];
    }
}
