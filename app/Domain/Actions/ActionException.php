<?php

namespace App\Domain\Actions;

/** A refusal an action's handler reports; the runner turns it into the API's `{error, message}` answer. */
final class ActionException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
