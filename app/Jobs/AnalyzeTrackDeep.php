<?php

namespace App\Jobs;

use App\Domain\Analysis\AnalysisActivity;
use App\Domain\Analysis\DeepAnalysis;
use App\Domain\Storage\FileStore;
use App\Models\File;
use App\Models\FileAnalysis;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * One deep-analysis kind (`DeepAnalysis::KINDS`, now `sections`) for one file, on the `analysis` queue; asked for by the
 * admin for one track, never queued in bulk. The `queued`/`running` markers are cleared however it ends (the marker
 * TTLs cover a worker that dies); a failure is recorded as the stage's error so the API can show its message, and
 * asking again retries.
 */
class AnalyzeTrackDeep implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor = 3600;

    public function __construct(public readonly File $file, public readonly string $kind)
    {
        // The service's own timeout plus decoding the file; below the worker's --timeout and queue `retry_after`.
        $this->timeout = (int) config('analysis.structure_timeout') + 60;
        $this->onQueue(config('analysis.queue'));
    }

    public function uniqueId(): string
    {
        return $this->file->id.':'.$this->kind;
    }

    public function handle(): void
    {
        $file = $this->file->load('location');
        $running = DeepAnalysis::marker('running', $file, $this->kind);

        Cache::forget(DeepAnalysis::marker('queued', $file, $this->kind));

        if ($file->status !== File::PRESENT) {
            return;
        }

        $stage = DeepAnalysis::stage($this->kind);
        $analysis = FileAnalysis::firstOrNew(['file_id' => $file->id]);
        if ($analysis->content_hash !== $file->hash) {
            $analysis->resetFor($file->hash);
        }

        if ($analysis->stageVersion($stage->key()) === $stage->version()) {
            return;
        }

        Cache::put($running, true, $this->timeout + 60);
        AnalysisActivity::start($file, $stage->key());
        $startedAt = microtime(true);

        try {
            (new FileStore($file->location))->withLocalPath($file->path, function (string $path) use ($file, $analysis, $stage) {
                try {
                    $analysis->fill($stage->run($file, $path, $analysis));
                    $analysis->recordSuccess($stage->key(), $stage->version());
                } catch (\Throwable $e) {
                    $analysis->recordError($stage->key(), $stage->version(), $e->getMessage());
                }
            });

            $analysis->analyzed_at = now();
            $analysis->save();
        } finally {
            Cache::forget($running);
            AnalysisActivity::finish();
        }

        AnalysisActivity::finish($stage->key(), microtime(true) - $startedAt);
    }

    /** The worker killed the job (timeout) or something outside the stage threw. */
    public function failed(?\Throwable $e): void
    {
        $file = $this->file;
        $stage = DeepAnalysis::stage($this->kind);

        Cache::forget(DeepAnalysis::marker('queued', $file, $this->kind));
        Cache::forget(DeepAnalysis::marker('running', $file, $this->kind));

        $analysis = FileAnalysis::firstOrNew(['file_id' => $file->id]);
        if ($analysis->content_hash !== $file->hash) {
            $analysis->resetFor($file->hash);
        }
        $analysis->recordError($stage->key(), $stage->version(), $e?->getMessage() ?: 'The deep analysis was stopped.');
        $analysis->save();
    }
}
