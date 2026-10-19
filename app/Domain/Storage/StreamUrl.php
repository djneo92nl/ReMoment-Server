<?php

namespace App\Domain\Storage;

/**
 * The address a speaker uses to pull one of our own files. A speaker is not the browser: it needs a host it can
 * resolve and reach, so `STREAM_BASE_URL` (config `app.stream_url`, e.g. `http://192.168.1.48:8080`) overrides
 * `APP_URL`, which on a dev machine is often a name only that machine knows (`remomentserver.test`).
 * Built when a track is played, never stored, so changing the setting needs no rescan.
 */
class StreamUrl
{
    public static function base(): string
    {
        return rtrim(config('app.stream_url') ?: config('app.url') ?: url('/'), '/');
    }

    public static function for(int $trackId): string
    {
        return self::base().'/stream/'.$trackId;
    }

    /** The address with the file's extension (`/stream/12.flac`), for renderers that decide the format from it. */
    public static function withExtension(int $trackId, ?string $extension): string
    {
        return self::for($trackId).($extension ? '.'.strtolower(ltrim($extension, '.')) : '');
    }

    /** A `/storage/...` file (artwork) of this server as the speaker reaches it; null for anything else. */
    public static function storage(string $url): ?string
    {
        $at = strpos($url, '/storage/');

        return $at === false ? null : self::base().substr($url, $at);
    }

    /** The track id when $url is one of our stream URLs, whatever host it was built with. */
    public static function trackId(string $url): ?int
    {
        return preg_match('#^https?://[^/]+/stream/(\d+)(?:\.[A-Za-z0-9]+)?(?:\?.*)?$#', $url, $m) ? (int) $m[1] : null;
    }
}
