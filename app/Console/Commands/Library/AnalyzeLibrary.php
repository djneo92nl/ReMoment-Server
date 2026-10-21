<?php

namespace App\Console\Commands\Library;

use App\Domain\Analysis\AudioAnalysis;
use App\Jobs\AnalyzeAudioFile;
use App\Models\File;
use Illuminate\Console\Command;

class AnalyzeLibrary extends Command
{
    protected $signature = 'library:analyze
        {--limit= : Maximum number of files to queue (default: what fits in analysis.night_hours)}
        {--stage= : Only this stage: loudness, waveform, quality, fingerprint, acoustid or essentia}
        {--file= : Analyse this file id (a stage that already ran at its current version is skipped)}
        {--now : Run in this process instead of queueing (for one file, to see errors)}
        {--dry-run : Only count what still needs analysis}';

    protected $description = 'Queue audio analysis (loudness, fingerprint, AcoustID, BPM/key/mood) for files that still need it';

    public function handle(): int
    {
        if (!AudioAnalysis::enabled()) {
            $this->warn('Audio analysis is off: set ANALYSIS_ENABLED=true (see docs/architecture/audio-analysis.md).');

            return self::SUCCESS;
        }

        $only = $this->option('stage') ?: null;
        if ($only !== null && !isset(AudioAnalysis::stages()[$only])) {
            $this->error('Unknown stage: '.$only);

            return self::FAILURE;
        }
        if (!AudioAnalysis::enabledStages($only)) {
            $this->warn('No analysis stage can run here (missing ffmpeg, fpcalc, an AcoustID key or the Essentia sidecar).');

            return self::SUCCESS;
        }

        if ($id = $this->option('file')) {
            return $this->analyzeFile((int) $id, $only);
        }

        if ($this->option('dry-run')) {
            $this->info(AudioAnalysis::pending(null, $only)->count().' file(s) still need analysis.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) ($this->option('limit') ?: AudioAnalysis::nightlyLimit()));
        $queued = AudioAnalysis::queueBacklog($limit, $only);

        $this->info($queued === 0 ? 'Nothing to analyse.' : "Queued analysis of {$queued} file(s) on the '".config('analysis.queue')."' queue; the rest follows on later runs.");

        return self::SUCCESS;
    }

    private function analyzeFile(int $id, ?string $only): int
    {
        $file = File::find($id);

        if ($file === null) {
            $this->error("No file {$id}.");

            return self::FAILURE;
        }

        if ($this->option('now')) {
            AnalyzeAudioFile::dispatchSync($file, $only);
            $analysis = $file->analysis()->first();
            $this->info('Done: '.json_encode(['stages' => $analysis?->stages, 'errors' => $analysis?->errors]));

            return empty($analysis?->errors) ? self::SUCCESS : self::FAILURE;
        }

        AnalyzeAudioFile::dispatch($file, $only);
        $this->info("Queued file {$id}.");

        return self::SUCCESS;
    }
}
