<?php

namespace App\Domain\Device;

use Illuminate\Support\Facades\Cache;

final class DeviceCache
{
    private const TTL = 3600;

    public static function updateState(int $deviceId, State $state): void
    {
        Cache::put(
            self::stateKey($deviceId),
            $state->value,
            self::TTL
        );

        Cache::put(
            self::lastSeenKey($deviceId),
            now(),
            self::TTL
        );
    }

    public static function getState(int $deviceId): ?State
    {
        $value = Cache::get(self::stateKey($deviceId));

        return $value ? State::from($value) : State::Unreachable;
    }

    public static function getLastSeen(int $deviceId)
    {
        $value = Cache::get(self::lastSeenKey($deviceId));

        return $value ?: false;
    }

    public static function forget(int $deviceId): void
    {
        Cache::forget(self::stateKey($deviceId));
        Cache::forget(self::lastSeenKey($deviceId));
    }

    private static function stateKey(int $deviceId): string
    {
        return "device:{$deviceId}:state";
    }

    private static function lastSeenKey(int $deviceId): string
    {
        return "device:{$deviceId}:last_seen";
    }
}
