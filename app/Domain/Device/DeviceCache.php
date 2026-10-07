<?php

namespace App\Domain\Device;

use App\Domain\Media\NowPlaying;
use App\Events\Device\DeviceStateChanged;
use App\Models\Device;
use Illuminate\Support\Facades\Cache;

final class DeviceCache
{
    private const TTL = 3600;

    public static function updateState(int $deviceId, State $state): void
    {
        $previous = Cache::get(self::stateKey($deviceId));

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

        if ($previous !== $state->value) {
            DeviceStateChanged::dispatch($deviceId, $state, $previous ? State::tryFrom($previous) : null);
        }
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

    /**
     * Now-playing of the first device that is currently playing, if any.
     */
    public static function firstPlaying(): ?NowPlaying
    {
        foreach (Device::pluck('id') as $id) {
            if (self::getState($id) === State::Playing && $nowPlaying = self::getNowPlaying($id)) {
                return $nowPlaying;
            }
        }

        return null;
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

    /** Seconds a listener's heartbeat lives: a listener that stops refreshing it counts as gone. */
    private const LISTENER_HEARTBEAT_SECONDS = 10;

    public static function isListenerRunning(int $deviceId): bool
    {
        return Cache::has(self::listenerKey($deviceId));
    }

    /** A device listener's heartbeat, renewed while it runs (the UI reads a device without one as unreachable). */
    public static function markListenerAlive(int|string $deviceId): void
    {
        Cache::put(self::listenerKey($deviceId), true, now()->addSeconds(self::LISTENER_HEARTBEAT_SECONDS));
    }

    public static function forgetListener(int|string $deviceId): void
    {
        Cache::forget(self::listenerKey($deviceId));
    }

    private static function listenerKey(int|string $deviceId): string
    {
        return "listener_running_{$deviceId}";
    }

    public static function setSpotifyRoutedDevice(int $deviceId): void
    {
        Cache::put('spotify_routed_to', $deviceId, 30);
    }

    public static function clearSpotifyRoutedDevice(): void
    {
        Cache::forget('spotify_routed_to');
    }

    public static function getSpotifyRoutedDeviceId(): ?int
    {
        return Cache::get('spotify_routed_to');
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
