<?php

namespace Tests\Support;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\RepeatMode;
use App\Integrations\Contracts\LikeInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\RepeatInterface;
use App\Integrations\Contracts\ShuffleInterface;
use App\Models\Device;

/**
 * Bound as a device's `device_driver` in API tests for the touch client's
 * extra controls (shuffle, repeat, like). Records calls and can be told to
 * throw to simulate a device that doesn't respond.
 */
class FakeControlsDriver implements LikeInterface, MusicPlayerDriverInterface, RepeatInterface, ShuffleInterface
{
    /** @var array<int, array{0: string, 1: mixed}> */
    public static array $calls = [];

    public static ?\Exception $throw = null;

    public function __construct(public Device $device) {}

    public static function reset(): void
    {
        self::$calls = [];
        self::$throw = null;
    }

    private function record(string $method, mixed $argument = null): void
    {
        if (self::$throw) {
            throw self::$throw;
        }

        self::$calls[] = [$method, $argument];
    }

    public function getCurrentPlayingAttribute(): array
    {
        return DeviceCache::getNowPlaying($this->device->id)?->toArray() ?? [];
    }

    public function setShuffle(bool $shuffle): void
    {
        $this->record('setShuffle', $shuffle);
    }

    public function setRepeat(RepeatMode $mode): void
    {
        $this->record('setRepeat', $mode);
    }

    public function setLiked(bool $liked): void
    {
        $this->record('setLiked', $liked);
    }
}
