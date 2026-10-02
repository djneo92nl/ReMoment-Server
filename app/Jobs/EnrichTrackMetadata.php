<?php

namespace App\Jobs;

use App\Domain\Library\Enrichment;
use App\Models\Media\Track;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Kept so jobs queued before enrichment was split per source still run; new code calls
 * {@see Enrichment::queue()} directly.
 */
class EnrichTrackMetadata implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly Track $track) {}

    public function handle(): void
    {
        Enrichment::queue($this->track);
    }
}
