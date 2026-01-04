<?php

namespace App\Domain\Device\Cache;

use Illuminate\Support\Facades\Cache;

final class Volume
{
    private const TTL = 360;

    public static function updateVolume(int $deviceId, int $volume): void
    {
        Cache::put(
            self::volumeKey($deviceId),
            $volume,
            self::TTL
        );
    }

    public static function getVolume(int $deviceId)
    {
        $value = Cache::get(self::volumeKey($deviceId));

        return $value ?: false;
    }

    public static function forget(int $deviceId): void
    {
        Cache::forget(self::volumeKey($deviceId));
    }

    private static function volumeKey(int $deviceId): string
    {
        return "device:{$deviceId}:volume";
    }
}
