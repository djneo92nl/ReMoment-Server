<?php

namespace App\Domain\Sync;

use RuntimeException;

/** A sync operation that cannot go ahead (over budget, nothing to sync, a busy target ...), with the HTTP status and optional estimate. */
class SyncRefused extends RuntimeException
{
    /** @param  array<string, mixed>|null  $estimate */
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422, public readonly ?array $estimate = null)
    {
        parent::__construct($message);
    }
}
