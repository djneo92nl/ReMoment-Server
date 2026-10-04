<?php

namespace App\Domain\Device\Settings;

/** A Wi-Fi network the device knows. */
final readonly class WifiNetwork
{
    public function __construct(
        public string $ssid,
        public ?string $frequency = null,
        public ?string $security = null,
        public bool $configured = true,
        public bool $active = false,
    ) {}

    public function toArray(): array
    {
        return [
            'ssid' => $this->ssid,
            'frequency' => $this->frequency,
            'security' => $this->security,
            'configured' => $this->configured,
            'active' => $this->active,
        ];
    }
}
