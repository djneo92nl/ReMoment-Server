<?php

namespace App\Domain\Artwork;

/**
 * Full-screen background sizes rendered by ProcessArtwork for the touch
 * clients, as "{width}x{height}". Each becomes `bg_{size}.jpg` on disk and
 * `proxy_bg_{size}` in the artwork object, except the original 1024x600,
 * which keeps its key `proxy_bg` for existing clients. A client picks
 * `proxy_bg_{width}x{height}` for its screen and falls back to `proxy_bg`.
 *
 * To add a size: add it to SIZES and its key to ArtworkCache::REQUIRED_KEYS
 * and LibraryArtwork::CLIENT_FILES.
 */
final class ArtworkBackgrounds
{
    /** Size => the client screen it is for. */
    public const SIZES = [
        '1024x600' => '7" landscape',
        '320x480' => '3.5" portrait',
    ];

    /** The original size, published as `proxy_bg`. */
    public const DEFAULT = '1024x600';

    public static function key(string $size): string
    {
        return $size === self::DEFAULT ? 'proxy_bg' : "proxy_bg_{$size}";
    }

    public static function file(string $size): string
    {
        return "bg_{$size}.jpg";
    }

    /** @return array{0: int, 1: int} [width, height] */
    public static function dimensions(string $size): array
    {
        [$width, $height] = explode('x', $size);

        return [(int) $width, (int) $height];
    }

    public static function isSize(mixed $size): bool
    {
        return is_string($size) && isset(self::SIZES[$size]);
    }
}
