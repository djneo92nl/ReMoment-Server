<?php

namespace App\Jobs;

use App\Domain\Export\ExportRunner;
use App\Models\Export;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Writes one export (a library selection as store-mode zip parts) on the `heavy` queue, one at a time; see ExportRunner. */
class ExportLibrarySelection implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout;

    /** The unique lock outlives the longest run. */
    public int $uniqueFor;

    public function __construct(public readonly Export $export)
    {
        $this->timeout = (int) config('export.timeout');
        $this->uniqueFor = $this->timeout + 300;
        $this->onQueue(config('export.queue'));
    }

    public function uniqueId(): string
    {
        return $this->export->public_id;
    }

    public function handle(): void
    {
        if ($this->export->status !== Export::QUEUED) {
            return;
        }

        (new ExportRunner)->run($this->export);
    }

    /** A killed run (timeout, worker restart): mark it and remove the partial output. */
    public function failed(\Throwable $e): void
    {
        \Storage::disk(config('export.disk'))->deleteDirectory($this->export->directory());
        $this->export->forceFill(['status' => Export::FAILED, 'error' => $e->getMessage(), 'finished_at' => now(), 'part_files' => null])->save();
    }
}
