<?php

namespace App\Domain\Artwork;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The SD card artwork zip for the ESP32 touch client: covers and
 * backgrounds of the recently played albums plus the source logos, laid
 * out exactly as the client reads them from the card root. Built by
 * BuildSdCardExport into the private `local` disk; see
 * docs/architecture/sd-card-export.md.
 */
final class SdCardExport
{
    public const ZIP_PATH = 'exports/remoment-sd-artwork.zip';

    public const META_PATH = 'exports/remoment-sd-artwork.json';

    public const DOWNLOAD_NAME = 'remoment-sd-artwork.zip';

    /** Zip entry for a file, by hash: the client SD card layout. */
    public const COVER_ENTRY = 'remoment/covers/%s.jpg';

    public const BACKGROUND_ENTRY = 'remoment/backgrounds/%s.jpg';

    private const PENDING_KEY = 'artwork:sd-export:pending';

    /** @return array{built_at: string, bytes: int, albums: int, logos: int, skipped: int, limit: int}|null */
    public static function meta(): ?array
    {
        $disk = Storage::disk('local');

        if (!$disk->exists(self::ZIP_PATH) || !$disk->exists(self::META_PATH)) {
            return null;
        }

        return json_decode((string) $disk->get(self::META_PATH), true) ?: null;
    }

    public static function markPending(): void
    {
        Cache::put(self::PENDING_KEY, now()->toIso8601String(), now()->addHour());
    }

    public static function clearPending(): void
    {
        Cache::forget(self::PENDING_KEY);
    }

    /** When a queued build was requested, while it hasn't finished (or failed) yet. */
    public static function pendingSince(): ?string
    {
        return Cache::get(self::PENDING_KEY);
    }
}
