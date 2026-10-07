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

    /** @throws \InvalidArgumentException when the device would not accept $value for $part */
    public function assertAccepts(string $part, int $value): void
    {
        if (!$this->accepts($value)) {
            throw new \InvalidArgumentException("{$part} must be between {$this->min} and {$this->max} (step {$this->step}).");
        }
    }

    public function toArray(): array
    {
        return ['value' => $this->value, 'min' => $this->min, 'max' => $this->max, 'step' => $this->step];
    }
}
