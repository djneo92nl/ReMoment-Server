<?php

namespace App\Domain\Artwork;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The SD card artwork zip for the ESP32 touch client: covers and
 * backgrounds of the recently played albums plus the source logos, laid
 * out exactly as the client reads them from the card root. One zip per
 * background size (ArtworkBackgrounds::SIZES), since a client's card has a
 * single backgrounds folder for its screen. Built by BuildSdCardExport into
 * the private `local` disk; see docs/architecture/sd-card-export.md.
 */
final class SdCardExport
{
    /** Zip entry for a file, by hash: the client SD card layout. */
    public const COVER_ENTRY = 'remoment/covers/%s.jpg';

    public const BACKGROUND_ENTRY = 'remoment/backgrounds/%s.jpg';

    private const PENDING_KEY = 'artwork:sd-export:pending:%s';

    public static function zipPath(string $size = ArtworkBackgrounds::DEFAULT): string
    {
        return 'exports/'.self::downloadName($size);
    }

    public static function metaPath(string $size = ArtworkBackgrounds::DEFAULT): string
    {
        return "exports/remoment-sd-artwork-{$size}.json";
    }

    public static function downloadName(string $size = ArtworkBackgrounds::DEFAULT): string
    {
        return "remoment-sd-artwork-{$size}.zip";
    }

    /** @return array{built_at: string, bytes: int, albums: int, logos: int, skipped: int, limit: int, size: string}|null */
    public static function meta(string $size = ArtworkBackgrounds::DEFAULT): ?array
    {
        $disk = Storage::disk('local');

        if (!$disk->exists(self::zipPath($size)) || !$disk->exists(self::metaPath($size))) {
            return null;
        }

        return json_decode((string) $disk->get(self::metaPath($size)), true) ?: null;
    }

    /**
     * Every size with its last build and pending flag, for the admin card.
     *
     * @return array<string, array{label: string, meta: array|null, pending_since: string|null}>
     */
    public static function all(): array
    {
        $exports = [];
        foreach (ArtworkBackgrounds::SIZES as $size => $label) {
            $exports[$size] = ['label' => $label, 'meta' => self::meta($size), 'pending_since' => self::pendingSince($size)];
        }

        return $exports;
    }

    public static function markPending(string $size = ArtworkBackgrounds::DEFAULT): void
    {
        Cache::put(sprintf(self::PENDING_KEY, $size), now()->toIso8601String(), now()->addHour());
    }

    public static function clearPending(string $size = ArtworkBackgrounds::DEFAULT): void
    {
        Cache::forget(sprintf(self::PENDING_KEY, $size));
    }

    /** When a queued build was requested, while it hasn't finished (or failed) yet. */
    public static function pendingSince(string $size = ArtworkBackgrounds::DEFAULT): ?string
    {
        return Cache::get(sprintf(self::PENDING_KEY, $size));
    }
}
