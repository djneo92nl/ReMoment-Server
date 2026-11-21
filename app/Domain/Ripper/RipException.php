<?php

namespace App\Domain\Ripper;

/** A refused rip request: the API answers `{ error: code, message }` with this status. */
final class RipException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
