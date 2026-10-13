<?php

namespace App\Domain\Artwork;

use Illuminate\Support\Facades\Storage;

/**
 * Covers embedded in the server's own audio files. The picture is kept once, by content, on the private `local`
 * disk (`covers/{sha1}`), and addressed by a pseudo URL ("remoment:cover/{sha1}") like logos and playlist
 * covers, so ProcessArtwork reads it from disk instead of downloading it and the artwork cache key
 * (md5 of the URL) is the same for every album that shares the picture.
 */
final class LocalCover
{
    private const URL_PREFIX = 'remoment:cover/';

    /** Smaller pictures are icons or thumbnails, not covers. */
    private const MIN_SIDE = 100;

    private const MAX_BYTES = 15 * 1024 * 1024;

    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    public static function isUrl(string $url): bool
    {
        return str_starts_with($url, self::URL_PREFIX);
    }

    /** The pseudo URL for a picture, after keeping its bytes; null when it is not a usable cover. */
    public static function store(?string $bytes): ?string
    {
        if ($bytes === null || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }

        $size = @getimagesizefromstring($bytes);
        if ($size === false || !in_array($size[2], self::TYPES, true) || min($size[0], $size[1]) < self::MIN_SIDE) {
            return null;
        }

        $hash = sha1($bytes);
        $disk = Storage::disk('local');
        if (!$disk->exists("covers/{$hash}")) {
            $disk->put("covers/{$hash}", $bytes);
        }

        return self::URL_PREFIX.$hash;
    }

    /** The picture bytes of a pseudo URL, null when they are gone. */
    public static function read(string $url): ?string
    {
        if (!self::isUrl($url) || !preg_match('/^[0-9a-f]{40}$/', $hash = substr($url, strlen(self::URL_PREFIX)))) {
            return null;
        }

        return Storage::disk('local')->get("covers/{$hash}");
    }
}
