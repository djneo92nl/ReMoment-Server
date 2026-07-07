<?php

namespace Djneo92nl\BeoMozart\Exceptions;

class MozartApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorId = null,
        public readonly ?string $errorMessage = null,
    ) {
        parent::__construct($message);
    }
}
