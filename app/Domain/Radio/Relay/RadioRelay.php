<?php

namespace App\Domain\Radio\Relay;

use App\Domain\Storage\StreamUrl;
use App\Models\RadioStation;
use Illuminate\Support\Facades\Cache;

/**
 * What the drivers and the listener use: ask for a station to be relayed, get the address a renderer plays, wait
 * for the mount. Demand lives in the cache (`radio:relay:wanted`, station id => last request time) and is read by
 * the manager (`radio:relay`), which starts and stops the ffmpeg children.
 */
final class RadioRelay
{
    private const WANTED_KEY = 'radio:relay:wanted';

    public static function enabled(): bool
    {
        return (bool) config('radio_relay.enabled');
    }

    /** Says this station is wanted now (again): starts its relay, or keeps it from stopping. */
    public static function want(RadioStation $station): void
    {
        $wanted = Cache::get(self::WANTED_KEY, []);
        $wanted[$station->id] = now()->timestamp;
        Cache::put(self::WANTED_KEY, $wanted, now()->addDay());
    }

    /** @return array<int, int> station id => when it was last asked for */
    public static function wanted(): array
    {
        return Cache::get(self::WANTED_KEY, []);
    }

    /** Forgets a station's demand (the relay stops once nobody listens). */
    public static function release(int $stationId): void
    {
        $wanted = Cache::get(self::WANTED_KEY, []);
        unset($wanted[$stationId]);
        Cache::put(self::WANTED_KEY, $wanted, now()->addDay());
    }

    /** The address a renderer plays: the host of the other stream addresses (`STREAM_BASE_URL`) on the Icecast port. */
    public static function url(RadioStation $station): string
    {
        $host = parse_url(StreamUrl::base(), PHP_URL_HOST) ?: '127.0.0.1';

        return 'http://'.$host.':'.config('radio_relay.icecast.port').'/'.RelayCommand::mount($station->id);
    }

    /**
     * Asks for the station and waits until its mount has a source, or the time is up. False when it never came
     * (the caller falls back to the station's own address).
     */
    public static function ensureLive(RadioStation $station, ?int $seconds = null, ?IcecastStatus $icecast = null): bool
    {
        if (!self::enabled()) {
            return false;
        }

        self::want($station);

        $icecast ??= new IcecastStatus;
        $mount = RelayCommand::mount($station->id);
        $deadline = microtime(true) + ($seconds ?? (int) config('radio_relay.live_wait_seconds'));

        do {
            if ($icecast->isLive($mount)) {
                return true;
            }

            usleep(500_000);
        } while (microtime(true) < $deadline);

        return false;
    }
}
