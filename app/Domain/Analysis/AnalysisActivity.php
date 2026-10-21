<?php

namespace App\Domain\Analysis;

use App\Models\File;
use Illuminate\Support\Facades\Cache;

/**
 * What the analysis worker is doing right now and how long a stage usually takes, kept in the cache by
 * `AnalyzeAudioFile` for /settings/analysis. Best effort: a worker that dies leaves a stale entry, which the
 * TTL (one job timeout) and `current()` (ignores an entry older than that) clean up.
 */
class AnalysisActivity
{
    private const CURRENT = 'analysis:current';

    private const SAMPLES = 50;

    public static function start(File $file, string $stage): void
    {
        Cache::put(self::CURRENT, [
            'file_id' => $file->id, 'path' => $file->path, 'stage' => $stage, 'started_at' => now()->timestamp,
        ], (int) config('analysis.timeout') + 60);
    }

    /** Ends the running stage and remembers its duration, unless it was only cleaned up (`$seconds` null). */
    public static function finish(?string $stage = null, ?float $seconds = null): void
    {
        Cache::forget(self::CURRENT);

        if ($stage !== null && $seconds !== null) {
            $key = "analysis:durations:{$stage}";
            $samples = array_slice([...Cache::get($key, []), round($seconds, 2)], -self::SAMPLES);
            Cache::put($key, $samples, now()->addDays(30));
        }
    }

    /** @return array{file_id: int, path: string, stage: string, started_at: int}|null */
    public static function current(): ?array
    {
        $current = Cache::get(self::CURRENT);

        return $current && now()->timestamp - $current['started_at'] <= (int) config('analysis.timeout') ? $current : null;
    }

    /** Average seconds one stage took over its last runs, null while nothing was measured. */
    public static function averageSeconds(string $stage): ?float
    {
        $samples = Cache::get("analysis:durations:{$stage}", []);

        return $samples ? round(array_sum($samples) / count($samples), 1) : null;
    }

    /**
     * Seconds one file takes with every stage this install runs (the shared decode plus each stage, from the
     * measured averages); null until anything was measured.
     */
    public static function secondsPerFile(): ?float
    {
        $keys = ['decode', ...array_keys(AudioAnalysis::enabledStages())];
        $times = array_filter(array_map(self::averageSeconds(...), $keys));

        return $times ? round(array_sum($times), 1) : null;
    }
}
