<?php

namespace App\Integrations\Contracts;

interface DigitsInterface
{
    /** Press one number key (0-9), e.g. to pick a channel on the active TV or set-top box source. */
    public function sendDigit(int $digit): void;
}
