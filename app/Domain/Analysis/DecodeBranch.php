<?php

namespace App\Domain\Analysis;

/**
 * One stage's part of the shared ffmpeg run: a filter chain from `{in}` (the decoded audio) to `{out}`, and
 * where its audio goes. No $output means the stage reads the filters' log lines (stderr) and the audio is dropped;
 * otherwise $output are the ffmpeg output options (e.g. `-f s16le`) and the audio is written to a temp file.
 */
final class DecodeBranch
{
    /** @param list<string>|null $output */
    public function __construct(public readonly string $graph, public readonly ?array $output = null) {}
}
