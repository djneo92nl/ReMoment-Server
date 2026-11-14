<?php

namespace App\Domain\Stats;

use App\Domain\Profile\Profile;
use App\Models\Play;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Listening insights of the household (workstream 12.9, docs/api/insights.md): skip rate per artist, new against repeat
 * tracks per week, mood and tempo per part of the day, and what is played late. Read from `plays` joined with the
 * analysis of the own files; one aggregate query per block, results cached for a few minutes.
 *
 * Hours and dates are those stored in `plays.played_at`, i.e. the application timezone (`config('app.timezone')`).
 */
class ListeningInsights
{
    public const DEFAULT_DAYS = 90;

    public const MAX_DAYS = 365;

    public const CACHE_SECONDS = 300;

    /**
     * THE skip rule, in one place: a finished play (it has an `ended_at` after `played_at`) of a track with a known
     * length counts as skipped when it lasted less than this fraction of that length. Wall clock, so a pause makes a
     * skip look longer and the rate is a lower bound. Plays with no known length or no end are not measured at all.
     */
    public const SKIP_FRACTION = 0.5;

    /** Artists need this many measured plays before they get a skip rate (one skip must not read as 100 %). */
    public const SKIP_MIN_PLAYS = 3;

    /** "Late" is from this hour up to and including midnight, and from midnight until before LATE_UNTIL (22:00-04:59). */
    public const LATE_FROM = 22;

    public const LATE_UNTIL = 5;

    /** Parts of the day as [first hour, first hour of the next part]; together they cover 0-23. */
    public const PARTS = ['night' => [0, 5], 'morning' => [5, 12], 'afternoon' => [12, 18], 'evening' => [18, 24]];

    /** Tempo buckets as in the library overview: slow below 90 BPM, steady below 125, fast from there. */
    public const TEMPOS = ['slow' => [0, 90], 'steady' => [90, 125], 'fast' => [125, null]];

    /** Longest lists. */
    private const LIMIT = 10;

    /**
     * @param  list<string>  $hidden  sources hidden by scope/client, as for the stats
     */
    public static function forApi(int $days, array $hidden): array
    {
        $key = 'insights:'.$days.':'.Carbon::today()->format('Y-m-d').':'.md5(json_encode($hidden)).':'.(Profile::allows('analysis') ? 'a' : 's');

        return Cache::remember($key, self::CACHE_SECONDS, fn () => self::compute($days, $hidden));
    }

    public static function compute(int $days, array $hidden): array
    {
        $to = Carbon::today()->addDay();
        $from = $to->copy()->subDays($days);
        $base = fn () => ListeningStats::plays($from, $to, $hidden)->toBase();
        $analysed = Profile::allows('analysis');

        [$parts, $late] = $analysed ? self::soundByHour($base) : [self::emptyParts(), ['plays' => 0, 'moods' => []]];
        $lateTop = self::lateTop($base);

        return [
            'range' => ['from' => $from->format('Y-m-d'), 'to' => $to->copy()->subDay()->format('Y-m-d'), 'days' => $days],
            'timezone' => config('app.timezone'),
            'analysis' => $analysed,
            'skips' => self::skips($base),
            'weekly' => self::weekly($base, $from, $to),
            'parts_of_day' => $parts,
            'late' => [
                'hours' => ['from' => self::LATE_FROM, 'until' => self::LATE_UNTIL],
                'plays' => $lateTop['plays'],
                'artists' => $lateTop['artists'],
                'tracks' => $lateTop['tracks'],
                'moods' => $late['moods'],
            ],
        ];
    }

    private static function hourSql(): string
    {
        return DB::getDriverName() === 'sqlite' ? "CAST(strftime('%H', plays.played_at) AS INTEGER)" : 'HOUR(plays.played_at)';
    }

    private static function lateSql(): string
    {
        return '('.self::hourSql().' >= '.self::LATE_FROM.' OR '.self::hourSql().' < '.self::LATE_UNTIL.')';
    }

