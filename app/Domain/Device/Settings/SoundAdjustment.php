<?php

namespace App\Domain\Device\Settings;

use App\Integrations\Common\UnsupportedOperationException;

/** Bass/treble/loudness; a part the device doesn't have is null. */
final readonly class SoundAdjustment
{
    public function __construct(
        public ?AdjustmentRange $bass,
        public ?AdjustmentRange $treble,
        public ?bool $loudness,
    ) {}

    /**
     * Checks a change against what this device has: a part it lacks is unsupported, a value outside
     * its range invalid. Null arguments are parts that are left as they are.
     *
     * @throws UnsupportedOperationException
     * @throws \InvalidArgumentException
     */
    public function assertCanSet(?int $bass, ?int $treble, ?bool $loudness): void
    {
        foreach (['bass' => $bass, 'treble' => $treble] as $part => $value) {
            if ($value === null) {
                continue;
            }

            ($this->{$part} ?? throw new UnsupportedOperationException("This device has no {$part} adjustment."))
                ->assertAccepts($part, $value);
        }

        if ($loudness !== null && $this->loudness === null) {
            throw new UnsupportedOperationException('This device has no loudness adjustment.');
        }
    }

    public function toArray(): array
    {
        return [
            'bass' => $this->bass?->toArray(),
            'treble' => $this->treble?->toArray(),
            'loudness' => $this->loudness,
        ];
    }
}
