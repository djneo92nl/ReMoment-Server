<?php

namespace App\Domain\Catalogue;

use App\Models\File;
use App\Models\Media\Album;
use App\Models\Media\PhysicalItem;
use Illuminate\Support\Facades\Log;

/**
 * The link between a ripped disc and the CD on the shelf (workstream 6 and 7): a file that arrives carrying the
 * MusicBrainz release id (the ripper writes MUSICBRAINZ_ALBUMID) or the barcode of a CD in the physical collection
 * marks that item ripped and ties it to the album the file landed in. Only `cd` items: a disc is what gets ripped.
 *
 * Called from `FileIndexer::attach`, so every way a file enters the library (the admin upload the ripper uses, the
 * inbox import, a scan) links the same way. A different pressing of the same release-group does not match (its
 * release id and barcode differ): mark that item by hand.
 */
final class PhysicalRipLink
{
    /**
     * @param  array<string, mixed>  $tags  as read by `AudioTags::read`
     * @return int items that were linked now (already ripped and linked ones are not counted)
     */
    public static function link(File $file, Album $album, array $tags): int
    {
        $release = isset($tags['release_mbid']) ? strtolower((string) $tags['release_mbid']) : null;
        $variants = PhysicalItems::barcodeVariants($tags['barcode'] ?? null);
        if (!$release && $variants === []) {
            return 0;
        }

        $items = PhysicalItem::query()->where('medium', 'cd')
            ->where(function ($q) use ($release, $variants) {
                if ($release) {
                    $q->orWhere('release_mbid', $release);
                }
                if ($variants !== []) {
                    $q->orWhereIn('barcode', $variants);
                }
            })->get();

        $linked = 0;
        foreach ($items as $item) {
            $previous = $item->album_id;
            if ($item->ripped_at !== null && $item->album_id === $album->id) {
                continue;
            }

            $item->ripped_at ??= now();
            $item->file_id ??= $file->id;
            $item->album_id = $album->id;
            $item->save();
            $linked++;

            // The album is owned now through the item, and the album it was tied to by name may not be any more.
            CatalogueLinker::refreshPhysical($item->release_group_mbid, $album);
            if ($previous !== null && $previous !== $album->id && ($old = Album::find($previous))) {
                CatalogueLinker::refreshOwnership($old);
            }
        }

        return $linked;
    }

    /** `link` for the indexer: a failure here must never stop a file from being indexed. */
    public static function linkQuietly(File $file, Album $album, array $tags): void
    {
        try {
            self::link($file, $album, $tags);
        } catch (\Throwable $e) {
            Log::warning("Linking file {$file->id} to the physical collection failed: {$e->getMessage()}");
        }
    }
}
