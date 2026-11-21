<?php

namespace App\Domain\Analysis;

use App\Domain\Health\QueueInspector;
use App\Domain\Tasks\UserTasks;
use App\Jobs\AnalyzeAudioFile;
use App\Models\File;
use App\Models\FileAnalysis;

/**
 * What /settings/analysis shows and does, for the web page and `/api/admin/analysis` alike: how far each stage is,
 * what runs now, the failed stages, and the actions that nudge it (queue a batch, analyse one file, retry a stage).
 */
class AnalysisAdmin
{
    public const PROBLEM_ROWS = 200;

    /** Queues the nightly batch size now. @return int files queued */
    public function runBacklog(): int
    {
        $queued = AudioAnalysis::queueBacklog(AudioAnalysis::nightlyLimit());
        AnalysisProgress::forget();

        if ($queued > 0) {
            UserTasks::put(AnalyzeAudioFile::BACKLOG_TASK, 'analysis', 'Audio analysis', 'queued', $queued);
        }

        return $queued;
    }

    /** Queues all stages of one file; false when analysis is off. */
    public function analyse(File $file): bool
    {
        if (!AudioAnalysis::enabled()) {
            return false;
        }

        AnalyzeAudioFile::dispatch($file);

        return true;
    }

    /**
     * Forgets a stage's error (and its attempts) and queues just that stage again.
     *
     * @return bool false when the file has no analysis row or the stage is unknown
     */
    public function retry(File $file, string $stage): bool
    {
        $analysis = FileAnalysis::where('file_id', $file->id)->first();

        if (!$analysis || !isset(AudioAnalysis::stages()[$stage])) {
            return false;
        }

        $errors = $analysis->errors ?? [];
        unset($errors[$stage]);
        $analysis->errors = $errors;
        $analysis->save();

        if (AudioAnalysis::enabled()) {
            AnalyzeAudioFile::dispatch($file, $stage);
        }

        AnalysisProgress::forget();

        return true;
    }

    /** @return list<array{file: File, stage: string, error: array}> the newest failed stages, optionally of one stage */
    public function problems(string $stage = ''): array
    {
        $rows = [];

        FileAnalysis::query()
            ->whereNotNull('errors')->where('errors', '!=', '[]')->where('errors', '!=', '{}')
            ->with('file')
            ->orderByDesc('analyzed_at')->orderByDesc('id')
            ->limit(self::PROBLEM_ROWS)
            ->get()
            ->each(function (FileAnalysis $analysis) use (&$rows, $stage) {
                foreach ($analysis->errors ?? [] as $key => $error) {
                    if ($analysis->file && ($stage === '' || $stage === $key)) {
                        $rows[] = ['file' => $analysis->file, 'stage' => $key, 'error' => $error];
                    }
                }
            });

        return $rows;
    }

    /** The figures of the summary and schedule cards. */
    public function summary(QueueInspector $inspector): array
    {
        $progress = AnalysisProgress::get();
        $backlog = $progress['backlog'];
        $queue = collect($inspector->pending()['groups'] ?? [])->firstWhere('job', 'AnalyzeAudioFile');
        $current = AnalysisActivity::current();
        $perFile = AnalysisActivity::secondsPerFile();
        $nightFiles = AudioAnalysis::nightlyLimit();

        return [
            'progress' => $progress,
            'current' => $current,
            'currentFile' => $current ? File::find($current['file_id']) : null,
            'queued' => $queue['count'] ?? 0,
            'perDay' => AnalysisProgress::filesLastDay(),
            'perFile' => $perFile,
            'nightFiles' => $nightFiles,
            'nights' => $backlog > 0 ? (int) ceil($backlog / $nightFiles) : 0,
            'backlogHours' => $perFile ? round($backlog * $perFile / 3600, 1) : null,
            'sidecar' => AnalysisProgress::sidecar(),
            'durations' => collect(array_keys(AudioAnalysis::stages()))->prepend('decode')->mapWithKeys(fn ($s) => [$s => AnalysisActivity::averageSeconds($s)])->filter()->all(),
            'lastRun' => FileAnalysis::query()->max('analyzed_at'),
        ];
    }

    /** The JSON of `GET /api/admin/analysis`. */
    public function toApi(QueueInspector $inspector): array
    {
        $s = $this->summary($inspector);
        $p = $s['progress'];

        return [
            'enabled' => $p['enabled'],
            'files' => $p['files'],
            'complete' => $p['complete'],
            'backlog' => $p['backlog'],
            'with_problems' => $p['with_problems'],
            'queued_jobs' => $s['queued'],
            'files_last_24h' => $s['perDay'],
            'last_activity_at' => $s['lastRun'] ? \Illuminate\Support\Carbon::parse($s['lastRun'])->toIso8601String() : null,
            'schedule' => [
                'night_files' => $s['nightFiles'],
                'night_hours' => (float) config('analysis.night_hours'),
                'seconds_per_file' => $s['perFile'],
                'nights_to_clear_backlog' => $s['nights'],
                'backlog_hours' => $s['backlogHours'],
            ],
            'current' => $s['current'] ? [
                'stage' => $s['current']['stage'],
                'file_id' => $s['current']['file_id'],
                'path' => $s['currentFile']?->path ?? $s['current']['path'],
                'started_at' => \Illuminate\Support\Carbon::createFromTimestamp($s['current']['started_at'])->toIso8601String(),
            ] : null,
            'max_attempts' => (int) config('analysis.max_attempts'),
            'stages' => array_values(array_map(fn (array $row) => [
                'key' => $row['key'], 'description' => $row['description'], 'version' => $row['version'],
                'enabled' => $row['enabled'], 'reason' => $row['reason'],
                'done' => $row['done'], 'pending' => $row['pending'], 'stale' => $row['stale'],
                'failed' => $row['failed'], 'gave_up' => $row['gave_up'],
                'seconds_per_file' => $s['durations'][$row['key']] ?? null,
            ], $p['stages'])),
            'decode_seconds_per_file' => $s['durations']['decode'] ?? null,
            'sidecar' => $s['sidecar'],
        ];
    }

    /** @return list<array{file_id: int, path: string, stage: string, message: ?string, attempts: int, gave_up: bool, at: ?string}> */
    public function problemsToApi(string $stage = ''): array
    {
        $max = (int) config('analysis.max_attempts');

        return array_map(fn (array $row) => [
            'file_id' => $row['file']->id,
            'path' => $row['file']->path,
            'stage' => $row['stage'],
            'message' => $row['error']['message'] ?? null,
            'attempts' => (int) ($row['error']['attempts'] ?? 1),
            'gave_up' => (int) ($row['error']['attempts'] ?? 1) >= $max,
            'at' => $row['error']['at'] ?? null,
        ], $this->problems($stage));
    }
}
