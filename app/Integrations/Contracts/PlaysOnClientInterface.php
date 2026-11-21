<?php

namespace App\Integrations\Contracts;

use App\Models\Device;

/** Capability `play_on_client`: start an item in the server's client app on a device. */
interface PlaysOnClientInterface
{
    /**
     * @param  float  $position  where to start, in seconds
     *
     * @throws \RuntimeException when the client app on the device is not connected to the server (a message fit to show)
     * @throws \Throwable when the server does not answer
     */
    public function playOnClient(Device $device, string $itemId, float $position = 0): void;
}
