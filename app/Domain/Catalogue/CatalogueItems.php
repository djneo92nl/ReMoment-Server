<?php

namespace App\Domain\Catalogue;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Library\LibrarySources;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\AlbumAvailability;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The `CatalogueItem` of the API (docs/api/availability.md): a release-group of an artist with its state
 * (`owned`, `streamable`, `known`), the providers that have it and, for a device, whether it can play them.
 * The state is derived, never stored: a library album's providers come from its tracks (the derived providers),
 * a lookup provider's answer from `album_availability`.
 */
final class CatalogueItems
{
    public const WISHLIST_PAGE_SIZE = 50;

    /** MusicBrainz release-group front cover, 500 px (an existing cover source of the album enrichment). */
    private const COVER_URL = 'https://coverartarchive.org/release-group/%s/front-500';

    /** @return list<array<string, mixed>> an artist's discography, newest first; ignored entries only with $ignored */
    public static function forArtist(Artist $artist, ?string $availability = null, bool $wanted = false, bool $ignored = false, ?Device $device = null): array
    {
        $rows = CatalogueAlbum::where('artist_id', $artist->id)
            ->when(!$ignored, fn ($q) => $q->whereNull('ignored_at'))
            ->when($wanted, fn ($q) => $q->whereNotNull('wanted_at'))
            ->orderByRaw('first_release_year is null')->orderByDesc('first_release_year')->orderBy('title')->orderBy('id')
            ->with('artist')->get();

        $items = self::present($rows, $device);

        return array_values(array_filter($items, fn (array $i) => match ($availability) {
            'owned', 'streamable' => $i['state'] === $availability,
            'missing' => $i['album_id'] === null,
            default => true,
        }));
    }

    /**
     * Wanted, not yet owned, most recently wished first: one row more than a page (to know there is a next one) and the items of the page.
     *
     * @return array{0: Collection<int, CatalogueAlbum>, 1: list<array<string, mixed>>}
     */
    public static function wishlist(int $offset, ?Device $device = null): array
    {
        $rows = CatalogueAlbum::whereNotNull('wanted_at')->whereNull('fulfilled_at')->whereNull('ignored_at')
            ->orderByDesc('wanted_at')->orderByDesc('id')->with('artist')
            ->offset($offset)->limit(self::WISHLIST_PAGE_SIZE + 1)->get();

        return [$rows, self::present($rows->take(self::WISHLIST_PAGE_SIZE), $device)];
    }

    /**
     * Release-groups of the library's artists released in the last $days days or still to come, that the library lacks, newest
     * first (the "New from your artists" shelf). Only full dates count: a release known only by its year cannot be placed.
     *
     * @return list<array<string, mixed>>
     */
    public static function newReleases(int $days = 90, ?Device $device = null, int $limit = 50): array
    {
        $rows = CatalogueAlbum::whereNull('album_id')->whereNull('ignored_at')
            ->whereRaw('length(first_release_date) = 10')->where('first_release_date', '>=', now()->subDays(max(1, $days))->toDateString())
            ->orderByDesc('first_release_date')->orderBy('id')->limit($limit)->with('artist')->get();

        return $rows->isEmpty() ? [] : self::present($rows, $device);
    }

    /** @return array<string, mixed> */
    public static function one(CatalogueAlbum $row, ?Device $device = null): array
    {
        return self::present(collect([$row->loadMissing('artist')]), $device)[0];
    }

    /**
     * Narrows an albums query to `owned` (an own-file or DLNA track) or `streamable` (not owned, but a streaming
     * library's track or an available lookup answer).
     */
    public static function scopeAlbums(Builder $query, string $availability): Builder
    {
        $owned = function (Builder $q) {
            foreach (CatalogueLinker::OWNED_SOURCES as $i => $source) {
                $q->{$i === 0 ? 'whereHas' : 'orWhereHas'}('tracks', fn ($t) => LibrarySources::availableFrom($t, $source));
            }
        };

        if ($availability === 'owned') {
            return $query->where($owned);
        }

        $streaming = array_diff(LibrarySources::libraryIds(), CatalogueLinker::OWNED_SOURCES);

        return $query->whereNot($owned)->where(function (Builder $q) use ($streaming) {
            foreach ($streaming as $source) {
                $q->orWhereHas('tracks', fn ($t) => LibrarySources::availableFrom($t, $source));
            }
            $q->orWhereExists(fn ($e) => $e->selectRaw('1')->from('album_availability')
                ->whereColumn('album_availability.album_id', 'albums.id')->where('album_availability.status', 'available'));
        });
    }

    /**
     * @param  Collection<int, CatalogueAlbum>  $rows  with `artist` loaded
     * @return list<array<string, mixed>>
     */
    public static function present(Collection $rows, ?Device $device): array
    {
        $albumIds = $rows->pluck('album_id')->filter()->all();
        $albums = Album::whereIn('id', $albumIds)
            ->with(['tracks.metadata' => fn ($q) => $q->whereIn('key', ['dlna_url', 'external_id'])])->get()->keyBy('id');

        $answers = AlbumAvailability::where('status', 'available')
            ->where(fn ($q) => $q->whereIn('album_id', $albumIds)->orWhereIn('catalogue_album_id', $rows->pluck('id')->all()))->get();

        $covers = $rows->mapWithKeys(fn (CatalogueAlbum $r) => [$r->id => $r->album_id && $albums->has($r->album_id) && $albums[$r->album_id]->images
            ? null : sprintf(self::COVER_URL, $r->release_group_mbid)]);
        LibraryItemArtwork::preload([...$covers->filter()->values(), ...LibraryItemArtwork::urlsOfAlbums($albums)]);

        return $rows->map(function (CatalogueAlbum $row) use ($albums, $answers, $covers, $device) {
            $album = $row->album_id ? $albums->get($row->album_id) : null;
            $providers = $album ? LibrarySources::ofTracks($album->tracks) : [];
            foreach ($answers as $answer) {
                if (($answer->album_id !== null && $answer->album_id === $row->album_id) || $answer->catalogue_album_id === $row->id) {
                    $providers[] = $answer->provider;
                }
            }
            $providers = array_values(array_unique($providers));

            $owned = (bool) array_intersect(array_map(fn ($p) => explode(':', $p, 2)[0], $providers), CatalogueLinker::OWNED_SOURCES);
            $state = $owned ? 'owned' : ($providers ? 'streamable' : 'known');

            return [
                'id' => $row->id,
                'release_group_mbid' => $row->release_group_mbid,
                'title' => $row->title,
                'type' => $row->type,
                'year' => $row->first_release_year,
                'release_date' => $row->first_release_date,
                'artist' => ['id' => $row->artist_id, 'name' => $row->artist?->name],
                'state' => $state,
                'album_id' => $row->album_id,
                'wanted' => $row->wanted_at !== null,
                'ignored' => $row->ignored_at !== null,
                'fulfilled' => $row->fulfilled_at !== null,
                'availability' => array_map(function (string $provider) use ($device) {
                    $library = LibrarySources::libraryOfSource($provider);
                    $entry = ['provider' => $provider, 'library' => $library, 'status' => 'available'];
                    if ($device !== null) {
                        $entry['playable'] = $library !== null && LibrarySources::playableOn($library, $device);
                    }

                    return $entry;
                }, $providers),
                'artwork' => $album?->images
                    ? LibraryItemArtwork::forImages($album->images)
                    : LibraryItemArtwork::forUrl($covers[$row->id]),
            ];
        })->all();
    }
}
