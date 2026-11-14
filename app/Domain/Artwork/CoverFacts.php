<?php

namespace App\Domain\Artwork;

use App\Domain\Library\Enrichment;
use App\Models\Media\Album;
use Illuminate\Support\Facades\Http;

/**
 * What the cover health report (`LibraryReports` key `covers`) needs to know about an album's cover, kept as album
 * metadata so the report stays a query: `cover_size` ("{width}x{height}" of the original image, not of the proxies),
 * `cover_sha1` (content hash of that original) and `embedded_cover_sha1` (hash of the picture embedded in the album's
 * own files, written by the file indexer). Recorded when ProcessArtwork processes the cover, or by
 * `php artisan artwork:measure-covers` for covers processed before this existed.
 */
final class CoverFacts
{
    public const SOURCE = 'artwork';

    public const EMBEDDED_SOURCE = 'local';

    /** Keeps the facts of the image bytes in use for an album; false when the bytes are not an image. */
    public static function record(Album $album, string $bytes): bool
    {
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return false;
        }

        Enrichment::save($album, 'cover_size', $size[0].'x'.$size[1], 'string', self::SOURCE);
        Enrichment::save($album, 'cover_sha1', sha1($bytes), 'string', self::SOURCE);

        return true;
    }

    /** Remembers the picture embedded in an own file of the album (the hash of its bytes). */
    public static function recordEmbedded(Album $album, ?array $picture): void
    {
        $data = $picture['data'] ?? null;
        if (is_string($data) && $data !== '') {
            Enrichment::save($album, 'embedded_cover_sha1', sha1($data), 'string', self::EMBEDDED_SOURCE);
        }
    }

    /** Measures the cover in use for one album (read from disk for an embedded one, else downloaded). False when it could not be read. */
    public static function measure(Album $album): bool
    {
        $url = LibraryArtwork::coverUrl($album->images ?? []);
        if ($url === null) {
            return false;
        }

        try {
            $bytes = LocalCover::isUrl($url)
                ? LocalCover::read($url)
                : Http::timeout(30)->withUserAgent(config('metadata.user_agent'))->get($url)->throw()->body();
        } catch (\Throwable) {
            return false;
        }

        return $bytes !== null && self::record($album, $bytes);
    }

    /** "500x500" as [500, 500], null when malformed. */
    public static function parse(?string $size): ?array
    {
        return $size !== null && preg_match('/^(\d+)x(\d+)$/', $size, $m) ? [(int) $m[1], (int) $m[2]] : null;
    }
}
