<?php

namespace App\Domain\Device;

use App\Domain\Media\NowPlaying;
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

    public static function forgetNowPlaying(int $deviceId)
    {
        Cache::forget(self::nowPlayingKey($deviceId));
    }

    public function updateNowPlaying(int $deviceId, NowPlaying $nowPlaying): void
    {
        Cache::put(
            self::nowPlayingKey($deviceId),
            $nowPlaying,
            self::TTL
        );
    }

    public static function getNowPlaying(int $deviceId): ?NowPlaying
    {
        $value = Cache::get(self::nowPlayingKey($deviceId));

        return $value ? $value : null;
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

    private static function nowPlayingKey(int $deviceId): string
    {
        return "device:{$deviceId}:now_playing";
    }
}
