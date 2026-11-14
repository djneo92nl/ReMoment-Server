<?php

namespace App\Console\Commands\Artwork;

use App\Domain\Artwork\CoverFacts;
use App\Models\Media\Album;
use Illuminate\Console\Command;

class MeasureCovers extends Command
{
    protected $signature = 'artwork:measure-covers {--limit=200 : Most albums measured per run}';

    protected $description = 'Record the pixel size and hash of the cover of albums processed before the cover health report existed (an embedded cover is read from disk, any other downloaded)';

    public function handle(): int
    {
        $albums = Album::query()
            ->whereNotNull('images')->where('images', '!=', '[]')
            ->whereDoesntHave('metadata', fn ($m) => $m->where('key', 'cover_size')->where('source', CoverFacts::SOURCE))
            ->whereHas('tracks.trackFiles')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $done = 0;
        foreach ($albums as $album) {
            $done += CoverFacts::measure($album) ? 1 : 0;
        }

        $this->info("Measured {$done} of {$albums->count()} covers.");

        return self::SUCCESS;
    }
}
