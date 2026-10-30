<?php

namespace App\Domain\Analysis;

use App\Jobs\AnalyzeTrackDeep;
use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\Media\Track;
use App\Models\TrackFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Deep analysis of ONE track on request (workstream 16): per-track results that are too slow or too rare to compute
 * for the whole library, so they are admin-triggered, stored, and never part of the nightly run. Each KIND is backed by
 * an `OnDemandStage`; `mood` and `stems` get a row in KINDS when they exist. See docs/api/deep-analysis.md.
 *
 * States per kind and file: `none`, `queued`, `running` (cache markers set by the endpoint and the job, with a TTL so a
 * dead worker never leaves one behind), `done` (stored, current file, current stage version), `failed` (last run at this
 * version raised, message kept).
 */
final class DeepAnalysis
{
    /** kind => the on-demand stage that computes it. */
    public const KINDS = ['sections' => 'structure'];

    /** How long a `queued` marker lasts: the analysis queue can be busy with the nightly run for hours. */
    private const QUEUED_TTL = 43200;

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_keys(self::KINDS);
    }

    public static function stage(string $kind): OnDemandStage
    {
        return AudioAnalysis::onDemandStages()[self::KINDS[$kind]];
    }

    /** The present, primary original file of a track: the one a deep analysis reads. */
    public static function fileFor(Track $track): ?File
    {
        $link = $track->trackFiles()
            ->where('role', TrackFile::ORIGINAL)->where('is_primary', true)
            ->whereHas('file', fn ($q) => $q->where('status', File::PRESENT))
            ->with('file')->first();

        return $link?->file;
    }

    /** Whether this kind can run now: its service is configured and answers its health check (remembered for a minute). */
    public static function available(string $kind): bool
    {
        $url = config('analysis.structure_url');

        if ($kind !== 'sections' || !$url || !self::stage($kind)->enabled()) {
            return false;
        }

        return Cache::remember('deep:structure-health', 60, function () use ($url) {
            try {
                $response = Http::timeout(3)->get(rtrim($url, '/').'/health');

                return $response->ok() && (bool) $response->json('ok');
            } catch (\Throwable) {
                return false;
            }
        });
    }

    /** True when any kind can run: `features.deep_analysis` of GET /api/info. */
    public static function anyAvailable(): bool
    {
        return (bool) array_filter(self::kinds(), self::available(...));
    }

    /** Seconds a kind is expected to take for this track, from its length: an estimate, measured on a fast machine. */
    public static function estimateSeconds(string $kind, ?int $trackSeconds): int
    {
        return (int) ceil((float) config('analysis.structure_seconds_fixed') + (float) config('analysis.structure_seconds_per_second') * max(0, (int) $trackSeconds));
    }

    /** @return array{state: string, finished_at: ?string, version: ?string, message: ?string, stale: bool} */
    public static function state(File $file, ?FileAnalysis $analysis, string $kind): array
    {
        $stage = self::stage($kind);
        $key = $stage->key();
        $current = $analysis !== null && $analysis->content_hash === $file->hash;
        $out = ['state' => 'none', 'finished_at' => null, 'version' => null, 'message' => null, 'stale' => false];

        if ($current) {
            $out['version'] = $analysis->stageVersion($key);
            $out['finished_at'] = $analysis->stages[$key]['at'] ?? null;
            $out['stale'] = $out['version'] !== null && $out['version'] !== $stage->version();
            $error = $analysis->errors[$key] ?? null;
            if ($error !== null && ($error['version'] ?? null) === $stage->version()) {
                $out['message'] = $error['message'] ?? null;
            }
        }

        $done = $current && $out['version'] === $stage->version();

        $out['state'] = match (true) {
            Cache::has(self::marker('running', $file, $kind)) => 'running',
            Cache::has(self::marker('queued', $file, $kind)) => 'queued',
            $done => 'done',
            $out['message'] !== null => 'failed',
            default => 'none',
        };

        if ($out['state'] === 'failed') {
            $out['finished_at'] = $analysis->errors[$key]['at'] ?? null; // when it failed
        }

        return $out;
    }

    /**
     * Queues the kinds that are not stored (for the current file and stage version) nor already queued or running.
     *
     * @param  list<string>  $kinds
     * @return array{status: string, queued: list<string>} status `queued`, `already_done` or `not_available`
     */
    public static function request(File $file, array $kinds): array
    {
        $analysis = $file->analysis()->first();
        $toQueue = [];
        $inProgress = [];

        foreach (array_unique($kinds) as $kind) {
            $state = self::state($file, $analysis, $kind)['state'];

            if (in_array($state, ['queued', 'running'], true)) {
                $inProgress[] = $kind;
            } elseif ($state !== 'done') {
                $toQueue[] = $kind;
            }
        }

        if ($toQueue === [] && $inProgress === []) {
            return ['status' => 'already_done', 'queued' => []];
        }

        foreach ($toQueue as $kind) {
            if (!self::available($kind)) {
                return ['status' => 'not_available', 'queued' => []];
            }
        }

        foreach ($toQueue as $kind) {
            Cache::put(self::marker('queued', $file, $kind), true, self::QUEUED_TTL);
            AnalyzeTrackDeep::dispatch($file, $kind);
        }

        return ['status' => 'queued', 'queued' => [...$inProgress, ...$toQueue]];
    }

    /** Removes the stored result of one kind; true when there was one. */
    public static function forget(File $file, string $kind): bool
    {
        $analysis = $file->analysis()->first();
        $stage = self::stage($kind);

        if ($analysis === null || ($analysis->stages[$stage->key()] ?? null) === null && ($analysis->errors[$stage->key()] ?? null) === null) {
            return false;
        }

        $analysis->forgetStage($stage->key());
        if ($kind === 'sections') {
            $analysis->sections = null;
        }
        $analysis->save();

        return true;
    }

    public static function marker(string $name, File $file, string $kind): string
    {
        return "deep:{$name}:{$file->id}:{$kind}";
    }
}
