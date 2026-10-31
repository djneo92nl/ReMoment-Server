<?php

namespace App\Domain\Analysis;

/**
 * A stage that is computed only when someone asks for ONE track (`AnalyzeTrackDeep`, `DeepAnalysis`), never by the
 * nightly `library:analyze`: the backlog, `/settings/analysis` and the per-file dots do not know it. Its result and
 * version live in `file_analyses` like any stage, so a changed file or a new version makes it stale.
 *
 * It also says what `DeepAnalysis` needs to run and show it without knowing the kind: where its result is stored, the
 * service that computes it, the queue it runs on, how long a job may take and what to expect.
 */
interface OnDemandStage extends AnalysisStage
{
    /** The `file_analyses` column that holds the result. */
    public function column(): string;

    /** The base URL of the service that computes it, null when not configured. */
    public function serviceUrl(): ?string;

    /** The queue its job runs on. */
    public function queue(): string;

    /** Seconds the job may run (the service request's timeout plus decoding): below its worker's --timeout and the queue's retry_after. */
    public function jobTimeout(): int;

    /** Seconds it is expected to take for a track of this length: an estimate, measured on a fast machine. */
    public function estimateSeconds(?int $trackSeconds): int;

    /** RAM the service needs while it works, in MB, for the estimate shown to the admin; null when not worth mentioning. */
    public function estimateRamMb(): ?int;
}
