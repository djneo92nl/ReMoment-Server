<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

use App\Domain\Device\Settings\AdjustmentRange;
use App\Domain\Device\Settings\SoundAdjustment;
use App\Integrations\Common\UnsupportedOperationException;

trait SoundAdjustmentControls
{
    abstract protected function deviceApiClient(): \App\Integrations\Common\HttpConnector;

    public function getSoundAdjustment(): SoundAdjustment
    {
        $adjustment = $this->deviceApiClient()->getStrict('BeoZone/Zone/Sound/Adjustment')['adjustment'] ?? [];
        $ranges = $adjustment['_capabilities']['range'] ?? [];

        $range = fn (string $part) => isset($adjustment[$part])
            ? new AdjustmentRange(
                (int) $adjustment[$part],
                (int) ($ranges[$part][0]['min'] ?? -10),
                (int) ($ranges[$part][0]['max'] ?? 10),
                (int) ($ranges[$part][0]['step'] ?? 1),
            )
            : null;

        return new SoundAdjustment(
            $range('bass'),
            $range('treble'),
            isset($adjustment['loudness']) ? (bool) $adjustment['loudness'] : null,
        );
    }

    public function setSoundAdjustment(?int $bass = null, ?int $treble = null, ?bool $loudness = null): void
    {
        $current = $this->getSoundAdjustment();

        $wanted = ['bass' => $bass, 'treble' => $treble];
        $body = [];

        foreach ($wanted as $part => $value) {
            $range = $current->{$part};
            if ($range === null) {
                if ($value !== null) {
                    throw new UnsupportedOperationException("This device has no {$part} adjustment.");
                }

                continue;
            }
            if ($value !== null && !$range->accepts($value)) {
                throw new \InvalidArgumentException("{$part} must be between {$range->min} and {$range->max} (step {$range->step}).");
            }
            $body[$part] = $value ?? $range->value;
        }

        if ($current->loudness === null && $loudness !== null) {
            throw new UnsupportedOperationException('This device has no loudness adjustment.');
        }
        if ($current->loudness !== null) {
            $body['loudness'] = $loudness ?? $current->loudness;
        }

        // The device wants the whole resource back, not just the changed part.
        $this->deviceApiClient()->putStrict('BeoZone/Zone/Sound/Adjustment', ['adjustment' => $body]);

        $applied = $this->getSoundAdjustment();
        $got = ['bass' => $applied->bass?->value, 'treble' => $applied->treble?->value, 'loudness' => $applied->loudness];
        foreach ($body as $part => $value) {
            if ($got[$part] !== $value) {
                throw new \RuntimeException("The device did not apply the {$part} change.");
            }
        }
    }
}
