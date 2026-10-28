<?php

namespace App\Domain\Catalogue;

use App\Domain\Library\IdAliases;
use App\Models\Media\Album;
use App\Models\Media\AlbumAvailability;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use App\Models\Media\CatalogueArtist;

/**
 * What `library:merge-duplicates` (LibraryMerger) does to the catalogue, called inside its transactions and always
 * BEFORE the merged-away record is deleted (the foreign keys would otherwise null the link or cascade the rows).
 * See docs/architecture/availability-layer.md, "Interaction with stable IDs".
 */
final class CatalogueMerge
{
    /** The loser's release-group links and lookup answers move to the surviving album. */
    public static function album(Album $loser, Album $survivor): void
    {
        CatalogueAlbum::where('album_id', $loser->id)->update(['album_id' => $survivor->id]);

        foreach (AlbumAvailability::where('album_id', $loser->id)->get() as $answer) {
            $same = AlbumAvailability::where('album_id', $survivor->id)->where('provider', $answer->provider)->first();
            if ($same === null) {
                $answer->update(['album_id' => $survivor->id]);
            } elseif ($answer->checked_at > $same->checked_at) {
                $same->delete();
                $answer->update(['album_id' => $survivor->id]);
            } else {
                $answer->delete();
            }
        }
    }

    /** Called once the survivor's tracks are merged in: files that came with the loser may make the album owned. */
    public static function albumMerged(Album $survivor): void
    {
        CatalogueLinker::refreshOwnership($survivor);
    }

    /**
     * The loser artist's discography moves to the survivor. A release-group both have is one row: the survivor's
     * stays, takes the loser's wish/ignore/link, and the loser's id becomes an alias of it.
     */
    public static function artist(Artist $loser, Artist $survivor): void
    {
        foreach (CatalogueAlbum::where('artist_id', $loser->id)->get() as $row) {
            $keeper = CatalogueAlbum::where('artist_id', $survivor->id)->where('release_group_mbid', $row->release_group_mbid)->first();

            if ($keeper === null) {
                $row->update(['artist_id' => $survivor->id]);

                continue;
            }

            self::rows($row, $keeper);
        }

        CatalogueArtist::where('artist_id', $loser->id)->delete();
        // The survivor may have gained an mbid and albums by the merge: let the next sync look again.
        CatalogueArtist::where('artist_id', $survivor->id)->update(['next_sync_at' => now()]);
    }

    /** Folds the duplicate release-group row $from into $into and retires its id. */
    public static function rows(CatalogueAlbum $from, CatalogueAlbum $into): void
    {
        $earliest = fn ($a, $b) => $a === null ? $b : ($b === null ? $a : ($a < $b ? $a : $b));

        // A wish beats "I don't want this" beats neither (a wish is the stronger, deliberate act); the setter hook keeps them exclusive.
        $wanted = $earliest($into->wanted_at, $from->wanted_at);
        $ignored = $wanted === null ? $earliest($into->ignored_at, $from->ignored_at) : null;

        $into->forceFill([
            'wanted_at' => $wanted,
            'ignored_at' => $ignored,
            'album_id' => $into->album_id ?? $from->album_id,
            'linked_at' => $into->album_id !== null ? $into->linked_at : $from->linked_at,
            'link_method' => $into->album_id !== null ? $into->link_method : $from->link_method,
            'fulfilled_at' => $earliest($into->fulfilled_at, $from->fulfilled_at),
        ])->save();

        foreach (AlbumAvailability::where('catalogue_album_id', $from->id)->get() as $answer) {
            $same = AlbumAvailability::where('catalogue_album_id', $into->id)->where('provider', $answer->provider)->first();
            if ($same === null) {
                $answer->update(['catalogue_album_id' => $into->id]);
            } elseif ($answer->checked_at > $same->checked_at) {
                $same->delete();
                $answer->update(['catalogue_album_id' => $into->id]);
            } else {
                $answer->delete();
            }
        }

        $from->delete();
        app(IdAliases::class)->record('catalogue_album', $from->id, $into->id);
    }
}
