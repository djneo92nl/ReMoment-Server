<?php

namespace App\Domain\Stats;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Library\ApiItems;
use App\Domain\Library\LibrarySources;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Play;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Listening statistics of the household, per device and overall, read from `plays` (the JSON version of the /stats page).
 * Honest numbers: see docs/api/stats.md (what "skipped", "seconds" and a play mean).
 */
class ListeningStats
{
    public const MAX_DAYS = 731;

    /**
     * @return array{from: Carbon, to: Carbon, days: int} `from` is the start of the first day, `to` the start of the day after the last one
     */
    public static function range(?string $from, ?string $to): array
    {
        $last = $to !== null ? Carbon::parse($to)->startOfDay() : Carbon::today();
        $first = $from !== null ? Carbon::parse($from)->startOfDay() : $last->copy()->subDays(29);

        return ['from' => $first, 'to' => $last->copy()->addDay(), 'days' => (int) $first->diffInDays($last) + 1];
    }

    /**
     * Plays in the range. Plays of a library track outside the scope (`$hidden`) are left out; plays without a
     * library track (radio, unmatched text-only plays) always count.
     */
    public static function plays(Carbon $from, Carbon $to, array $hidden): Builder
    {
        return Play::query()
            ->where('plays.played_at', '>=', $from)->where('plays.played_at', '<', $to)
            ->where(fn ($q) => $q->whereNull('plays.track_id')
                ->orWhereIn('plays.track_id', LibrarySources::tracks(Track::query()->select('tracks.id'), $hidden)));
    }

    public static function compute(Carbon $from, Carbon $to, array $hidden, int $limit = 10): array
    {
        $base = fn () => self::plays($from, $to, $hidden)->toBase();
        $finished = 'CASE WHEN plays.ended_at IS NOT NULL AND plays.ended_at > plays.played_at THEN '.Play::secondsSql('plays.').' ELSE 0 END';
        $sqlite = DB::getDriverName() === 'sqlite';
        $hour = $sqlite ? "CAST(strftime('%H', plays.played_at) AS INTEGER)" : 'HOUR(plays.played_at)';
        $weekday = $sqlite ? "CAST(strftime('%w', plays.played_at) AS INTEGER)" : '(DAYOFWEEK(plays.played_at) - 1)';

        $totals = $base()->selectRaw("COUNT(*) as plays, COALESCE(SUM($finished), 0) as seconds, COALESCE(SUM(CASE WHEN plays.skipped THEN 1 ELSE 0 END), 0) as skipped, COUNT(DISTINCT plays.track_id) as tracks")->first();
        $artists = (int) $base()->join('tracks', 'tracks.id', '=', 'plays.track_id')->distinct()->count('tracks.artist_id');

        $byHour = array_fill(0, 24, 0);
        $base()->selectRaw("$hour as h, COUNT(*) as n")->groupBy('h')->get()->each(function ($r) use (&$byHour) {
            $byHour[(int) $r->h] = (int) $r->n;
        });

        $byWeekday = array_fill(0, 7, 0);
        $base()->selectRaw("$weekday as d, COUNT(*) as n")->groupBy('d')->get()->each(function ($r) use (&$byWeekday) {
            $byWeekday[(int) $r->d] = (int) $r->n;
        });

        $days = [];
        for ($d = $from->copy(); $d < $to; $d->addDay()) {
            $days[$d->format('Y-m-d')] = ['day' => $d->format('Y-m-d'), 'plays' => 0, 'seconds' => 0];
        }
        $base()->selectRaw("DATE(plays.played_at) as day, COUNT(*) as n, COALESCE(SUM($finished), 0) as seconds")->groupBy('day')->get()
            ->each(function ($r) use (&$days) {
                if (isset($days[$r->day])) {
                    $days[$r->day] = ['day' => $r->day, 'plays' => (int) $r->n, 'seconds' => (int) $r->seconds];
                }
            });

        $bySource = $base()->selectRaw("COALESCE(plays.source_type, 'unknown') as source_type, COUNT(*) as plays")
            ->groupBy('source_type')->orderByDesc('plays')->orderBy('source_type')->get()
            ->map(fn ($r) => ['source_type' => $r->source_type, 'plays' => (int) $r->plays])->all();

        $devices = [];
        $base()->selectRaw("plays.device_id, $hour as h, COUNT(*) as n, COALESCE(SUM($finished), 0) as seconds")->groupBy('plays.device_id', 'h')->get()
            ->each(function ($r) use (&$devices) {
                $d = &$devices[(int) $r->device_id];
                $d ??= ['device_id' => (int) $r->device_id, 'plays' => 0, 'seconds' => 0, 'by_hour' => array_fill(0, 24, 0)];
                $d['plays'] += (int) $r->n;
                $d['seconds'] += (int) $r->seconds;
                $d['by_hour'][(int) $r->h] = (int) $r->n;
            });
        $names = Device::whereIn('id', array_keys($devices))->pluck('device_name', 'id');
        $byDevice = collect($devices)->map(fn ($d) => ['device_id' => $d['device_id'], 'name' => $names[$d['device_id']] ?? null] + $d)
            ->sortByDesc('plays')->values()->all();

        return [
            'range' => ['from' => $from->format('Y-m-d'), 'to' => $to->copy()->subDay()->format('Y-m-d')],
            'totals' => [
                'plays' => (int) $totals->plays, 'seconds' => (int) $totals->seconds, 'skipped' => (int) $totals->skipped,
                'artists' => $artists, 'tracks' => (int) $totals->tracks,
            ],
            'by_hour' => $byHour,
            'by_weekday' => $byWeekday,
            'by_day' => array_values($days),
            'by_source' => $bySource,
            'by_device' => $byDevice,
            'top' => self::top($base, $limit),
        ];
    }

