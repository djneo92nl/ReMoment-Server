<?php

namespace App\Domain\Export;

use RuntimeException;

/** An export that cannot start: over the limits, nothing exportable, ffmpeg missing. Carries the estimate when there is one. */
class ExportRefused extends RuntimeException
{
    /** @param  array<string, mixed>|null  $estimate */
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422, public readonly ?array $estimate = null)
    {
        parent::__construct($message);
    }
}
