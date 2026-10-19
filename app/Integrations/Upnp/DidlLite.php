<?php

namespace App\Integrations\Upnp;

use App\Models\Media\Track;

/** The DIDL-Lite metadata a renderer takes with `SetAVTransportURI`, and the same read back from `GetPositionInfo`. */
final class DidlLite
{
    private const MIME_BY_EXTENSION = [
        'mp3' => 'audio/mpeg', 'flac' => 'audio/flac', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
        'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'opus' => 'audio/ogg', 'wav' => 'audio/wav', 'aiff' => 'audio/aiff',
    ];

    public static function mimeFor(string $url, ?Track $track = null): string
    {
        $mime = $track?->trackFiles()->with('file')->get()->pluck('file.mime_type')->filter()->first();

        if ($mime) {
            return $mime;
        }

        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return self::MIME_BY_EXTENSION[$extension] ?? 'audio/mpeg';
    }

    public static function build(string $url, string $title, ?string $artist, ?string $album, ?string $art, ?int $duration, string $mime): string
    {
        $e = fn (?string $value) => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $duration = $duration ? sprintf(' duration="%s"', self::time($duration)) : '';

        return '<DIDL-Lite xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/">'
            .'<item id="0" parentID="-1" restricted="1">'
            .'<dc:title>'.$e($title).'</dc:title>'
            .($artist ? '<upnp:artist>'.$e($artist).'</upnp:artist><dc:creator>'.$e($artist).'</dc:creator>' : '')
            .($album ? '<upnp:album>'.$e($album).'</upnp:album>' : '')
            .($art ? '<upnp:albumArtURI>'.$e($art).'</upnp:albumArtURI>' : '')
            .'<upnp:class>object.item.audioItem.musicTrack</upnp:class>'
            ."<res protocolInfo=\"http-get:*:{$e($mime)}:*\"{$duration}>{$e($url)}</res>"
            .'</item></DIDL-Lite>';
    }

    /** @return array{title: string, artist: string, album: string, art: string} empty strings for what is missing */
    public static function parse(?string $didl): array
    {
        $empty = ['title' => '', 'artist' => '', 'album' => '', 'art' => ''];
        $dom = new \DOMDocument;

        if (!$didl || !@$dom->loadXML($didl)) {
            return $empty;
        }

        $text = fn (string $tag) => trim($dom->getElementsByTagNameNS('*', $tag)->item(0)?->textContent ?? '');

        return [
            'title' => $text('title'),
            'artist' => $text('artist') ?: $text('creator'),
            'album' => $text('album'),
            'art' => $text('albumArtURI'),
        ];
    }

    /** H:MM:SS as UPnP writes durations and positions. */
    public static function time(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /** Seconds of a UPnP time ("0:03:21", "0:03:21.500"); null for "NOT_IMPLEMENTED", empty or unparsable. */
    public static function seconds(?string $time): ?int
    {
        if ($time === null || !preg_match('/^(\d+):(\d{1,2}):(\d{1,2})(?:\.\d+)?$/', trim($time), $m)) {
            return null;
        }

        return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
    }
}
