<?php

namespace App\Domain\Analysis;

use App\Domain\Similar\SectionEmbeddings;
use App\Domain\Tasks\UserTasks;
use App\Jobs\AnalyzeTrackDeep;
use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\Media\Track;
use App\Models\TrackFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Deep analysis of ONE track on request (workstream 16): per-track results that are too slow or too rare to compute
 * for the whole library, so they are admin-triggered, stored, and never part of the nightly run (`stem_audio` keeps the
 * stems as audio files for the apps' mixer, see StemAudio; `mood` is the mood curve of 16.3 and `section_sound` the
 * per-section embeddings of 16.5, both on the Essentia sidecar's ML build). Each KIND is backed by
 * an `OnDemandStage` that says where its result is stored, which service computes it and on which queue it runs.
 * See docs/api/deep-analysis.md.
 *
 * States per kind and file: `none`, `queued`, `running` (cache markers set by the endpoint and the job, with a TTL so a
 * dead worker never leaves one behind), `done` (stored, current file, current stage version), `failed` (last run at this
 * version raised, message kept).
 */
final class DeepAnalysis
{
    /** kind => the on-demand stage that computes it. */
    public const KINDS = ['sections' => 'structure', 'stems' => 'stems', 'stem_audio' => 'stem_audio', 'mood' => 'mood', 'section_sound' => 'section_sound'];

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

    /**
     * Which of these tracks have their stem audio kept (kind `stem_audio` done for the current content of the primary file):
     * one query for the whole list, for the mixer's picker. Does not check the files on the disk (`StemAudio::complete` does, when the stems are listed).
     *
     * @param  iterable<int, int>  $trackIds
     * @return array<int, true> track id => true, for the tracks that have them
     */
    public static function stemsKept(iterable $trackIds): array
    {
        $ids = collect($trackIds)->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        return TrackFile::query()
            ->whereIn('track_files.track_id', $ids)
            ->where('track_files.role', TrackFile::ORIGINAL)->where('track_files.is_primary', true)
            ->join('files', 'files.id', '=', 'track_files.file_id')
            ->join('file_analyses', 'file_analyses.file_id', '=', 'files.id')
            ->where('files.status', File::PRESENT)
            ->whereColumn('file_analyses.content_hash', 'files.hash')
            ->whereNotNull('file_analyses.stem_audio')
            ->pluck('track_files.track_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /** Whether this kind can run now: its service is configured and answers its health check (remembered for a minute). */
    public static function available(string $kind): bool
    {
        $stage = self::stage($kind);
        $url = $stage->serviceUrl();

        if (!$url || !$stage->enabled()) {
            return false;
        }

        return Cache::remember(self::healthKey($stage->key(), $url), 60, function () use ($url, $stage) {
            try {
                $response = Http::timeout(3)->get(rtrim($url, '/').'/health');

                // A service that reports its weights (the stems service: opt-in download) is available only once they are ready.
                $ready = $response->ok() && (bool) $response->json('ok') && ($response->json('weights') ?? StemWeights::READY) === StemWeights::READY;

                // A kind that needs one of the Essentia sidecar's ML models is offered only when that model is loaded.
                if ($ready && $stage instanceof NeedsSidecarModel) {
                    return in_array($stage->sidecarModel(), (array) $response->json('models'), true);
                }

                return $ready;
            } catch (\Throwable) {
                return false;
            }
        });
    }

    /** Forgets the remembered health of a stage's service (after its weights were asked for), so the kinds show up as soon as it is ok. */
    public static function forgetHealth(string $stageKey): void
    {
        $stage = AudioAnalysis::onDemandStages()[$stageKey] ?? null;
        $url = $stage?->serviceUrl();

        if ($url) {
            Cache::forget(self::healthKey($stageKey, $url));
        }
    }

    private static function healthKey(string $stageKey, string $url): string
    {
        return "deep:health:{$stageKey}:".md5($url);
    }

    /** The kinds that can run now: `features.deep_analysis_kinds` of GET /api/info. @return list<string> */
    public static function availableKinds(): array
    {
        // Deep analysis is a Full feature: no sidecar runs in the other profiles, so don't even probe for one.
        if (!\App\Domain\Profile\Profile::allows('deep_analysis')) {
            return [];
        }

        return array_values(array_filter(self::kinds(), self::available(...)));
    }

    /** True when any kind can run: `features.deep_analysis` of GET /api/info. */
    public static function anyAvailable(): bool
    {
        return self::availableKinds() !== [];
    }

    /** Seconds a kind is expected to take for this track, from its length: an estimate, measured on a fast machine. */
    public static function estimateSeconds(string $kind, ?int $trackSeconds): int
    {
        return self::stage($kind)->estimateSeconds($trackSeconds);
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
            $track = $file->trackFiles()->with('track')->first()?->track;
            UserTasks::put(self::taskKey($file, $kind), 'deep_analysis', ucfirst(str_replace('_', ' ', $kind)).': '.($track?->name ?? $file->path), 'queued', null, self::estimateSeconds($kind, $track?->duration));
            AnalyzeTrackDeep::dispatch($file, $kind);
        }

        return ['status' => 'queued', 'queued' => [...$inProgress, ...$toQueue]];
    }

    /** Removes the stored result of one kind; true when there was one. */
    public static function forget(File $file, string $kind): bool
    {
        $analysis = $file->analysis()->first();
        $stage = self::stage($kind);

        if ($kind === 'stem_audio') {
            StemAudio::delete($file); // the kept files go with their record
        }

        if ($kind === 'section_sound') {
            SectionEmbeddings::delete($file); // the vectors go with their manifest
        }

        // The sound of the sections belongs to these section boundaries: new sections need it computed again.
        if ($kind === 'sections' && $analysis !== null && ($analysis->stages['section_sound'] ?? null) !== null) {
            self::forget($file, 'section_sound');
            $analysis = $file->analysis()->first();
        }

        if ($analysis === null || ($analysis->stages[$stage->key()] ?? null) === null && ($analysis->errors[$stage->key()] ?? null) === null) {
            return false;
        }

        $analysis->forgetStage($stage->key());
        $analysis->{$stage->column()} = null;
        $analysis->save();

        return true;
    }

    /** Key of the task-tray entry for one kind of one file. */
    public static function taskKey(File $file, string $kind): string
    {
        return "deep:{$file->id}:{$kind}";
    }

    public static function marker(string $name, File $file, string $kind): string
    {
        return "deep:{$name}:{$file->id}:{$kind}";
    }
}
