<?php

namespace App\Domain\Artwork;

use App\Models\Media\Album;
use App\Models\Media\Playlist;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The cover of a playlist, generated from its albums the way Spotify does:
 * a 2×2 mosaic of the covers of the 4 albums with the most tracks in the
 * playlist (ties: the album that comes first in the playlist). With 1–3
 * distinct covers it is simply the first album's cover; with none, the
 * playlist's own image (e.g. Spotify's), if any.
 *
 * The composite is used even when the playlist has its own image, so every
 * playlist looks the same way on the clients. It is addressed by a pseudo
 * URL "remoment:playlist/v1/{md5 of the 4 cover URLs}" and run through
 * ProcessArtwork like any cover (PlaylistCompositeRenderer draws it), so
 * the hash changes exactly when the chosen covers (or their order) change.
 * Bump VERSION when the layout changes.
 */
final class PlaylistArtwork
{
    public const VERSION = 1;

    /** `kind` of playlist items in the client pre-cache list. */
    public const KIND = 'playlist';

    /** Covers in a composite. */
    public const TILES = 4;

    public const FROM_COMPOSITE = 'composite';

    public const FROM_ALBUM = 'album';

    public const FROM_PLAYLIST = 'playlist';

    private const URL_PREFIX = 'remoment:playlist/';

    /** The pseudo URL of a composite of these covers, in tile order. */
    public static function compositeUrl(array $sources): string
    {
        return self::URL_PREFIX.'v'.self::VERSION.'/'.md5(implode("\n", $sources));
    }

    public static function isCompositeUrl(string $url): bool
    {
        return (bool) preg_match('#^'.preg_quote(self::URL_PREFIX, '#').'v\d+/[0-9a-f]{32}$#', $url);
    }

    /** Whether $sources are the covers $url was made from (the URL is their hash). */
    public static function matches(string $url, array $sources): bool
    {
        return count($sources) === self::TILES && self::compositeUrl(array_values($sources)) === $url;
    }

    /** The library API's small artwork object for one playlist (queued when unprocessed). */
    public static function itemFor(Playlist $playlist): ?array
    {
        $choice = self::choose(collect([$playlist]))[$playlist->id] ?? null;

        return $choice === null ? null : LibraryItemArtwork::forUrl($choice['url'], $choice['sources']);
    }

    /**
     * The artwork of every playlist with tracks, most recently played first,
     * one entry per URL (playlists can share a cover).
     *
     * @return Collection<int, array{url: string, sources: list<string>, from: string}>
     */
    public static function all(): Collection
    {
        return collect(self::choose(Playlist::query()->withTracksByRecency()->get(['id', 'images'])))
            ->filter()
            ->unique('url')
            ->values();
    }

    /**
     * The artwork of each playlist, in one query for all of them.
     *
     * @param  Collection<int, Playlist>  $playlists  (`id` and `images` are read)
     * @return array<int, array{url: string, sources: list<string>, from: string}|null> by playlist id
     */
    public static function choose(Collection $playlists): array
    {
        if ($playlists->isEmpty()) {
            return [];
        }

        $rows = DB::table('playlist_track')
            ->join('tracks', 'tracks.id', '=', 'playlist_track.track_id')
            ->whereIn('playlist_track.playlist_id', $playlists->pluck('id')->all())
            ->whereNotNull('tracks.album_id')
            ->groupBy('playlist_track.playlist_id', 'tracks.album_id')
            ->select('playlist_track.playlist_id', 'tracks.album_id')
            ->selectRaw('COUNT(*) as track_count, MIN(playlist_track.position) as first_position, MIN(playlist_track.id) as first_row')
            ->get()
            ->groupBy('playlist_id');

        $covers = Album::query()
            ->whereIn('id', $rows->flatten(1)->pluck('album_id')->unique()->all())
            ->whereNotNull('images')
            ->get(['id', 'images'])
            ->mapWithKeys(fn (Album $album) => [$album->id => LibraryArtwork::coverUrl($album->images)])
            ->filter();

        $choices = [];
        foreach ($playlists as $playlist) {
            $choices[$playlist->id] = self::chooseFrom($rows->get($playlist->id, collect()), $covers, $playlist);
        }

        return $choices;
    }

    /** @return array{url: string, sources: list<string>, from: string}|null */
    private static function chooseFrom(Collection $albums, Collection $covers, Playlist $playlist): ?array
    {
        $urls = $albums
            ->filter(fn ($row) => $covers->has($row->album_id))
            ->sort(fn ($a, $b) => [(int) $b->track_count, (int) $a->first_position, (int) $a->first_row]
                <=> [(int) $a->track_count, (int) $b->first_position, (int) $b->first_row])
            ->map(fn ($row) => $covers[$row->album_id])
            ->unique()
            ->values();

        if ($urls->count() >= self::TILES) {
            $sources = $urls->take(self::TILES)->all();

            return ['url' => self::compositeUrl($sources), 'sources' => $sources, 'from' => self::FROM_COMPOSITE];
        }

        if ($urls->isNotEmpty()) {
            return ['url' => $urls->first(), 'sources' => [], 'from' => self::FROM_ALBUM];
        }

        $own = LibraryArtwork::coverUrl($playlist->images);

        return $own === null ? null : ['url' => $own, 'sources' => [], 'from' => self::FROM_PLAYLIST];
    }
}
