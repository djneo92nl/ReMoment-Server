<?php

namespace App\Domain\Device;

/** Charge level (percent) and charging flag of a battery-powered device. */
final readonly class BatteryStatus
{
    public function __construct(
        public int $level,
        public bool $charging = false,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self((int) ($data['level'] ?? 0), (bool) ($data['charging'] ?? false));
    }

    /** @return array{level: int, charging: bool} */
    public function toArray(): array
    {
        return ['level' => $this->level, 'charging' => $this->charging];
    }
}
