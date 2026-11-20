<?php

namespace App\Domain\SubDevices;

use App\Domain\Media\Source;
use Illuminate\Support\Facades\Cache;

/**
 * The source or input a device last reported as selected, kept apart from its now playing: a track notification
 * replaces the now playing (and its `source`) while the input stays what it was. Written by `UpdateDeviceCache` when a
 * now playing carries a source, forgotten when the device goes idle; read by `LinkState` and the link actions.
 */
final class ActiveInput
{
    private const TTL = 3600;

    public static function get(int $deviceId): ?Source
    {
        $value = Cache::get(self::key($deviceId));

        return $value instanceof Source ? $value : null;
    }

    public static function put(int $deviceId, Source $source): void
    {
        Cache::put(self::key($deviceId), $source, self::TTL);
    }

    public static function forget(int $deviceId): void
    {
        Cache::forget(self::key($deviceId));
    }

    private static function key(int $deviceId): string
    {
        return "device:{$deviceId}:active_input";
    }
}
