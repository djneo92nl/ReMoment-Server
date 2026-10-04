<?php

namespace App\Domain\Device\Settings;

final readonly class BluetoothState
{
    /**
     * @param  BluetoothDevice[]  $devices
     * @param  string[]  $reconnectModes  what `reconnect_mode` may be set to; empty when it can't be changed
     * @param  bool  $writable  false for platforms that only list paired devices
     */
    public function __construct(
        public bool $enabled,
        public bool $discoverable,
        public ?string $reconnectMode,
        public array $reconnectModes,
        public bool $writable,
        public array $devices,
    ) {}

    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'discoverable' => $this->discoverable,
            'reconnect_mode' => $this->reconnectMode,
            'reconnect_modes' => $this->reconnectModes,
            'writable' => $this->writable,
            'devices' => array_map(fn (BluetoothDevice $d) => $d->toArray(), $this->devices),
        ];
    }
}
