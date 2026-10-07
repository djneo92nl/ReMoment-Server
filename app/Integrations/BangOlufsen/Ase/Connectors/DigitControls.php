<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

trait DigitControls
{
    abstract protected function deviceApiClient(): \App\Integrations\Common\HttpConnector;

    public function sendDigit(int $digit): void
    {
        if ($digit < 0 || $digit > 9) {
            throw new \InvalidArgumentException('A digit is 0 to 9.');
        }

        $this->deviceApiClient()->postStrict('BeoZone/Zone/Digits', ['digits' => $digit]);
    }
}
