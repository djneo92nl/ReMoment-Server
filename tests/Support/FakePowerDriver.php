<?php

namespace Tests\Support;

use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\PowerInterface;
use App\Models\Device;

/** Bound as a device's `device_driver` in power API tests. */
class FakePowerDriver implements MusicPlayerDriverInterface, PowerInterface
{
    /** @var string[] */
    public static array $calls = [];

    public static ?\Exception $throw = null;

    /** Like Mozart: standby works, waking up doesn't. */
    public static bool $standbyOnly = false;

    public function __construct(public Device $device) {}

    public static function reset(): void
    {
        self::$calls = [];
        self::$throw = null;
        self::$standbyOnly = false;
    }

    public function getCurrentPlayingAttribute(): array
    {
        return [];
    }

    public function powerOn(): void
    {
        if (self::$standbyOnly) {
            throw new UnsupportedOperationException('This device cannot be switched on remotely.');
        }

        $this->record('powerOn');
    }

    public function standby(): void
    {
        $this->record('standby');
    }

    private function record(string $method): void
    {
        if (self::$throw) {
            throw self::$throw;
        }

        self::$calls[] = $method;
    }
}
