<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

use App\Domain\Device\Settings\AdjustmentRange;
use App\Domain\Device\Settings\SleepTimer;
use App\Integrations\Common\UnsupportedOperationException;

trait SleepTimerControls
{
    abstract protected function deviceApiClient(): \App\Integrations\Common\HttpConnector;

    public function getSleepTimer(): SleepTimer
    {
        $timer = $this->deviceApiClient()->getStrict('BeoDevice/powerManagement/sleepTimer')['sleepTimer'] ?? [];
        $range = $timer['_capabilities']['range']['duration'][0] ?? [];

        return new SleepTimer(
            new AdjustmentRange((int) ($timer['duration'] ?? 0), (int) ($range['min'] ?? 0), (int) ($range['max'] ?? 60), (int) ($range['step'] ?? 1)),
            in_array('duration', $timer['_capabilities']['editable'] ?? [], true),
        );
    }

    public function setSleepTimer(int $minutes): void
    {
        $current = $this->getSleepTimer();

        // Older platforms (BeoPlay V1) list the timer but answer 404 "Not implemented" to a write.
        if (!$current->writable) {
            throw new UnsupportedOperationException('This device cannot set a sleep timer.');
        }

        // The device ignores an out-of-range value and still answers 200.
        if (!$current->minutes->accepts($minutes)) {
            throw new \InvalidArgumentException("minutes must be between {$current->minutes->min} and {$current->minutes->max} (step {$current->minutes->step}).");
        }

        $this->deviceApiClient()->putStrict('BeoDevice/powerManagement/sleepTimer', [
            'sleepTimer' => ['duration' => $minutes],
        ]);

        if ($this->getSleepTimer()->minutes->value !== $minutes) {
            throw new \RuntimeException('The device did not apply the sleep timer.');
        }
    }
}
