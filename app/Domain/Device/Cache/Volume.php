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

        // A cached 0 is a real level (muted by volume), not a miss.
        return $value === null ? false : (int) $value;
    }

    /**
     * The device's volume from the cache (kept current by its listener), else read from the device
     * with `$read` (null when it reports none, answered as 0) and cached.
     *
     * @param  \Closure(): ?int  $read
     */
    public static function remember(int $deviceId, \Closure $read): int
    {
        $cached = self::getVolume($deviceId);
        if ($cached !== false) {
            return $cached;
        }

        $level = $read();
        if ($level === null) {
            return 0;
        }

        self::updateVolume($deviceId, $level);

        return $level;
    }

    public static function updateMuted(int $deviceId, bool $muted): void
    {
        Cache::put(self::mutedKey($deviceId), $muted, self::TTL);
    }

    public static function getMuted(int $deviceId): ?bool
    {
        $value = Cache::get(self::mutedKey($deviceId));

        return is_bool($value) ? $value : null;
    }

    public static function forget(int $deviceId): void
    {
        Cache::forget(self::volumeKey($deviceId));
        Cache::forget(self::mutedKey($deviceId));
    }

    private static function mutedKey(int $deviceId): string
    {
        return "device:{$deviceId}:muted";
    }

    private static function volumeKey(int $deviceId): string
    {
        return "device:{$deviceId}:volume";
    }
}
