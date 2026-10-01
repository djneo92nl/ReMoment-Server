<?php

namespace App\Domain\Device\Cache;

use App\Domain\Device\PlaybackModes;
use Illuminate\Support\Facades\Cache;

/** Last known shuffle / repeat / liked state per device, as reported by its listener. */
final class Modes
{
    private const TTL = 3600;

    public static function get(int $deviceId): PlaybackModes
    {
        $value = Cache::get(self::key($deviceId));

        return is_array($value) ? PlaybackModes::fromArray($value) : new PlaybackModes;
    }

    public static function put(int $deviceId, PlaybackModes $modes): void
    {
        Cache::put(self::key($deviceId), $modes->toArray(), self::TTL);
    }

    public static function forget(int $deviceId): void
    {
        Cache::forget(self::key($deviceId));
    }

    private static function key(int $deviceId): string
    {
        return "device:{$deviceId}:modes";
    }
}
