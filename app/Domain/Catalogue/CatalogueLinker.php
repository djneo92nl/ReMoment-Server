<?php

namespace App\Domain\Catalogue;

use App\Domain\Availability\Providers;
use App\Domain\Library\LibrarySources;
use App\Domain\Library\Normalizer;
use App\Models\Media\Album;
use App\Models\Media\AlbumAvailability;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use Illuminate\Support\Facades\Log;

/**
 * Ties library albums to the MusicBrainz release-groups of their artist (catalogue_albums.album_id) and keeps
 * `fulfilled_at` (the linked album is owned, which closes a wish) right. Local queries only, never a network call.
 * Matching is conservative: an id match, else an equal name key; an ambiguous name (three albums called "Weezer")
 * is linked only when one release year is clearly the closest, else not at all.
 */
final class CatalogueLinker
{
    /** A year gap beyond which a name-only match among several is not trusted. */
    private const YEAR_TOLERANCE = 2;

    /** Used from model events: a failure here must never break a scan or an import. */
    public static function linkQuietly(Album $album): void
    {
        try {
            self::link($album);
        } catch (\Throwable $e) {
            Log::warning("Catalogue link of album {$album->id} failed: {$e->getMessage()}");
        }
    }

    /** The release-group row now linked to the album, if any. */
    public static function link(Album $album): ?CatalogueAlbum
    {
        if (!$album->artist_id) {
            return null;
        }

        if ($linked = CatalogueAlbum::where('album_id', $album->id)->first()) {
            self::syncOwnership($linked, $album);

            return $linked;
        }

        [$match, $method] = self::candidate($album);
        if ($match === null) {
            return null;
        }

        $match->forceFill(['album_id' => $album->id, 'linked_at' => now(), 'link_method' => $method])->save();
        self::moveAnswers($match, $album);
        self::syncOwnership($match, $album);

        return $match;
    }

    public static function linkArtist(Artist $artist): int
    {
        $count = 0;
        foreach ($artist->albums()->get() as $album) {
            $count += self::link($album) ? 1 : 0;
        }

        return $count;
    }

    /** For scans and imports, per track: one indexed query when the album has no release-group, and never an error for the caller. */
    public static function refreshOwnershipQuietly(Album $album): void
    {
        try {
            self::refreshOwnership($album);
        } catch (\Throwable $e) {
            Log::warning("Catalogue ownership of album {$album->id} failed: {$e->getMessage()}");
        }
    }

    /** Sets or clears `fulfilled_at` of the rows linked to an album, e.g. after a scan or an import added or removed files. */
    public static function refreshOwnership(Album $album): void
    {
        foreach (CatalogueAlbum::where('album_id', $album->id)->get() as $row) {
            self::syncOwnership($row, $album);
        }
    }

    /** Whether the album has a track from an owned provider. */
    public static function isOwned(Album $album): bool
    {
        foreach (Providers::ownedLibraries() as $source) {
            if (LibrarySources::availableFrom(Track::query()->where('album_id', $album->id), $source)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: ?CatalogueAlbum, 1: ?string} */
    private static function candidate(Album $album): array
    {
        $free = fn () => CatalogueAlbum::where('artist_id', $album->artist_id)->whereNull('album_id');

        $groupId = Metadata::where('metadatable_type', $album->getMorphClass())->where('metadatable_id', $album->id)
            ->where('key', 'release_group_mbid')->value('value');
        if ($groupId && ($row = $free()->where('release_group_mbid', $groupId)->first())) {
            return [$row, 'mbid'];
        }

        $rows = $free()->where('name_key', $album->name_key ?: Normalizer::album($album->name))->get();
        if ($rows->count() === 1) {
            return [$rows->first(), 'name'];
        }
        if ($rows->isEmpty()) {
            return [null, null];
        }

        $year = $album->released_at?->year;
        if ($year === null) {
            return [null, null];
        }
        $gaps = $rows->filter(fn ($r) => $r->first_release_year !== null)
            ->map(fn ($r) => ['row' => $r, 'gap' => abs($r->first_release_year - $year)])->sortBy('gap')->values();
        $best = $gaps->first();
        if ($best === null || $best['gap'] > self::YEAR_TOLERANCE || ($gaps->count() > 1 && $gaps[1]['gap'] === $best['gap'])) {
            return [null, null];
        }

        return [$best['row'], 'name'];
    }

    /** Promotion: lookup answers recorded on the release-group now belong to the library album (the most specific entity). */
    private static function moveAnswers(CatalogueAlbum $row, Album $album): void
    {
        foreach (AlbumAvailability::where('catalogue_album_id', $row->id)->get() as $answer) {
            $same = AlbumAvailability::where('album_id', $album->id)->where('provider', $answer->provider)->first();
            if ($same === null) {
                $answer->update(['album_id' => $album->id, 'catalogue_album_id' => null]);
            } elseif ($answer->checked_at > $same->checked_at) {
                $same->delete();
                $answer->update(['album_id' => $album->id, 'catalogue_album_id' => null]);
            } else {
                $answer->delete();
            }
        }
    }

    private static function syncOwnership(CatalogueAlbum $row, Album $album): void
    {
        $owned = self::isOwned($album);
        if ($owned && $row->fulfilled_at === null) {
            $row->forceFill(['fulfilled_at' => now()])->save();
        } elseif (!$owned && $row->fulfilled_at !== null) {
            $row->forceFill(['fulfilled_at' => null])->save();
        }
    }
}
