<?php

namespace App\Domain\Library;

use App\Models\Device;
use App\Models\Media\Track;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The daily mix (workstream 4, docs/frontend/discover.md): one queue that brings back what you forgot and surfaces what you never
 * heard, the same all day and new tomorrow. A few songs you loved and have not played for a while, a few deep cuts (never played, on
 * albums you love) and one track from each of a few albums you own and never played, taken in turns so the three kinds alternate.
 * Plays and favorites only, so it needs no analysis and works in Standard. Counts are in `discover.daily_mix`.
 *
 * Both the `daily_mix` shelf of Discover and `POST /api/devices/{id}/library/play-daily-mix` use `picks()`.
 */
final class DailyMix
{
    public const KEY = 'daily_mix';

    /** Reason sentences by kind. */
    private const REASONS = [
        Rediscover::FORGOTTEN_TRACKS => 'A song you loved, not played in a while',
        Rediscover::DEEP_CUTS => 'A deep cut: never played, on an album you love',
        Rediscover::BURIED_TREASURE => 'From an album you own and never played',
    ];

    /**
     * The mix as `[track_id, reason]`, in play order. With a `$device` only what it can play. Empty when fewer than
     * `discover.daily_mix.min_tracks` tracks come out, so a library with nothing to bring back has no mix.
     *
     * @param  list<string>  $hidden
     * @return list<array{track_id: int, reason: string}>
     */
    public static function picks(array $hidden, ?CarbonInterface $now = null, ?Device $device = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $counts = (array) config('discover.daily_mix');

        $lists = [
            Rediscover::FORGOTTEN_TRACKS => self::trackIds(Rediscover::dailyShuffle(Rediscover::select(Rediscover::FORGOTTEN_TRACKS, $hidden, 40, $now), (int) ($counts['forgotten_tracks'] ?? 8), $now, 'mix')),
            Rediscover::DEEP_CUTS => self::trackIds(Rediscover::select(Rediscover::DEEP_CUTS, $hidden, (int) ($counts['deep_cuts'] ?? 6), $now)),
            Rediscover::BURIED_TREASURE => self::firstTracks(Rediscover::select(Rediscover::BURIED_TREASURE, $hidden, (int) ($counts['buried_treasure'] ?? 6), $now)),
        ];

        if ($device !== null) {
            $all = array_merge(...array_values($lists));
            $playable = LibraryPlayback::playableTracks(Track::query()->whereIn('tracks.id', $all), $device)->pluck('tracks.id')->map(fn ($id) => (int) $id)->all();
            $lists = array_map(fn (array $ids) => array_values(array_intersect($ids, $playable)), $lists);
        }

        $picks = [];
        $seen = [];
        for ($i = 0, $longest = max(array_map('count', $lists)); $i < $longest; $i++) {
            foreach ($lists as $kind => $ids) {
                if (isset($ids[$i]) && !isset($seen[$ids[$i]])) {
                    $seen[$ids[$i]] = true;
                    $picks[] = ['track_id' => $ids[$i], 'reason' => self::REASONS[$kind]];
                }
            }
        }

        return count($picks) >= (int) ($counts['min_tracks'] ?? 5) ? $picks : [];
    }

    /** The tracks of the mix, in order, with what the player needs. @return Collection<int, Track> */
    public static function tracks(array $picks): Collection
    {
        $tracks = Track::query()->with(['metadata', 'artist'])->findMany(array_column($picks, 'track_id'))->keyBy('id');

        return collect($picks)->map(fn (array $pick) => $tracks->get($pick['track_id']))->filter()->values();
    }

    /** @param  list<array{id: int}>  $rows @return list<int> */
    private static function trackIds(array $rows): array
    {
        return array_map(fn (array $row) => $row['id'], $rows);
    }

    /**
     * One track of each album, in album order: the one indexed first (track numbers are metadata, not a column).
     *
     * @param  list<array{id: int}>  $rows  albums
     * @return list<int>
     */
    private static function firstTracks(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $first = Track::query()->whereIn('album_id', array_column($rows, 'id'))->orderBy('id')->get(['id', 'album_id'])->unique('album_id')->pluck('id', 'album_id');

        return array_values(array_filter(array_map(fn (array $row) => $first[$row['id']] ?? null, $rows)));
    }
}
