<?php

namespace App\Domain\Analysis;

use App\Models\File;
use App\Models\FileAnalysis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * How far the audio analysis is: per stage how many files are done, waiting, failed or given up, using the
 * same rules as the backlog (`AudioAnalysis::pendingStages`), so the page and the nightly run never disagree.
 * One pass over the analysable files, cached for a minute.
 */
class AnalysisProgress
{
    private const CACHE_KEY = 'analysis:progress';

    /** What a stage needs to run, shown when it cannot. */
    private const REQUIREMENTS = [
        'loudness' => 'ffmpeg is not installed',
        'waveform' => 'ffmpeg is not installed',
        'quality' => 'ffmpeg is not installed',
        'fingerprint' => 'fpcalc (Chromaprint) is not installed',
        'acoustid' => 'needs ACOUSTID_API_KEY and fpcalc',
        'essentia' => 'needs ANALYSIS_ESSENTIA_URL and ffmpeg',
    ];

    public const DESCRIPTIONS = [
        'loudness' => 'Loudness (LUFS), loudness range, true peak; proves the file decodes',
        'waveform' => 'Waveform (RMS with contrast) for the progress bar',
        'quality' => 'Spectral cutoff, clipping, mono, phase, silence, bit depth',
        'fingerprint' => 'Chromaprint fingerprint of the first two minutes',
        'acoustid' => 'Looks the fingerprint up: MusicBrainz recording id',
        'essentia' => 'BPM, key, danceability, mood and genre (sidecar)',
    ];

    /**
     * @return array{
     *   files: int, complete: int, with_problems: int, backlog: int, enabled: bool,
     *   stages: array<string, array{key: string, description: string, version: string, enabled: bool, reason: ?string, done: int, pending: int, stale: int, failed: int, gave_up: int}>,
     *   computed_at: int
     * }
     */
    public static function get(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, fn () => self::compute());
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function compute(): array
    {
        $stages = AudioAnalysis::stages();
        $max = (int) config('analysis.max_attempts');
        $out = [];

        foreach ($stages as $key => $stage) {
            $enabled = $stage->enabled();
            $out[$key] = [
                'key' => $key, 'description' => self::DESCRIPTIONS[$key] ?? '', 'version' => $stage->version(),
                'enabled' => $enabled, 'reason' => $enabled ? null : (AudioAnalysis::enabled() ? self::REQUIREMENTS[$key] ?? null : 'analysis is off'),
                'done' => 0, 'pending' => 0, 'stale' => 0, 'failed' => 0, 'gave_up' => 0,
            ];
        }

        $files = $complete = $withProblems = $backlog = 0;

        AudioAnalysis::inScope()->with('analysis')->lazyById(500)->each(function (File $file) use ($stages, $max, &$out, &$files, &$complete, &$withProblems, &$backlog) {
            $files++;
            $analysis = $file->analysis;
            $current = $analysis !== null && $analysis->content_hash === $file->hash;
            $allDone = true;
            $problem = false;
            $waiting = false;

            foreach ($stages as $key => $stage) {
                $state = self::stageState($stage, $analysis, $current, $max);
                $out[$key][$state['state']]++;
                $out[$key]['stale'] += $state['stale'] ? 1 : 0;

                if ($out[$key]['enabled']) {
                    $allDone = $allDone && $state['state'] === 'done';
                    $problem = $problem || in_array($state['state'], ['failed', 'gave_up'], true);
                    $waiting = $waiting || in_array($state['state'], ['pending', 'failed'], true);
                }
            }

            $complete += $allDone ? 1 : 0;
            $withProblems += $problem ? 1 : 0;
            $backlog += $waiting ? 1 : 0;
        });

        return [
            'files' => $files, 'complete' => $complete, 'with_problems' => $withProblems, 'backlog' => $backlog,
            'enabled' => AudioAnalysis::enabled(), 'stages' => $out, 'computed_at' => now()->timestamp,
        ];
    }

    /**
     * One stage of one file: done, pending, failed (will be retried) or gave_up. `stale` marks a pending stage
     * that was done before (the file changed, or the stage's version moved on) and will run again.
     *
     * @return array{state: string, stale: bool}
     */
    public static function stageState(AnalysisStage $stage, ?FileAnalysis $analysis, bool $current, int $max): array
    {
        if ($analysis === null) {
            return ['state' => 'pending', 'stale' => false];
        }

        $everRan = !empty($analysis->stages[$stage->key()] ?? null);

        if (!$current) {
            return ['state' => 'pending', 'stale' => $everRan || !empty($analysis->stages)];
        }

        if ($analysis->stageVersion($stage->key()) === $stage->version()) {
            return ['state' => 'done', 'stale' => false];
        }

        $error = $analysis->errors[$stage->key()] ?? null;

        if ($error !== null && ($error['version'] ?? null) === $stage->version()) {
            return ['state' => ($error['attempts'] ?? 0) >= $max ? 'gave_up' : 'failed', 'stale' => false];
        }

        return ['state' => 'pending', 'stale' => $everRan];
    }

    /**
     * Where one file stands per stage, for the dots in the file list and the track panel. `$hash` is the file's
     * current hash; without it the analysis is taken as current.
     *
     * @return array<string, array{state: string, enabled: bool, version: ?string, at: ?string, message: ?string}>
     */
    public static function forFile(?FileAnalysis $analysis, ?string $hash = null): array
    {
        $current = $analysis !== null && ($hash === null || $analysis->content_hash === $hash);
        $max = (int) config('analysis.max_attempts');
        $out = [];

        foreach (AudioAnalysis::stages() as $key => $stage) {
            $out[$key] = [
                'state' => self::stageState($stage, $analysis, $current, $max)['state'],
                'enabled' => $stage->enabled(),
                'version' => $current ? $analysis->stageVersion($key) : null,
                'at' => $current ? ($analysis->stages[$key]['at'] ?? null) : null,
                'message' => $current ? ($analysis->errors[$key]['message'] ?? null) : null,
            ];
        }

        return $out;
    }

    /** Stages finished (successfully or not) in the last 24 hours, counted as files: the rate the backlog moves at. */
    public static function filesLastDay(): int
    {
        return FileAnalysis::query()->where('analyzed_at', '>=', now()->subDay())->count();
    }

    /**
     * The Essentia sidecar's own `/health`: models loaded and whether it runs the ML build. Null when no URL
     * is set; `ok` false when it did not answer.
     *
     * @return array{ok: bool, essentia: ?string, ml: bool, models: list<string>, error: ?string}|null
     */
    public static function sidecar(): ?array
    {
        $url = config('analysis.essentia_url');

        if (!AudioAnalysis::enabled() || !$url) {
            return null;
        }

        return Cache::remember('analysis:sidecar', 30, function () use ($url) {
            try {
                $response = Http::timeout(3)->get(rtrim($url, '/').'/health');
            } catch (\Throwable $e) {
                return ['ok' => false, 'essentia' => null, 'ml' => false, 'models' => [], 'error' => 'Not reachable'];
            }

            if (!$response->ok()) {
                return ['ok' => false, 'essentia' => null, 'ml' => false, 'models' => [], 'error' => "HTTP {$response->status()}"];
            }

            $models = $response->json('models');

            return [
                'ok' => true, 'essentia' => $response->json('essentia'), 'ml' => (bool) $response->json('ml'),
                'models' => is_array($models) ? array_values(array_map(fn ($m) => is_string($m) ? $m : (string) ($m['name'] ?? json_encode($m)), $models)) : [],
                'error' => null,
            ];
        });
    }
}
