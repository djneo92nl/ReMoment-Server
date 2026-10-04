<?php

namespace App\Domain\Device\Settings;

/** Bass/treble/loudness; a part the device doesn't have is null. */
final readonly class SoundAdjustment
{
    public function __construct(
        public ?AdjustmentRange $bass,
        public ?AdjustmentRange $treble,
        public ?bool $loudness,
    ) {}

    public function toArray(): array
    {
        return [
            'bass' => $this->bass?->toArray(),
            'treble' => $this->treble?->toArray(),
            'loudness' => $this->loudness,
        ];
    }
}
