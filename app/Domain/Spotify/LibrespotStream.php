<?php

namespace App\Domain\Spotify;

use App\Domain\Storage\StreamUrl;
use App\Integrations\Contracts\ReportsStreamUrlInterface;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Services\SpotifyTokenService;

/** The Icecast stream of the librespot container (docs/architecture/spotify-stream.md). */
final class LibrespotStream
{
    public static function enabled(): bool
    {
        return (bool) config('librespot.enabled');
    }

    /** The address a speaker pulls the stream from: the host of {@see StreamUrl::base()} on Icecast's port. */
    public static function url(): string
    {
        $base = parse_url(StreamUrl::base());
        $host = $base['host'] ?? '127.0.0.1';

        return sprintf('%s://%s:%d/%s', $base['scheme'] ?? 'http', str_contains($host, ':') ? "[$host]" : $host,
            config('librespot.icecast.port'), ltrim((string) config('librespot.icecast.mount'), '/'));
    }

    /**
     * Map the receiver's Spotify Connect name to this speaker, as /settings/spotify-connect would. The speaker and the
     * Spotify device then behave as one (App\Domain\Device\SpotifyRouting): one card, pause/next/seek and the
     * queue go to Spotify. A receiver belongs to one speaker at a time, so an earlier mapping moves.
     */
    public static function routeTo(int $deviceId): void
    {
        $name = (string) config('librespot.name');

        DeviceMeta::where('key', 'spotify_connect_name')->where('value', $name)->where('device_id', '!=', $deviceId)->delete();
        DeviceMeta::updateOrCreate(['device_id' => $deviceId, 'key' => 'spotify_connect_name'], ['value' => $name]);
    }

    /** True while Spotify's own listener reports the receiver's playback for this speaker (connected and mapped to it). */
    public static function polledFor(int $deviceId): bool
    {
        return app(SpotifyTokenService::class)->isConnected()
            && DeviceMeta::where('key', 'spotify_connect_name')->where('value', (string) config('librespot.name'))->where('device_id', $deviceId)->exists();
    }

    /**
     * Does this speaker still play the stream (not something started on it since)? Asked of the device when its driver
     * can tell (UPnP, Sonos); one that cannot (B&O) is assumed to, which only matters while Spotify is paused.
     * The host is not compared: the speaker may know the server by another name than we do. Scheme and host aside,
     * the Icecast port and mount must be the loaded address's.
     */
    public static function stillStreaming(int $deviceId): bool
    {
        $driver = Device::find($deviceId)?->driver;

        if (!$driver instanceof ReportsStreamUrlInterface) {
            return true;
        }

        try {
            $loaded = $driver->loadedStreamUrl();
        } catch (\Throwable) {
            return true;
        }

        if ($loaded === null) {
            return false;
        }

        $ours = parse_url(self::url());
        $theirs = parse_url(preg_replace('#^x-rincon-mp3radio://#', 'http://', $loaded));

        return ($theirs['port'] ?? 80) === ($ours['port'] ?? 80)
            && ltrim((string) ($theirs['path'] ?? ''), '/') === ltrim((string) ($ours['path'] ?? ''), '/');
    }

    public static function title(): string
    {
        return 'Spotify ('.config('librespot.name').')';
    }
}
