<?php

namespace App\Domain\Spotify;

use App\Domain\Storage\StreamUrl;

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

    public static function title(): string
    {
        return 'Spotify ('.config('librespot.name').')';
    }
}
