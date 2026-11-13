<?php

namespace App\Domain\Stats;

use App\Models\Play;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** How often, and when last, library records were played: the `plays` and `last_played_at` of album, artist and track details. */
class PlayTotals
{
    /** @return array{plays: int, last_played_at: string|null} */
    public static function forAlbum(int $albumId): array
    {
        return self::row(Play::query()->join('tracks', 'tracks.id', '=', 'plays.track_id')->where('tracks.album_id', $albumId));
    }

    /** @return array{plays: int, last_played_at: string|null} */
    public static function forArtist(int $artistId): array
    {
        return self::row(Play::query()->join('tracks', 'tracks.id', '=', 'plays.track_id')->where('tracks.artist_id', $artistId));
    }

    /**
     * @param  iterable<int>  $trackIds
     * @return array<int, array{plays: int, last_played_at: string|null}> every id has an entry, zero plays when never played
     */
    public static function forTracks(iterable $trackIds): array
    {
        $ids = collect($trackIds)->unique()->values();
        $out = $ids->mapWithKeys(fn ($id) => [(int) $id => ['plays' => 0, 'last_played_at' => null]])->all();

        if ($ids->isEmpty()) {
            return $out;
        }

        DB::table('plays')->whereIn('track_id', $ids->all())->groupBy('track_id')
            ->selectRaw('track_id, COUNT(*) as plays, MAX(played_at) as last_played_at')->get()
            ->each(function ($r) use (&$out) {
                $out[(int) $r->track_id] = ['plays' => (int) $r->plays, 'last_played_at' => self::iso($r->last_played_at)];
            });

        return $out;
    }

    /** @return array{plays: int, last_played_at: string|null} */
    private static function row($query): array
    {
        $r = $query->toBase()->selectRaw('COUNT(plays.id) as plays, MAX(plays.played_at) as last_played_at')->first();

        return ['plays' => (int) ($r->plays ?? 0), 'last_played_at' => self::iso($r->last_played_at ?? null)];
    }

    private static function iso(?string $value): ?string
    {
        return $value !== null ? Carbon::parse($value)->toIso8601String() : null;
    }
}
