<?php

namespace App\Console\Commands\Artwork;

use App\Domain\Artwork\ArtworkBackgrounds;
use App\Domain\Artwork\SdCardExport;
use App\Jobs\BuildSdCardExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

class ExportSdCardArtwork extends Command
{
    protected $signature = 'artwork:export-sd
        {--limit= : Number of most recently played albums (default config artwork.recent_albums)}
        {--size=1024x600 : Background size of the client screen (1024x600, 320x480 or 480x480)}
        {--queue : Queue the build instead of running it now}';

    protected $description = 'Build the SD card artwork zip (covers + backgrounds of recently played albums and source logos) for the touch client';

    public function handle(): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $size = (string) $this->option('size');

        if (!ArtworkBackgrounds::isSize($size)) {
            $this->error("Unknown size {$size}; use one of: ".implode(', ', array_keys(ArtworkBackgrounds::SIZES)).'.');

            return self::INVALID;
        }

        if ($this->option('queue')) {
            SdCardExport::markPending($size);
            BuildSdCardExport::dispatch($limit, $size);
            $this->info('Queued. Download it from /settings/clients once built.');

            return self::SUCCESS;
        }

        $meta = (new BuildSdCardExport($limit, $size))->handle();

        $this->info(sprintf(
            'Built %s (%s): %d albums, %d logos, %d skipped (not processed yet).',
            Storage::disk('local')->path(SdCardExport::zipPath($size)),
            Number::fileSize($meta['bytes']),
            $meta['albums'],
            $meta['logos'],
            $meta['skipped'],
        ));

        return self::SUCCESS;
    }
}
