<?php

namespace Tests\Support;

use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\PlayStreamUrlInterface;
use App\Integrations\Contracts\ReportsStreamUrlInterface;
use App\Models\Device;

/** A speaker that can play a stream and tells which URL it has loaded (set `$loaded` in a test). */
class FakeReportingStreamDriver implements MusicPlayerDriverInterface, PlayStreamUrlInterface, ReportsStreamUrlInterface
{
    public static ?string $loaded = null;

    public static bool $throw = false;

    public function __construct(public Device $device) {}

    public function playStreamUrl(string $url, string $title, ?string $art = null): void
    {
        self::$loaded = $url;
    }

    public function loadedStreamUrl(): ?string
    {
        if (self::$throw) {
            throw new \RuntimeException('unreachable');
        }

        return self::$loaded;
    }
}
