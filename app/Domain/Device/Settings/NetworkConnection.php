<?php

namespace App\Domain\Device\Settings;

/** One network interface (wired or wireless) of a device. Never carries a passphrase. */
final readonly class NetworkConnection
{
    public function __construct(
        public string $type,
        public string $status,
        public ?bool $dhcp = null,
        public ?string $address = null,
        public ?string $subnetMask = null,
        public ?string $gateway = null,
        public ?string $preferredDns = null,
        public ?string $alternativeDns = null,
        public ?string $ssid = null,
        public ?string $frequency = null,
        public ?string $quality = null,
        public ?int $signal = null,
        public ?string $security = null,
    ) {}

    public function toArray(): array
    {
        $blank = fn (?string $value) => ($value === null || $value === '') ? null : $value;

        return [
            'type' => $this->type,
            'status' => $this->status,
            'dhcp' => $this->dhcp,
            'address' => $blank($this->address),
            'subnet_mask' => $blank($this->subnetMask),
            'gateway' => $blank($this->gateway),
            'preferred_dns' => $blank($this->preferredDns),
            'alternative_dns' => $blank($this->alternativeDns),
            'ssid' => $this->ssid,
            'frequency' => $this->frequency,
            'quality' => $this->quality,
            'signal' => $this->signal,
            'security' => $this->security,
        ];
    }
}
