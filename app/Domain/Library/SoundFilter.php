<?php

namespace App\Domain\Library;

use App\Domain\Analysis\Camelot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\Rule;

/**
 * Finds tracks by what audio analysis knows of the own files (docs/api/sound-query.md), with raw values: arousal,
 * valence and the rest are never normalised here. Only analysed tracks (an analysed primary file) can match, so a
 * track without analysis never does. One filter, used by `GET /library/tracks` and by `play-listening-mode` with a `filter`.
 */
final class SoundFilter
{
    public const KEYS = [
        'bpm_min', 'bpm_max', 'camelot', 'arousal_min', 'arousal_max', 'valence_min', 'valence_max', 'danceability_min',
        'instrumental_min', 'plr_min', 'mood', 'lossless_suspect_max', 'measured_bit_depth_min', 'clipping',
    ];

    /** The moods the Essentia classifiers name. */
    public const MOODS = ['happy', 'sad', 'aggressive', 'relaxed', 'party', 'acoustic', 'electronic'];

    /** Sort keys the tracks and albums lists add, with the SQL of the value (on `file_analyses`). */
    public const SORTS = ['plr' => '(file_analyses.true_peak - file_analyses.loudness_lufs)', 'bpm' => 'file_analyses.bpm', 'arousal' => 'file_analyses.arousal'];

    /** Validation rules for the filter keys, under an optional prefix (`filter.`). */
    public static function rules(string $prefix = ''): array
    {
        $number = ['nullable', 'numeric'];
        $fraction = ['nullable', 'numeric', 'between:0,1'];

        return [
            $prefix.'bpm_min' => $number, $prefix.'bpm_max' => $number,
            $prefix.'camelot' => ['nullable', fn ($attr, $value, $fail) => self::camelotList($value) !== null || $fail('The camelot value must be a comma list of codes such as 8A,9A.')],
            $prefix.'arousal_min' => $fraction, $prefix.'arousal_max' => $fraction,
            $prefix.'valence_min' => $fraction, $prefix.'valence_max' => $fraction,
            $prefix.'danceability_min' => $fraction, $prefix.'instrumental_min' => $fraction,
            $prefix.'plr_min' => $number,
            $prefix.'mood' => ['nullable', fn ($attr, $value, $fail) => self::moodList($value) !== null || $fail('The mood value must be a comma list of: '.implode(', ', self::MOODS).'.')],
            $prefix.'lossless_suspect_max' => $fraction,
            $prefix.'measured_bit_depth_min' => ['nullable', 'integer', 'min:1'],
            $prefix.'clipping' => ['nullable', Rule::in([0, 1, '0', '1', true, false])],
        ];
    }

    /** The criteria present in the input (known keys with a value), normalised; empty when none. */
    public static function criteria(array $input): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $value = $input[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $out[$key] = match ($key) {
                'camelot' => self::camelotList($value),
                'mood' => self::moodList($value),
                'clipping' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'measured_bit_depth_min' => (int) $value,
                default => (float) $value,
            };
        }

        return $out;
    }

    /** Restricts a track query to analysed tracks that meet every criterion. */
    public static function apply(Builder $tracks, array $criteria): Builder
    {
        return $tracks->whereExists(function (QueryBuilder $s) use ($criteria) {
            $s->selectRaw('1')->from('file_analyses')
                ->join('track_files', 'track_files.file_id', '=', 'file_analyses.file_id')
                ->whereColumn('track_files.track_id', 'tracks.id')
                ->where('track_files.is_primary', true);

            foreach ($criteria as $key => $value) {
                match ($key) {
                    'bpm_min' => $s->where('file_analyses.bpm', '>=', $value),
                    'bpm_max' => $s->where('file_analyses.bpm', '<=', $value),
                    'arousal_min' => $s->where('file_analyses.arousal', '>=', $value),
                    'arousal_max' => $s->where('file_analyses.arousal', '<=', $value),
                    'valence_min' => $s->where('file_analyses.valence', '>=', $value),
                    'valence_max' => $s->where('file_analyses.valence', '<=', $value),
                    'danceability_min' => $s->where('file_analyses.danceability', '>=', $value),
                    'instrumental_min' => $s->where('file_analyses.instrumental', '>=', $value),
                    'plr_min' => $s->whereNotNull('file_analyses.true_peak')->whereRaw('('.self::SORTS['plr'].' - ?) >= 0', [$value]),
                    'lossless_suspect_max' => $s->where('file_analyses.lossless_suspect', '<=', $value),
                    'measured_bit_depth_min' => $s->where('file_analyses.measured_bit_depth', '>=', $value),
                    'clipping' => $s->where('file_analyses.clipping_suspect', $value),
                    'camelot' => $s->where(function ($any) use ($value) {
                        foreach ($value as $code) {
                            $k = Camelot::parse($code);
                            $any->orWhere(fn ($w) => $w->where('file_analyses.key', $k['key'])->where('file_analyses.mode', $k['mode']));
                        }
                    }),
                    'mood' => $s->where(function ($any) use ($value) {
                        foreach ($value as $mood) {
                            $any->orWhere('file_analyses.mood_tags', 'like', '%"name":"'.$mood.'"%');
                        }
                    }),
                    default => null,
                };
            }
        });
    }

    /** Orders tracks by an analysis value (highest first, unanalysed or unknown last); a key of SORTS. */
    public static function orderTracks(Builder $tracks, string $sort): Builder
    {
        $value = '(select '.self::SORTS[$sort].' from file_analyses join track_files on track_files.file_id = file_analyses.file_id'
            .' where track_files.track_id = tracks.id and track_files.is_primary = 1 limit 1)';

        return $tracks->orderByRaw("$value IS NULL")->orderByRaw("$value DESC")->orderBy('tracks.id');
    }

    /** Orders albums by the mean of that value over their analysed tracks (highest first, unanalysed last). */
    public static function orderAlbums(Builder $albums, string $sort): Builder
    {
        $mean = '(select avg('.self::SORTS[$sort].') from tracks join track_files on track_files.track_id = tracks.id and track_files.is_primary = 1'
            .' join file_analyses on file_analyses.file_id = track_files.file_id where tracks.album_id = albums.id)';

        return $albums->orderByRaw("$mean IS NULL")->orderByRaw("$mean DESC")->orderBy('albums.id');
    }

    /** @return list<string>|null the codes of a comma list, null when empty or one is not a Camelot code */
    private static function camelotList(mixed $value): ?array
    {
        $codes = array_values(array_filter(array_map(fn ($c) => strtoupper(trim($c)), explode(',', is_array($value) ? implode(',', $value) : (string) $value)), fn ($c) => $c !== ''));

        foreach ($codes as $code) {
            if (Camelot::parse($code) === null) {
                return null;
            }
        }

        return $codes === [] ? null : $codes;
    }

    /** @return list<string>|null */
    private static function moodList(mixed $value): ?array
    {
        $moods = array_values(array_filter(array_map(fn ($m) => strtolower(trim($m)), explode(',', is_array($value) ? implode(',', $value) : (string) $value)), fn ($m) => $m !== ''));

        return $moods !== [] && array_diff($moods, self::MOODS) === [] ? $moods : null;
    }
}
