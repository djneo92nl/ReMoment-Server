<?php

namespace App\Domain\Sync;

use App\Domain\Export\ExportSelection;
use App\Domain\Library\IdAliases;
use App\Domain\Library\SmartPlaylist\SmartPlaylistBuilder;
use App\Models\Export;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Models\TrackFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns one selection of a sync rule set into units of tracks, in rank order (docs/architecture/offline-sync.md section 2).
 * A unit is a group that is kept or dropped together (an album, for the album, artist and favorites types) or one
 * track (playlist, recently_added, most_played). Orders: album disc/track order, playlist order, newest added first,
 * most played first, most recently favorited first. Ids resolve through IdAliases; an id that no longer exists is
 * skipped with a warning, not an error.
 */
final class SyncSelection
{
    /** Smart playlists are re-selected at plan time when older than this many seconds. */
    private const SMART_FRESH = 3600;

    /**
     * @param  array<string, mixed>  $selection  a normalised selection of SyncRules
     * @param  list<array<string, mixed>>  $warnings  collected, passed by reference
     * @return list<array{whole: bool, tracks: Collection<int, Track>}>
     */
    public static function units(array $selection, array &$warnings): array
    {
        $with = ['artist', 'album.artist', 'metadata'];
        $aliases = app(IdAliases::class);

        switch ($selection['type']) {
            case 'album':
                $id = $aliases->resolve('album', $selection['id']);
                if (!Album::whereKey($id)->exists()) {
                    $warnings[] = ['code' => 'selection_not_found', 'type' => 'album', 'id' => $selection['id']];

                    return [];
                }

                return [['whole' => true, 'tracks' => ExportSelection::tracks(new Export(['selection_type' => 'album', 'selection_id' => $id]))]];

            case 'artist':
                $id = $aliases->resolve('artist', $selection['id']);
                if (!Artist::whereKey($id)->exists()) {
                    $warnings[] = ['code' => 'selection_not_found', 'type' => 'artist', 'id' => $selection['id']];

                    return [];
                }

                return self::albumUnits(ExportSelection::tracks(new Export(['selection_type' => 'artist', 'selection_id' => $id])));

            case 'playlist':
                $id = $aliases->resolve('playlist', $selection['id']);
                $playlist = Playlist::find($id);
                if (!$playlist) {
                    $warnings[] = ['code' => 'selection_not_found', 'type' => 'playlist', 'id' => $selection['id']];

                    return [];
                }
                if ($playlist->rules !== null && (!$playlist->refreshed_at || $playlist->refreshed_at->diffInSeconds(now()) > self::SMART_FRESH)) {
                    SmartPlaylistBuilder::refresh($playlist);
                }

                return $playlist->tracks()->with($with)->get()->map(fn (Track $t) => ['whole' => false, 'tracks' => collect([$t])])->all();

            case 'favorites':
                return self::favorites();

            case 'recently_added':
                $query = self::withOwnFile(Track::query())->with($with)->orderByDesc('tracks.created_at')->orderByDesc('tracks.id');
                if (isset($selection['days'])) {
                    $query->where('tracks.created_at', '>=', now()->subDays($selection['days']));
                }
                if (isset($selection['limit'])) {
                    $query->limit($selection['limit']);
                }

                return $query->get()->map(fn (Track $t) => ['whole' => false, 'tracks' => collect([$t])])->all();

            case 'most_played':
                $ids = DB::table('plays')->whereNotNull('track_id')
                    ->when(isset($selection['days']), fn ($q) => $q->where('played_at', '>=', now()->subDays($selection['days'])))
                    ->whereIn('track_id', self::withOwnFile(Track::query())->select('tracks.id'))
                    ->groupBy('track_id')->orderByRaw('count(*) desc')->orderBy('track_id')
                    ->limit($selection['limit'] ?? 200)->pluck('track_id')->all();
                $byId = Track::with($with)->whereIn('id', $ids)->get()->keyBy('id');

                return collect($ids)->map(fn ($id) => $byId->get($id))->filter()
                    ->map(fn (Track $t) => ['whole' => false, 'tracks' => collect([$t])])->values()->all();
        }

        return [];
    }

    /** Favourite albums and artists (an artist as its albums), newest favourite first. @return list<array{whole: bool, tracks: Collection<int, Track>}> */
    private static function favorites(): array
    {
        $items = Album::whereNotNull('favorited_at')->get(['id', 'favorited_at'])->map(fn ($a) => ['at' => $a->favorited_at, 'kind' => 'album', 'id' => $a->id])
            ->concat(Artist::whereNotNull('favorited_at')->get(['id', 'favorited_at'])->map(fn ($a) => ['at' => $a->favorited_at, 'kind' => 'artist', 'id' => $a->id]))
            ->sortByDesc(fn ($i) => $i['at']->getTimestamp())->values();

        $units = [];
        foreach ($items as $i) {
            $tracks = ExportSelection::tracks(new Export(['selection_type' => $i['kind'], 'selection_id' => $i['id']]));
            foreach ($i['kind'] === 'album' ? [['whole' => true, 'tracks' => $tracks]] : self::albumUnits($tracks) as $unit) {
                $units[] = $unit;
            }
        }

        return $units;
    }

    /** @param Collection<int, Track> $tracks in album order @return list<array{whole: bool, tracks: Collection<int, Track>}> */
    private static function albumUnits(Collection $tracks): array
    {
        return $tracks->groupBy(fn (Track $t) => $t->album_id ?? 'none-'.$t->id)
            ->map(fn (Collection $group) => ['whole' => true, 'tracks' => $group->values()])->values()->all();
    }

    private static function withOwnFile($query)
    {
        return $query->whereHas('trackFiles', fn ($q) => $q->where('role', TrackFile::ORIGINAL));
    }
}
