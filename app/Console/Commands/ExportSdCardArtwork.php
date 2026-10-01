<?php

namespace App\Console\Commands;

use App\Domain\Artwork\SdCardExport;
use App\Jobs\BuildSdCardExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

class ExportSdCardArtwork extends Command
{
    protected $signature = 'artwork:export-sd
        {--limit= : Number of most recently played albums (default config artwork.recent_albums)}
        {--queue : Queue the build instead of running it now}';

    protected $description = 'Build the SD card artwork zip (covers + backgrounds of recently played albums and source logos) for the touch client';

    public function handle(): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if ($this->option('queue')) {
            SdCardExport::markPending();
            BuildSdCardExport::dispatch($limit);
            $this->info('Queued. Download it from /settings/clients once built.');

            return self::SUCCESS;
        }

        $meta = (new BuildSdCardExport($limit))->handle();

        $this->info(sprintf(
            'Built %s (%s): %d albums, %d logos, %d skipped (not processed yet).',
            Storage::disk('local')->path(SdCardExport::ZIP_PATH),
            Number::fileSize($meta['bytes']),
            $meta['albums'],
            $meta['logos'],
            $meta['skipped'],
        ));

        return self::SUCCESS;
    }
}
