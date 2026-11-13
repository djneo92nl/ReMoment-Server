<?php

namespace App\Domain\Manager;

use RuntimeException;

/** A call to the ReMoment Manager failed; `$reason` is a short code (no_token, unreachable, unauthorized, busy, …), the message is for the admin. */
class ManagerException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
