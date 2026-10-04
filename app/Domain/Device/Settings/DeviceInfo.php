<?php

namespace App\Domain\Device\Settings;

final readonly class DeviceInfo
{
    public function __construct(
        public string $name,
        public ?string $productType = null,
        public ?string $firmware = null,
        public ?string $macAddress = null,
        public bool $renamable = true,
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'product_type' => $this->productType,
            'firmware' => $this->firmware,
            'mac_address' => $this->macAddress,
            'renamable' => $this->renamable,
        ];
    }
}
