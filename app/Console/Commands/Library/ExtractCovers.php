<?php

namespace App\Console\Commands\Library;

use App\Domain\Storage\CoverExtractor;
use Illuminate\Console\Command;

class ExtractCovers extends Command
{
    protected $signature = 'library:extract-covers {--limit=50 : Maximum number of albums to read the files of}';

    protected $description = 'Give albums without an image the cover embedded in their own audio files (also done by every file scan)';

    public function handle(CoverExtractor $covers): int
    {
        ['found' => $found, 'checked' => $checked] = $covers->backfill(max(1, (int) $this->option('limit')));

        $this->info($checked === 0
            ? 'No albums without an image are left to check.'
            : "Checked {$checked} album(s), found a cover for {$found}. Covers are processed on the queue.");

        return self::SUCCESS;
    }
}
