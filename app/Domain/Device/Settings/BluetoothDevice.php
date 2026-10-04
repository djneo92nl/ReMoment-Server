<?php

namespace App\Domain\Device\Settings;

/** A phone/computer paired with the device. */
final readonly class BluetoothDevice
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $address = null,
        public bool $connected = false,
    ) {}

    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'address' => $this->address, 'connected' => $this->connected];
    }
}
