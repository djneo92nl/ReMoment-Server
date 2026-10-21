<?php

namespace App\Livewire;

use App\Domain\Analysis\AnalysisActivity;
use App\Domain\Analysis\AnalysisProgress;
use App\Domain\Analysis\AudioAnalysis;
use App\Domain\Health\QueueInspector;
use App\Jobs\AnalyzeAudioFile;
use App\Models\File;
use App\Models\FileAnalysis;
use Livewire\Component;

/** /settings/analysis: how far each analysis stage is, what runs now, what failed, and buttons to nudge it. */
class AnalysisStatus extends Component
{
    private const PROBLEM_ROWS = 200;

    /** Stage key to show problems for; empty for all. */
    public string $stage = '';

    public function runBacklog(): void
    {
        $queued = AudioAnalysis::queueBacklog((int) config('analysis.batch'));
        AnalysisProgress::forget();
        $this->toast($queued > 0 ? "Queued {$queued} files." : 'Nothing left to analyse.');
    }

    public function analyse(int $fileId): void
    {
        $file = File::find($fileId);

        if ($file && AudioAnalysis::enabled()) {
            AnalyzeAudioFile::dispatch($file);
            $this->toast("Queued {$file->path}.");
        }
    }

    /** Forgets a stage's error (and its attempts) and queues just that stage again. */
    public function retry(int $fileId, string $stage): void
    {
        $analysis = FileAnalysis::where('file_id', $fileId)->first();
        $file = File::find($fileId);

        if (!$analysis || !$file || !isset(AudioAnalysis::stages()[$stage])) {
            return;
        }

        $errors = $analysis->errors ?? [];
        unset($errors[$stage]);
        $analysis->errors = $errors;
        $analysis->save();

        if (AudioAnalysis::enabled()) {
            AnalyzeAudioFile::dispatch($file, $stage);
        }

        AnalysisProgress::forget();
        $this->toast("Queued {$stage} for {$file->path}.");
    }

    private function toast(string $message): void
    {
        session()->flash('analysis-success', $message);
    }

    /** @return list<array{file: File, stage: string, error: array}> */
    private function problems(): array
    {
        $rows = [];

        FileAnalysis::query()
            ->whereNotNull('errors')->where('errors', '!=', '[]')->where('errors', '!=', '{}')
            ->with('file')
            ->orderByDesc('analyzed_at')->orderByDesc('id')
            ->limit(self::PROBLEM_ROWS)
            ->get()
            ->each(function (FileAnalysis $analysis) use (&$rows) {
                foreach ($analysis->errors ?? [] as $stage => $error) {
                    if ($analysis->file && ($this->stage === '' || $this->stage === $stage)) {
                        $rows[] = ['file' => $analysis->file, 'stage' => $stage, 'error' => $error];
                    }
                }
            });

        return $rows;
    }

    public function render(QueueInspector $inspector)
    {
        $progress = AnalysisProgress::get();
        $perDay = AnalysisProgress::filesLastDay();
        $backlog = $progress['backlog'];
        $queue = collect($inspector->pending()['groups'] ?? [])->firstWhere('job', 'AnalyzeAudioFile');
        $current = AnalysisActivity::current();

        return view('livewire.analysis-status', [
            'progress' => $progress,
            'current' => $current,
            'currentFile' => $current ? File::find($current['file_id']) : null,
            'queued' => $queue['count'] ?? 0,
            'perDay' => $perDay,
            'nights' => $backlog > 0 && config('analysis.batch') > 0 ? (int) ceil($backlog / config('analysis.batch')) : 0,
            'sidecar' => AnalysisProgress::sidecar(),
            'durations' => collect(array_keys(AudioAnalysis::stages()))->prepend('decode')->mapWithKeys(fn ($s) => [$s => AnalysisActivity::averageSeconds($s)])->filter()->all(),
            'problems' => $this->problems(),
            'lastRun' => FileAnalysis::query()->max('analyzed_at'),
        ]);
    }
}