    /** @param \Closure(): Builder $base */
    private static function skips(\Closure $base): array
    {
        $length = 'COALESCE(NULLIF(plays.duration, 0), NULLIF(tracks.duration, 0))';
        $seconds = Play::secondsSql('plays.');
        $skipped = "CASE WHEN $seconds < ".self::SKIP_FRACTION." * $length THEN 1 ELSE 0 END";

        $rows = $base()
            ->join('tracks', 'tracks.id', '=', 'plays.track_id')
            ->join('artists', 'artists.id', '=', 'tracks.artist_id')
            ->whereNotNull('plays.ended_at')->whereRaw('plays.ended_at > plays.played_at')
            ->whereRaw("$length IS NOT NULL")
            ->selectRaw("artists.id, artists.name, COUNT(*) as plays, SUM($skipped) as skipped")
            ->groupBy('artists.id', 'artists.name')
            ->get();

        $measured = (int) $rows->sum('plays');
        $skippedTotal = (int) $rows->sum('skipped');

        $artists = $rows->filter(fn ($r) => $r->plays >= self::SKIP_MIN_PLAYS && $r->skipped > 0)
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'plays' => (int) $r->plays, 'skipped' => (int) $r->skipped, 'rate' => round($r->skipped / $r->plays, 3)])
            ->sort(fn ($a, $b) => [$b['rate'], $b['plays'], $a['id']] <=> [$a['rate'], $a['plays'], $b['id']])
            ->take(self::LIMIT)->values()->all();

        return [
            'rule' => ['fraction' => self::SKIP_FRACTION, 'min_plays' => self::SKIP_MIN_PLAYS],
            'plays' => $measured,
            'skipped' => $skippedTotal,
            'rate' => $measured > 0 ? round($skippedTotal / $measured, 3) : null,
            'artists' => $artists,
        ];
    }

    /**
     * A play is new when it is the first logged play of its track (ever, not only in the range), repeat otherwise.
     * Plays without a library track are not counted. Weeks start on Monday and are zero-filled; the first and last
     * may be partial.
     *
     * @param  \Closure(): Builder  $base
     */
    private static function weekly(\Closure $base, Carbon $from, Carbon $to): array
    {
        $new = 'CASE WHEN NOT EXISTS (SELECT 1 FROM plays earlier WHERE earlier.track_id = plays.track_id AND earlier.played_at < plays.played_at) THEN 1 ELSE 0 END';

        $weeks = [];
        for ($w = $from->copy()->startOfWeek(Carbon::MONDAY); $w < $to; $w->addWeek()) {
            $weeks[$w->format('Y-m-d')] = ['week' => $w->format('Y-m-d'), 'new' => 0, 'repeat' => 0];
        }

        $base()->whereNotNull('plays.track_id')
            ->selectRaw("DATE(plays.played_at) as day, COUNT(*) as n, SUM($new) as new_n")->groupBy('day')->get()
            ->each(function ($r) use (&$weeks) {
                $week = Carbon::parse($r->day)->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
                if (isset($weeks[$week])) {
                    $weeks[$week]['new'] += (int) $r->new_n;
                    $weeks[$week]['repeat'] += (int) $r->n - (int) $r->new_n;
                }
            });

        return array_values($weeks);
    }

    /** One query by hour and mood set; folds into the parts of the day and the late moods. */
    private static function soundByHour(\Closure $base): array
    {
        $rows = $base()
            ->leftJoin('track_files', fn ($j) => $j->on('track_files.track_id', '=', 'plays.track_id')->where('track_files.is_primary', true))
            ->leftJoin('file_analyses', 'file_analyses.file_id', '=', 'track_files.file_id')
            ->selectRaw(self::hourSql().' as h, file_analyses.mood_tags as mood_tags, COUNT(*) as n, COUNT(file_analyses.id) as analysed,'
                .' COUNT(file_analyses.bpm) as bpm_n, COALESCE(SUM(file_analyses.bpm), 0) as bpm_sum,'
                .' COUNT(file_analyses.arousal) as arousal_n, COALESCE(SUM(file_analyses.arousal), 0) as arousal_sum,'
                .' SUM(CASE WHEN file_analyses.bpm < 90 THEN 1 ELSE 0 END) as slow,'
                .' SUM(CASE WHEN file_analyses.bpm >= 90 AND file_analyses.bpm < 125 THEN 1 ELSE 0 END) as steady,'
                .' SUM(CASE WHEN file_analyses.bpm >= 125 THEN 1 ELSE 0 END) as fast')
            ->groupBy('h', 'mood_tags')->get();

        $parts = self::emptyParts();
        $acc = [];
        $lateMoods = [];
        $latePlays = 0;
        foreach ($rows as $r) {
            $h = (int) $r->h;
            $part = collect(self::PARTS)->filter(fn ($range) => $h >= $range[0] && $h < $range[1])->keys()->first();
            $a = &$acc[$part];
            $a ??= ['plays' => 0, 'analysed' => 0, 'bpm_n' => 0, 'bpm_sum' => 0.0, 'arousal_n' => 0, 'arousal_sum' => 0.0, 'tempos' => ['slow' => 0, 'steady' => 0, 'fast' => 0], 'moods' => []];
            $a['plays'] += (int) $r->n;
            $a['analysed'] += (int) $r->analysed;
            $a['bpm_n'] += (int) $r->bpm_n;
            $a['bpm_sum'] += (float) $r->bpm_sum;
            $a['arousal_n'] += (int) $r->arousal_n;
            $a['arousal_sum'] += (float) $r->arousal_sum;
            foreach (['slow', 'steady', 'fast'] as $t) {
                $a['tempos'][$t] += (int) $r->$t;
            }
            $moods = array_column(json_decode($r->mood_tags ?? '[]', true) ?: [], 'name');
            $isLate = $h >= self::LATE_FROM || $h < self::LATE_UNTIL;
            $latePlays += $isLate ? (int) $r->n : 0;
            foreach ($moods as $mood) {
                $a['moods'][$mood] = ($a['moods'][$mood] ?? 0) + (int) $r->n;
                if ($isLate) {
                    $lateMoods[$mood] = ($lateMoods[$mood] ?? 0) + (int) $r->n;
                }
            }
            unset($a);
        }

        foreach ($acc as $part => $a) {
            $parts[$part] = [
                'part' => $part,
                'hours' => ['from' => self::PARTS[$part][0], 'until' => self::PARTS[$part][1]],
                'plays' => $a['plays'],
                'analysed_plays' => $a['analysed'],
                'bpm' => $a['bpm_n'] > 0 ? round($a['bpm_sum'] / $a['bpm_n'], 1) : null,
                'arousal' => $a['arousal_n'] > 0 ? round($a['arousal_sum'] / $a['arousal_n'], 3) : null,
                'tempos' => $a['tempos'],
                'moods' => self::ranked($a['moods']),
            ];
        }

        return [array_values($parts), ['plays' => $latePlays, 'moods' => self::ranked($lateMoods)]];
    }

    private static function emptyParts(): array
    {
        $out = [];
        foreach (self::PARTS as $part => [$from, $until]) {
            $out[$part] = ['part' => $part, 'hours' => ['from' => $from, 'until' => $until], 'plays' => 0, 'analysed_plays' => 0, 'bpm' => null, 'arousal' => null, 'tempos' => ['slow' => 0, 'steady' => 0, 'fast' => 0], 'moods' => []];
        }

        return $out;
    }

    /** @param array<string, int> $counts */
    private static function ranked(array $counts): array
    {
        arsort($counts);

        return collect($counts)->map(fn ($n, $name) => ['name' => (string) $name, 'plays' => $n])
            ->sortBy([['plays', 'desc'], ['name', 'asc']])->take(self::LIMIT)->values()->all();
    }

    /**
     * Late plays by track in one query; the artists are the sum over their tracks.
     *
     * @param  \Closure(): Builder  $base
     */
    private static function lateTop(\Closure $base): array
    {
        $total = (int) $base()->whereRaw(self::lateSql())->count();

        $rows = $base()->whereRaw(self::lateSql())
            ->join('tracks', 'tracks.id', '=', 'plays.track_id')
            ->join('artists', 'artists.id', '=', 'tracks.artist_id')
            ->selectRaw('tracks.id as track_id, tracks.name as track, artists.id as artist_id, artists.name as artist, COUNT(*) as plays')
            ->groupBy('tracks.id', 'tracks.name', 'artists.id', 'artists.name')
            ->get();

        $tracks = $rows->sort(fn ($a, $b) => [$b->plays, $a->track_id] <=> [$a->plays, $b->track_id])->take(self::LIMIT)
            ->map(fn ($r) => ['id' => (int) $r->track_id, 'name' => $r->track, 'artist' => $r->artist, 'plays' => (int) $r->plays])->values()->all();

        $artists = $rows->groupBy('artist_id')
            ->map(fn ($g) => ['id' => (int) $g->first()->artist_id, 'name' => $g->first()->artist, 'plays' => (int) $g->sum('plays')])
            ->sort(fn ($a, $b) => [$b['plays'], $a['id']] <=> [$a['plays'], $b['id']])->take(self::LIMIT)->values()->all();

        return ['plays' => $total, 'artists' => $artists, 'tracks' => $tracks];
    }
}
