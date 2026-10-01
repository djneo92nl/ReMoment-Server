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
        Cache::forget(self::ownKey($deviceId));
    }

    /**
     * What the device's own listener last reported, kept apart from what is
     * shown while Spotify is routed to the device (see SpotifyRouting), so it
     * can be restored afterwards. Null when its listener reported nothing.
     */
    public static function own(int $deviceId): ?PlaybackModes
    {
        $value = Cache::get(self::ownKey($deviceId));

        return is_array($value) ? PlaybackModes::fromArray($value) : null;
    }

    public static function putOwn(int $deviceId, PlaybackModes $modes): void
    {
        Cache::put(self::ownKey($deviceId), $modes->toArray(), self::TTL);
    }

    private static function ownKey(int $deviceId): string
    {
        return "device:{$deviceId}:own_modes";
    }

    private static function key(int $deviceId): string
    {
        return "device:{$deviceId}:modes";
    }
}