    /** @param \Closure(): \Illuminate\Database\Query\Builder $base */
    private static function top(\Closure $base, int $limit): array
    {
        $ranked = fn (string $column, bool $join) => ($join
            ? $base()->join('tracks', 'tracks.id', '=', 'plays.track_id')->whereNotNull($column)
            : $base()->whereNotNull($column))
            ->selectRaw("$column as id, COUNT(*) as plays")->groupBy($column)->orderByDesc('plays')->orderBy($column)->limit($limit)->get()
            ->pluck('plays', 'id');

        $trackPlays = $ranked('plays.track_id', false);
        $albumPlays = $ranked('tracks.album_id', true);
        $artistPlays = $ranked('tracks.artist_id', true);

        $tracks = Track::with(['artist', 'album'])->whereIn('id', $trackPlays->keys())->get()->keyBy('id');
        $albums = Album::with('artist')->whereIn('id', $albumPlays->keys())->get()->keyBy('id');
        $artists = Artist::with(['metadata' => fn ($q) => $q->whereIn('key', Artist::PHOTO_METADATA), 'albums'])
            ->withCount('albums')->whereIn('id', $artistPlays->keys())->get()->keyBy('id');

        LibraryItemArtwork::preload([
            ...LibraryItemArtwork::urlsOfAlbums($albums),
            ...LibraryItemArtwork::urlsOfAlbums($tracks->map->album->filter()),
            ...$artists->map->avatarUrl()->all(),
        ]);

        $build = fn ($plays, $models, \Closure $item) => $plays->map(fn ($n, $id) => isset($models[$id]) ? $item($models[$id]) + ['plays' => (int) $n] : null)->filter()->values()->all();

        return [
            'artists' => $build($artistPlays, $artists, fn (Artist $a) => ApiItems::artist($a)),
            'albums' => $build($albumPlays, $albums, fn (Album $a) => ApiItems::album($a)),
            'tracks' => $build($trackPlays, $tracks, fn (Track $t) => ApiItems::track($t)),
        ];
    }
}
