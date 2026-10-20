<?php

namespace App\Jobs;

use App\Domain\Analysis\AudioAnalysis;
use App\Domain\Analysis\DecodedStage;
use App\Domain\Analysis\DecodePass;
use App\Domain\Storage\FileStore;
use App\Models\File;
use App\Models\FileAnalysis;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs the analysis stages a file still needs, on the `analysis` queue. The file is opened once (a download
 * for remote storage), the stages run one after another and each records its own success or error, so one
 * failing stage does not hold back the others; the backlog retries a failed stage on a later run.
 */
class AnalyzeAudioFile implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A file removed in the meantime is not an error. */
    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor = 3600;

    public function __construct(public readonly File $file, public readonly ?string $only = null)
    {
        $this->timeout = (int) config('analysis.timeout');
        $this->onQueue(config('analysis.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->file->id;
    }

    public function handle(): void
    {
        $file = $this->file->load('location');

        if ($file->status !== File::PRESENT || !AudioAnalysis::enabled()) {
            return;
        }

        $analysis = FileAnalysis::firstOrNew(['file_id' => $file->id]);
        if ($analysis->content_hash !== $file->hash) {
            $analysis->resetFor($file->hash);
        }

        $stages = AudioAnalysis::pendingStages($file, $analysis, AudioAnalysis::enabledStages($this->only));
        if (!$stages) {
            return;
        }

        (new FileStore($file->location))->withLocalPath($file->path, function (string $path) use ($file, $analysis, $stages) {
            $pass = $this->decode($file, $path, $stages);

            try {
                foreach ($stages as $stage) {
                    try {
                        $analysis->fill($pass && $stage instanceof DecodedStage
                            ? $stage->fromDecode($file, $path, $analysis, $pass)
                            : $stage->run($file, $path, $analysis));
                        $analysis->recordSuccess($stage->key(), $stage->version());
                    } catch (\Throwable $e) {
                        $analysis->recordError($stage->key(), $stage->version(), $e->getMessage());
                    }

                    $analysis->save();
                }
            } finally {
                $pass?->cleanup();
            }
        });

        AudioAnalysis::applyEffects($file, $analysis);
    }

    /**
     * One ffmpeg decode for every pending stage that works on the decoded audio. Null when fewer than two
     * need it, or when the shared run failed: the stages then run on their own, so each records its own error.
     *
     * @param  array<string, \App\Domain\Analysis\AnalysisStage>  $stages
     */
    private function decode(File $file, string $path, array $stages): ?DecodePass
    {
        $shared = array_values(array_filter($stages, fn ($stage) => $stage instanceof DecodedStage));

        if (count($shared) < 2) {
            return null;
        }

        try {
            return DecodePass::run($shared, $file, $path);
        } catch (\Throwable) {
            return null;
        }
    }
}
