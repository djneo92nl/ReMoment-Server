<?php

namespace App\Domain\Library;

use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Artwork\PlaylistArtwork;
use App\Models\Media\Album;
use App\Models\Media\Playlist;
use App\Models\Play;
use App\Models\RadioStation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Recently played albums, playlists and radio stations in one list, newest first. The one implementation behind
 * `GET /api/library/recent-items` and the web library page (docs/api/library.md).
 *
 * Each kind is limited to `$limit` before merging, so the merged list is complete.
 */
class RecentItems
{
    /**
     * @param  list<string>  $hidden  sources hidden for the request (LibrarySources)
     * @return array{items: Collection<int, array{type: string, library: ?string, played_at: string, album: ?Album, playlist: ?Playlist, station: ?RadioStation}>, artwork: array<int, array{url: string, sources: array}>}
     *                                                                                                                                                                                                                      `artwork` is PlaylistArtwork::choose() of the listed playlists, keyed by playlist id.
     */
    public static function get(array $hidden, int $limit = 30): array
    {
        $items = collect();

        $albums = LibrarySources::albums(Album::query(), $hidden)
            ->joinSub(LibraryArtwork::lastPlayedPerAlbum(), 'recent', 'recent.album_id', '=', 'albums.id')
            ->with('artist')
            ->select('albums.*', 'recent.last_played_at as recent_played_at')
            ->orderByDesc('recent.last_played_at')
            ->orderByDesc('albums.id')
            ->limit($limit)
            ->get();
        $playedFrom = self::albumPlayedFrom($albums->pluck('id')->all());
        foreach ($albums as $album) {
            $played = $playedFrom[$album->id] ?? null;
            $items->push([
                'type' => 'album',
                'library' => $played !== null && !in_array($played, $hidden, true) ? $played : self::leadingLibrary($album->tracks(), $hidden),
                'played_at' => Carbon::parse($album->recent_played_at)->toIso8601String(),
                'album' => $album,
                'playlist' => null,
                'station' => null,
            ]);
        }

        $playlists = LibrarySources::playlists(Playlist::query()->whereNotNull('last_played_at'), $hidden)
            ->withCount(['tracks' => fn ($q) => LibrarySources::tracks($q, $hidden)])
            ->with(['metadata' => fn ($q) => $q->where('key', 'spotify_owner')])
            ->orderByDesc('last_played_at')->orderByDesc('id')
            ->limit($limit)
            ->get();
        $artwork = PlaylistArtwork::choose($playlists);
        foreach ($playlists as $playlist) {
            $library = LibraryPlayback::playlistLibrary($playlist);
            $items->push([
                'type' => 'playlist',
                'library' => $library !== null && !in_array($library, $hidden, true) ? $library : self::leadingLibrary($playlist->tracks(), $hidden),
                'played_at' => $playlist->last_played_at->toIso8601String(),
                'album' => null,
                'playlist' => $playlist,
                'station' => null,
            ]);
        }

        if (!in_array(LibrarySources::RADIO, $hidden, true)) {
            RadioStation::query()->with('meta')->whereNotNull('last_played_at')
                ->orderByDesc('last_played_at')->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->each(fn (RadioStation $station) => $items->push([
                    'type' => 'radio',
                    'library' => LibrarySources::RADIO,
                    'played_at' => $station->last_played_at->toIso8601String(),
                    'album' => null,
                    'playlist' => null,
                    'station' => $station,
                ]));
        }

        return ['items' => $items->sortByDesc('played_at')->take($limit)->values(), 'artwork' => $artwork];
    }

    /**
     * The library each album was last played from: the library of its latest played track
     * (`tracks.source`); albums whose latest plays were not of a library track are left out.
     *
     * @param  list<int>  $albumIds
     * @return array<int, string> album id => library id
     */
    private static function albumPlayedFrom(array $albumIds): array
    {
        if ($albumIds === []) {
            return [];
        }

        $plays = Play::query()
            ->join('tracks', 'tracks.id', '=', 'plays.track_id')
            ->whereIn('tracks.album_id', $albumIds)
            ->orderByDesc('plays.played_at')->orderByDesc('plays.id')
            ->get(['tracks.album_id', 'tracks.source']);

        $from = [];
        foreach ($plays as $play) {
            $from[$play->album_id] ??= LibrarySources::libraryOfSource($play->source);
        }

        return array_filter($from);
    }

    /** The leading library among those a track query is available from (and the client doesn't hide). */
    private static function leadingLibrary(Relation|Builder $tracks, array $hidden): ?string
    {
        $available = array_filter(
            array_diff(LibrarySources::libraryIds(), $hidden),
            fn (string $id) => LibrarySources::availableFrom(clone $tracks, $id)->exists(),
        );

        return LibrarySources::leadingOf(array_values($available));
    }
}
