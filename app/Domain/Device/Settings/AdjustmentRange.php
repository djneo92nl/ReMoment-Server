<?php

namespace App\Domain\Device\Settings;

/** A numeric sound setting with the range the device accepts. */
final readonly class AdjustmentRange
{
    public function __construct(
        public int $value,
        public int $min,
        public int $max,
        public int $step = 1,
    ) {}

    public function accepts(int $value): bool
    {
        return $value >= $this->min && $value <= $this->max && ($value - $this->min) % max($this->step, 1) === 0;
    }

    public function toArray(): array
    {
        return ['value' => $this->value, 'min' => $this->min, 'max' => $this->max, 'step' => $this->step];
    }
}
