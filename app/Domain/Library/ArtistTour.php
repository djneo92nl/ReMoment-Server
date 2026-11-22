<?php

namespace App\Domain\Library;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Models\Device;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Play;
use Illuminate\Support\Collection;

/**
 * An artist tour (workstream 4a, docs/api/artist-tour.md): a guided way through one artist's tracks in the library. It starts with
 * the songs you play most (the ones you know), then one song from each album, oldest album first, taking the song of that album you
 * have played least, and goes round the albums again until the tour is `discover.tour_length` long. Plays and release dates only,
 * so it needs no analysis and works in Standard.
 */
final class ArtistTour
{
    /** How many of the most played songs open the tour. */
    private const KNOWN = 3;

    /** The least tracks that make a tour. */
    private const MIN_TRACKS = 4;

    /**
     * The tour as `[track_id, reason]` in play order; with a `$device` only what it can play. Empty when the artist has fewer than
     * four tracks to play.
     *
     * @param  list<string>  $hidden
     * @return list<array{track_id: int, reason: string}>
     */
    public static function picks(Artist $artist, array $hidden = [], ?Device $device = null, ?int $length = null): array
    {
        $length = max(self::MIN_TRACKS, min(60, $length ?? (int) config('discover.tour_length', 15)));
        $plays = Play::query()->whereNotNull('plays.track_id')->groupBy('plays.track_id')->selectRaw('plays.track_id, COUNT(*) AS play_count');

        $query = LibrarySources::tracks(Track::query()->where('tracks.artist_id', $artist->id), $hidden)
            ->leftJoin('albums', 'albums.id', '=', 'tracks.album_id')
            ->leftJoinSub($plays, 'pt', 'pt.track_id', '=', 'tracks.id')
            ->select('tracks.id', 'tracks.album_id', 'albums.name as album_name', 'albums.released_at', 'pt.play_count');
        if ($device !== null) {
            LibraryPlayback::playableTracks($query, $device);
        }

        $rows = $query->get()->map(fn ($row) => [
            'id' => (int) $row->id,
            'album_id' => (int) $row->album_id,
            'album' => $row->album_name,
            'released' => $row->released_at ? substr((string) $row->released_at, 0, 10) : '9999-12-31',
            'plays' => (int) $row->play_count,
        ]);
        if ($rows->count() < self::MIN_TRACKS) {
            return [];
        }

        $picks = [];
        $known = $rows->where('plays', '>', 0)->sortBy([['plays', 'desc'], ['id', 'asc']])->take(self::KNOWN)->values();
        foreach ($known as $row) {
            $picks[] = ['track_id' => $row['id'], 'reason' => 'One of your most played by '.$artist->name.' ('.$row['plays'].' '.($row['plays'] === 1 ? 'play' : 'plays').')'];
        }

        // Per album, its least played songs first; the albums oldest first.
        $albums = $rows->reject(fn (array $row) => $known->contains('id', $row['id']))
            ->groupBy('album_id')
            ->map(fn (Collection $tracks) => $tracks->sortBy([['plays', 'asc'], ['id', 'asc']])->values())
            ->sortBy(fn (Collection $tracks) => [$tracks[0]['released'], $tracks[0]['album_id']])
            ->values();

        for ($round = 0; count($picks) < $length && $albums->contains(fn (Collection $tracks) => isset($tracks[$round])); $round++) {
            foreach ($albums as $tracks) {
                if (count($picks) >= $length) {
                    break;
                }
                if ($row = $tracks[$round] ?? null) {
                    $year = $row['released'] === '9999-12-31' ? '' : ' ('.substr($row['released'], 0, 4).')';
                    $picks[] = ['track_id' => $row['id'], 'reason' => ($row['plays'] === 0 ? 'Never played, from ' : 'A less played song from ').($row['album'] ?? 'a single').$year];
                }
            }
        }

        return $picks;
    }

    /** The tour as API items (the track and its reason). @param list<array{track_id: int, reason: string}> $picks @return list<array<string, mixed>> */
    public static function items(array $picks): array
    {
        $tracks = Track::query()->with(['artist', 'album'])->findMany(array_column($picks, 'track_id'))->keyBy('id');
        LibraryItemArtwork::preload(LibraryItemArtwork::urlsOfAlbums($tracks->map->album->filter()));

        $items = [];
        foreach ($picks as $i => $pick) {
            if ($track = $tracks->get($pick['track_id'])) {
                $items[] = ApiItems::track($track) + ['step' => $i + 1, 'reason' => $pick['reason']];
            }
        }

        return $items;
    }

    /** The tracks in play order, with what the player needs. @param list<array{track_id: int}> $picks @return Collection<int, Track> */
    public static function tracks(array $picks): Collection
    {
        return DailyMix::tracks($picks);
    }
}
