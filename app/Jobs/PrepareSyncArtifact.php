<?php

namespace App\Jobs;

use App\Domain\Sync\SyncArtifacts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Makes one sync artifact (a transcode or remux shared by every target, docs/architecture/offline-sync.md section 5) on the
 * `heavy` worker, one at a time, under `nice` (ExportTranscoder). One job per fingerprint.
 */
class PrepareSyncArtifact implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor;

    /** @param  array<string, mixed>  $spec */
    public function __construct(public string $fingerprint, public array $spec)
    {
        $this->onQueue((string) config('sync.queue'));
        $this->timeout = (int) config('sync.artifact_timeout') + 120;
        $this->uniqueFor = $this->timeout + 300;
    }

    public function uniqueId(): string
    {
        return $this->fingerprint;
    }

    public function handle(): void
    {
        SyncArtifacts::prepare($this->fingerprint, $this->spec);
    }
}
