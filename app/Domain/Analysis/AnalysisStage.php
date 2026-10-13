<?php

namespace App\Domain\Analysis;

use App\Models\File;
use App\Models\FileAnalysis;

/**
 * One kind of analysis of a file's audio. Its version is a string that changes when its output
 * should be recomputed (an algorithm or model change), so only that stage runs again.
 */
interface AnalysisStage
{
    public function key(): string;

    public function version(): string;

    /** Whether this install can run it (switched on, binary or service available). */
    public function enabled(): bool;

    /**
     * Computes the stage's `file_analyses` attributes from the audio at $localPath. Throws when it fails;
     * the job records the error and carries on with the other stages.
     *
     * @return array<string, mixed>
     */
    public function run(File $file, string $localPath, FileAnalysis $analysis): array;
}
