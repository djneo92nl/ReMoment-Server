<?php

namespace App\Console\Commands\Library;

use App\Domain\Analysis\AudioAnalysis;
use App\Domain\Analysis\DeepAnalysis;
use App\Models\File;
use Illuminate\Console\Command;

/**
 * The opt-in nightly batch of the stems kind: queues the tracks that have no stems yet, newest files first, as many as
 * fit in `analysis.stems_night_hours` at the stage's own estimate, onto the single `stems` worker. The rest follows the
 * next night. Everything else about a stems run (queue, markers, errors) is the on-demand path of `DeepAnalysis`.
 */
class AnalyzeStems extends Command
{
    protected $signature = 'library:analyze-stems
        {--hours= : Hours of work to queue (default: analysis.stems_night_hours)}
        {--dry-run : Only say what would be queued}';

    protected $description = 'Queue stem analysis (vocals/drums/bass/other loudness lanes) for tracks that have none, within a time budget';

    public function handle(): int
    {
        if (!$this->option('dry-run') && !config('analysis.stems_nightly') && $this->option('hours') === null) {
            $this->warn('Nightly stems is off: set STEMS_NIGHTLY=true (see docs/architecture/stems-service.md).');

            return self::SUCCESS;
        }

        if (!DeepAnalysis::available('stems')) {
            $this->warn('The stems service is not available: set STEMS_URL and start it (docker compose --profile stems up -d).');

            return self::SUCCESS;
        }

        $budget = (float) ($this->option('hours') ?? config('analysis.stems_night_hours')) * 3600;
        $maxDuration = (int) config('analysis.stems_max_duration');
        $stage = DeepAnalysis::stage('stems');
        $dry = (bool) $this->option('dry-run');

        $queued = 0;
        $spent = 0;

        AudioAnalysis::inScope()
            ->with('analysis')
            ->orderByDesc('id')
            ->lazyById(500)
            ->each(function (File $file) use (&$queued, &$spent, $budget, $maxDuration, $stage, $dry) {
                $seconds = (int) ceil((float) $file->duration);

                // Over the service's limit it would only answer 413; unknown length is let through.
                if ($seconds > $maxDuration) {
                    return true;
                }

                $analysis = $file->analysis;
                $state = DeepAnalysis::state($file, $analysis, 'stems')['state'];

                if ($state !== 'none' && !($state === 'failed' && $analysis !== null && !$analysis->gaveUp($stage->key(), $stage->version()))) {
                    return true; // done, queued, running, or failed too often
                }

                $cost = $stage->estimateSeconds($seconds ?: null);
                if ($queued > 0 && $spent + $cost > $budget) {
                    return false;
                }

                if (!$dry) {
                    DeepAnalysis::request($file, ['stems']);
                }

                $queued++;
                $spent += $cost;

                return $spent < $budget;
            });

        $minutes = (int) round($spent / 60);
        $this->info($queued === 0
            ? 'No tracks need stems.'
            : ($dry ? 'Would queue' : 'Queued')." stems for {$queued} track(s), about {$minutes} min of work; the rest follows on later runs.");

        return self::SUCCESS;
    }
}
