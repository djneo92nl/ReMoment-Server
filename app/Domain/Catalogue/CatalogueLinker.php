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
use App\Models\Media\PhysicalItem;
use App\Models\Media\Track;
use Illuminate\Support\Facades\Log;

/**
 * Ties library albums to the MusicBrainz release-groups of their artist (catalogue_albums.album_id) and keeps
 * `fulfilled_at` (the linked album is owned, which closes a wish) right. Local queries only, never a network call.
 * Matching is conservative: an id match, else an equal name key, else an equal EditionKey (the library holds releases:
 * "One By One Special Norwegian Edition (82876 512002)" is an edition of the release-group "One By One"); an ambiguous
 * name (three albums called "Weezer") is linked only when one release year is clearly the closest, else not at all.
 * A release-group links to one album; the other editions of it stay unlinked (see docs "Edition matching").
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

        self::adoptPhysicalItems($album);

        if ($linked = CatalogueAlbum::where('album_id', $album->id)->first()) {
            self::syncOwnership($linked, $album);

            return $linked;
        }

        self::assign($album->artist_id, collect([$album]));

        return CatalogueAlbum::where('album_id', $album->id)->first();
    }

    /** How many of the artist's albums are linked after the run. */
    public static function linkArtist(Artist $artist): int
    {
        return self::relink($artist)['linked'];
    }

    /**
     * Links every unlinked album of the artist, best matches first (an exact name beats an edition match, so the order of
     * the albums never matters).
     *
     * @return array{linked: int, changed: int} albums linked after the run, links made or moved by it
     */
    public static function relink(Artist $artist): array
    {
        $pool = collect();
        $linked = 0;
        foreach ($artist->albums()->get() as $album) {
            self::adoptPhysicalItems($album);
            if ($row = CatalogueAlbum::where('album_id', $album->id)->first()) {
                self::syncOwnership($row, $album);
                $linked++;
            } else {
                $pool->push($album);
            }
        }

        $changed = self::assign($artist->id, $pool);

        return ['linked' => CatalogueAlbum::where('artist_id', $artist->id)->whereNotNull('album_id')->count(), 'changed' => $changed];
    }

    /**
     * An album that appears after its physical items (a CD catalogued before it was ripped or scanned) takes them over.
     * (Physical items match on the strict name key: they carry their own release-group id when catalogued by barcode.)
     */
    private static function adoptPhysicalItems(Album $album): void
    {
        PhysicalItem::whereNull('album_id')->where('title_key', $album->name_key ?: Normalizer::album($album->name))
            ->whereIn('artist_key', array_filter([$album->artist?->name_key ?: ($album->artist ? Normalizer::artist($album->artist->name) : null)]))
            ->update(['album_id' => $album->id]);
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

    /**
     * A physical item came or went: set or clear `fulfilled_at` on the release-group row it belongs to (by release-group id,
     * or through the library album), whether or not the library has that album.
     */
    public static function refreshPhysical(?string $releaseGroupMbid, ?Album $album): void
    {
        foreach (PhysicalItems::catalogueRows($releaseGroupMbid, $album) as $row) {
            $owned = PhysicalItem::where('release_group_mbid', $row->release_group_mbid)->exists()
                || ($row->album_id !== null && ($a = Album::find($row->album_id)) && self::isOwned($a));
            if ($owned && $row->fulfilled_at === null) {
                $row->forceFill(['fulfilled_at' => now()])->save();
            } elseif (!$owned && $row->fulfilled_at !== null) {
                $row->forceFill(['fulfilled_at' => null])->save();
            }
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
        if (PhysicalItem::where('album_id', $album->id)->exists()) {
            return true;
        }

        foreach (Providers::ownedLibraries() as $source) {
            if (LibrarySources::availableFrom(Track::query()->where('album_id', $album->id), $source)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Matches the unlinked albums to the artist's rows and stores the links.
     *
     * @param  \Illuminate\Support\Collection<int, Album>  $albums  unlinked albums of one artist
     * @return int links made or moved
     */
    private static function assign(int $artistId, \Illuminate\Support\Collection $albums): int
    {
        $all = CatalogueAlbum::where('artist_id', $artistId)->get();
        // Free rows, and rows held by an edition match, which a better match may take over.
        $pool = $all->filter(fn ($r) => $r->album_id === null || $r->link_method === 'edition');
        if ($pool->isEmpty()) {
            return 0;
        }
        $nameKeys = $all->pluck('name_key')->flip();
        $editionKeys = $pool->mapWithKeys(fn ($r) => [$r->id => EditionKey::of($r->title)]);

        $proposals = [];
        foreach ($albums as $album) {
            if ($p = self::best($album, $pool, $nameKeys, $editionKeys)) {
                $proposals[] = $p + ['album' => $album, 'owned' => self::isOwned($album)];
            }
        }
        usort($proposals, fn ($a, $b) => [$a['tier'], $a['gap'] ?? 99, $b['owned'] <=> $a['owned'], $a['album']->id]
            <=> [$b['tier'], $b['gap'] ?? 99, 0, $b['album']->id]);

        $changed = 0;
        $taken = [];
        $placed = [];
        foreach ($proposals as $p) {
            /** @var CatalogueAlbum $row */
            $row = $p['row'];
            $album = $p['album'];
            if (isset($taken[$row->id]) || isset($placed[$album->id])) {
                continue;
            }
            if ($row->album_id !== null) {
                $held = Album::find($row->album_id);
                $takeOver = $p['tier'] < 2 || ($p['owned'] && $held !== null && !self::isOwned($held));
                if (!$takeOver) {
                    continue;
                }
            }

            $taken[$row->id] = $placed[$album->id] = true;
            $row->forceFill(['album_id' => $album->id, 'linked_at' => now(), 'link_method' => $p['method']])->save();
            self::moveAnswers($row, $album);
            self::syncOwnership($row, $album);
            $changed++;
        }

        return $changed;
    }

    /**
     * The best row for one album: tier 0 release-group id, 1 equal name key, 2 equal EditionKey (with the sanity checks).
     * An ambiguous tier gives nothing and never falls through to a looser one.
     *
     * @return ?array{row: CatalogueAlbum, method: string, tier: int, gap: ?int}
     */
    private static function best(Album $album, \Illuminate\Support\Collection $pool, \Illuminate\Support\Collection $nameKeys, \Illuminate\Support\Collection $editionKeys): ?array
    {
        $groupId = Metadata::where('metadatable_type', $album->getMorphClass())->where('metadatable_id', $album->id)
            ->where('key', 'release_group_mbid')->value('value');
        if ($groupId && ($row = $pool->firstWhere('release_group_mbid', $groupId))) {
            return ['row' => $row, 'method' => 'mbid', 'tier' => 0, 'gap' => null];
        }

        $key = $album->name_key ?: Normalizer::album($album->name);
        $year = $album->released_at?->year;

        $exact = $pool->filter(fn ($r) => $r->name_key === $key)->values();
        if ($exact->isNotEmpty()) {
            return self::closest($exact, $year, 1, 'name', requireYear: $exact->count() > 1, tolerance: self::YEAR_TOLERANCE);
        }
        if ($nameKeys->has($key)) {
            return null; // the album's own name belongs to a row another album holds: never attach it to a looser one
        }

        $edition = EditionKey::of($album->name);
        $tracks = null;
        $loose = $pool->filter(function ($r) use ($edition, $editionKeys, $year, &$tracks, $album) {
            if ($editionKeys[$r->id] !== $edition) {
                return false;
            }
            $tracks ??= Track::where('album_id', $album->id)->count();

            return self::plausible($year, $tracks, $r->first_release_year, $r->type);
        })->values();
        if ($loose->isEmpty()) {
            return null;
        }

        return self::closest($loose, $year, 2, 'edition', requireYear: $loose->count() > 1, tolerance: self::editionYearTolerance());
    }

    /** One candidate wins; several only by a unique closest year within the tolerance. */
    private static function closest(\Illuminate\Support\Collection $rows, ?int $year, int $tier, string $method, bool $requireYear, int $tolerance): ?array
    {
        if ($rows->count() === 1) {
            $row = $rows->first();

            return ['row' => $row, 'method' => $method, 'tier' => $tier, 'gap' => $year !== null && $row->first_release_year !== null ? abs($row->first_release_year - $year) : null];
        }
        if ($year === null) {
            return null;
        }

        $gaps = $rows->filter(fn ($r) => $r->first_release_year !== null)
            ->map(fn ($r) => ['row' => $r, 'gap' => abs($r->first_release_year - $year)])->sortBy('gap')->values();
        $best = $gaps->first();
        if ($best === null || $best['gap'] > $tolerance || ($gaps->count() > 1 && $gaps[1]['gap'] === $best['gap'])) {
            return null;
        }

        return ['row' => $best['row'], 'method' => $method, 'tier' => $tier, 'gap' => $best['gap']];
    }

    private static function editionYearTolerance(): int
    {
        return (int) config('availability.edition_matching.year_tolerance', 2);
    }

    /**
     * Sanity checks of a loose (edition) match, with what the data has: the year of the pressing within the tolerance of
     * the release-group's first release (a reissue may be a little later), and the size of the release: a 1-3 track
     * release is a single, never an album-type group, and an EP-type group is not a release of more than 10 tracks.
     * A missing year is no evidence either way, but an album without tracks yet (the scan creates the album before its
     * tracks) is not linked loosely: the nightly catalogue:reconcile does it once the tracks are there.
     */
    public static function plausible(?int $albumYear, int $trackCount, ?int $groupYear, string $groupType): bool
    {
        if ($trackCount < 1) {
            return false;
        }
        if ($albumYear !== null && $groupYear !== null && abs($albumYear - $groupYear) > self::editionYearTolerance()) {
            return false;
        }
        if ($trackCount >= 1 && $trackCount <= 3 && $groupType === 'album') {
            return false;
        }

        return !($trackCount > 10 && $groupType === 'ep');
    }

    /**
     * Artist verification (a search candidate is taken only when one library album is one of its release-groups): equal
     * name key, or an equal EditionKey that passes the sanity checks. A compilation-only artist shares no title and stays refused.
     *
     * @param  list<array<string, mixed>>  $groups  as MusicBrainzClient::releaseGroups() returns them
     */
    public static function verifies(Artist $artist, array $groups): bool
    {
        $byKey = [];
        $byEdition = [];
        foreach ($groups as $g) {
            $year = isset($g['date']) && preg_match('/^\d{4}/', (string) $g['date']) ? (int) substr($g['date'], 0, 4) : null;
            $byKey[Normalizer::album($g['title'])] = true;
            $byEdition[EditionKey::of($g['title'])][] = [$year, $g['type'] ?? 'album'];
        }

        foreach ($artist->albums()->get() as $album) {
            if (isset($byKey[$album->name_key ?: Normalizer::album($album->name)])) {
                return true;
            }
            if (isset($byEdition[$key = EditionKey::of($album->name)])) {
                $tracks = Track::where('album_id', $album->id)->count();
                foreach ($byEdition[$key] as [$groupYear, $type]) {
                    if (self::plausible($album->released_at?->year, $tracks, $groupYear, $type)) {
                        return true;
                    }
                }
            }
        }

        return false;
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
