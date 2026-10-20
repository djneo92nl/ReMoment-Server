<?php

namespace App\Domain\Analysis;

use App\Models\File;
use App\Models\FileAnalysis;

/**
 * A stage that works on the decoded audio of the whole file. Instead of every such stage running its own
 * ffmpeg (decoding the file again each time), each describes the filter branch it needs and the job runs one
 * ffmpeg with all branches (`DecodePass`); the stage then reads what its branch produced.
 */
interface DecodedStage extends AnalysisStage
{
    /** The filter branch this stage needs from the shared decode. */
    public function branch(File $file): DecodeBranch;

    /**
     * The stage's `file_analyses` attributes from what the shared decode produced: `$pass->stderr` (filter
     * summaries and decoder warnings of all branches) and, for a branch with an output, `$pass->file($key)`.
     *
     * @return array<string, mixed>
     */
    public function fromDecode(File $file, string $localPath, FileAnalysis $analysis, DecodePass $pass): array;
}
