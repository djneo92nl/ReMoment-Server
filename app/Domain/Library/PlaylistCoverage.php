<?php

namespace App\Domain\Library;

use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How much of a playlist of another library (a Spotify playlist) exists in the Local (DLNA) library:
 * the share of its tracks with a playable local copy (a `dlna_url`). Playlists of the Local library
 * and local/smart playlists have no coverage. A playlist with at least MIN_COVERAGE is listed in the
 * Local scope as an "external" playlist and can be played in local mode (PlaylistMode).
 *
 * Counts come from one aggregate query for a whole page (counts()), the list filter from the same
 * query as a subquery (qualifyingIds()), never one query per playlist.
 */
final class PlaylistCoverage
{
    /** Minimum `local_coverage` (0..1, after rounding to 2 decimals) for a playlist of another library to be listed in the Local scope. */
    public const MIN_COVERAGE = 0.4;

    /** The share of local tracks as the API reports it: 0..1, 2 decimals; null without tracks. */
    public static function ratio(int $local, int $total): ?float
    {
        return $total > 0 ? round($local / $total, 2) : null;
    }

    /**
     * Track counts per playlist id: `total` playlist entries, `local` of them with a playable local copy.
     *
     * @param  iterable<int>  $playlistIds
     * @return array<int, array{total: int, local: int}>
     */
    public static function counts(iterable $playlistIds): array
    {
        $ids = collect($playlistIds)->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        return self::aggregate()->whereIn('pt.playlist_id', $ids)
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->playlist_id => ['total' => (int) $row->total, 'local' => (int) $row->local]])
            ->all();
    }

    /** The ids of the playlists (of another library) whose rounded coverage is at least MIN_COVERAGE, as a subquery. */
    public static function qualifyingIds(): \Illuminate\Database\Query\Builder
    {
        return self::aggregate()
            // The threshold is a literal: a bound float reaches SQLite as text, which compares greater than any number.
            ->havingRaw('ROUND(1.0 * count(distinct case when m.id is not null then pt.id end) / count(distinct pt.id), 2) >= '.sprintf('%.4F', self::MIN_COVERAGE))
            ->select('pt.playlist_id');
    }

    /**
     * Playlists that belong to a library other than Local (their `source`, or a Spotify playlist URI),
     * leaving out libraries in $hidden. Pass $not to get every other playlist instead.
     *
     * @param  list<string>  $hidden  libraries the client hides
     */
    public static function ownedByOtherLibrary(Builder $query, array $hidden = [], bool $not = false): Builder
    {
        $libraries = array_values(array_diff(LibrarySources::libraryIds(), [PlaylistMode::LOCAL_LIBRARY]));
        $shown = array_values(array_diff($libraries, $hidden));

        if ($not) {
            // Null-safe: a local playlist has no external_id.
            return $query
                ->where(fn (Builder $q) => $q->whereNull('playlists.source')->orWhereNotIn('playlists.source', $libraries))
                ->where(fn (Builder $q) => $q->whereNull('playlists.external_id')->orWhere('playlists.external_id', 'not like', 'spotify:playlist:%'));
        }

        return $query->where(function (Builder $q) use ($shown, $hidden) {
            $q->whereIn('playlists.source', $shown);
            if (!in_array('spotify', $hidden, true)) {
                $q->orWhere('playlists.external_id', 'like', 'spotify:playlist:%');
            }
        });
    }

    /** Playlist entries per playlist with how many have a playable local copy. */
    private static function aggregate(): \Illuminate\Database\Query\Builder
    {
        return DB::table('playlist_track as pt')
            ->leftJoin('metadata as m', function ($join) {
                $join->on('m.metadatable_id', '=', 'pt.track_id')
                    ->where('m.metadatable_type', (new Track)->getMorphClass())
                    ->where('m.key', 'dlna_url')
                    ->where('m.value', '!=', '');
            })
            ->groupBy('pt.playlist_id')
            ->selectRaw('pt.playlist_id, count(distinct pt.id) as total, count(distinct case when m.id is not null then pt.id end) as local');
    }

    /** Whether $playlist's own library is not Local, so it can have a coverage. */
    public static function applies(Playlist $playlist): bool
    {
        $library = LibraryPlayback::playlistLibrary($playlist);

        return $library !== null && $library !== PlaylistMode::LOCAL_LIBRARY;
    }
}
