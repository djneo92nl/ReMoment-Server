<?php

namespace App\Console\Commands\Radio;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Radio\RadioLogoFinder;
use App\Jobs\ProcessArtwork;
use App\Models\RadioStation;
use Illuminate\Console\Command;

class FixRadioArtwork extends Command
{
    protected $signature = 'radio:fix-artwork {--dry-run : Only list what would change}';

    protected $description = 'Find logos for stations without a usable one (radio-browser favicon, homepage icon) and process every station image that is not processed yet';

    public function handle(RadioLogoFinder $finder): int
    {
        $found = $missing = 0;

        foreach (RadioStation::with('meta')->orderBy('name')->get() as $station) {
            if (($fixed = RadioLogoFinder::normalize($station->image_url)) !== $station->image_url) {
                $this->option('dry-run') || $station->update(['image_url' => $fixed]);
            }

            if (!RadioLogoFinder::usable($station->image_url)) {
                $logo = $finder->find($station);
                $this->line(str_pad($station->name, 28).($logo ?? 'no logo found'));

                if ($logo === null) {
                    $missing++;

                    continue;
                }

                $found++;
                $this->option('dry-run') || $station->update(['image_url' => $logo]);
            }

            if (!$this->option('dry-run') && RadioLogoFinder::usable($station->image_url) && !ArtworkCache::has($station->image_url)) {
                try {
                    ProcessArtwork::dispatchSync($station->image_url);
                } catch (\Throwable $e) {
                    $this->warn("{$station->name}: ".mb_substr($e->getMessage(), 0, 100));
                }
            }
        }

        $this->info("Logos: {$found} found, {$missing} still without.");

        return self::SUCCESS;
    }
}
