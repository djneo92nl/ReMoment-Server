<?php

namespace App\Integrations\Contracts;

use App\Domain\Device\Settings\BluetoothState;

interface BluetoothInterface
{
    public function getBluetooth(): BluetoothState;

    /**
     * Make the device discoverable for pairing, and/or change how it
     * reconnects (null = leave as is). Throws UnsupportedOperationException
     * on a platform that can only list paired devices, and
     * InvalidArgumentException for an unknown reconnect mode.
     */
    public function setBluetooth(?bool $discoverable = null, ?string $reconnectMode = null): void;

    /** Forget a paired device; $id is the `id` from getBluetooth(). */
    public function removeBluetoothDevice(string $id): void;
}
