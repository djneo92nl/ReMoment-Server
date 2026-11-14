<?php

namespace App\Domain\Radio\Relay;

use App\Domain\Storage\StreamUrl;
use App\Models\Device;
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
        return self::urlFor($station->id);
    }

    public static function urlFor(int $stationId): string
    {
        $host = parse_url(StreamUrl::base(), PHP_URL_HOST) ?: '127.0.0.1';

        return 'http://'.$host.':'.config('radio_relay.icecast.port').'/'.RelayCommand::mount($stationId);
    }

    /**
     * Gives the relayed mount the title the station reports now, for renderers that show ICY titles. Only while the
     * station is relayed (wanted), and only when the title changed since the last push. True when it was pushed.
     */
    public static function pushTitle(RadioStation $station, string $title, ?IcecastStatus $icecast = null): bool
    {
        if (!self::enabled() || !isset(self::wanted()[$station->id])) {
            return false;
        }

        $key = "radio:relay:title:{$station->id}";

        if (Cache::get($key) === $title) {
            return false;
        }

        if (!($icecast ?? new IcecastStatus)->setTitle(RelayCommand::mount($station->id), $title)) {
            return false; // The mount is not up yet (or Icecast refused): try again at the next poll.
        }

        Cache::put($key, $title, now()->addHour());

        return true;
    }

    /** Whether the renderer `$device` was last sent this station through the relay (UPnP renderers; false for anything else). */
    public static function playedVia(Device $device, RadioStation $station): bool
    {
        $played = \App\Integrations\Upnp\MusicPlayerDriver::radio($device->id);

        return $played !== null && $played['station_id'] === $station->id && $played['uri'] === self::url($station);
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
