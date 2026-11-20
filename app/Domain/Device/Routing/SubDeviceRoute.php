<?php

namespace App\Domain\Device\Routing;

use App\Domain\Device\Capabilities;
use App\Domain\Device\DeviceRouting;
use App\Domain\SubDevices\DeviceLinks;
use App\Domain\SubDevices\LinkIndex;
use App\Models\Device;

/**
 * A sub device whose link is active (its input or source is selected on the host): transport, seek, queue and the
 * playback modes of the host go to the sub device, as far as its driver implements the contract. Volume, power and the
 * host's other sources stay with the host. With several active sub devices the oldest link wins.
 */
final class SubDeviceRoute implements RoutingRule
{
    public function target(Device $device, string $contract): ?Device
    {
        if (!in_array($contract, DeviceRouting::DELEGATED_CONTRACTS, true) || !LinkIndex::isHost($device->id)) {
            return null;
        }

        foreach (DeviceLinks::activeSubLinks($device) as $link) {
            if ($link->device->driver instanceof $contract) {
                return $link->device;
            }
        }

        return null;
    }

    public function capabilities(Device $device): array
    {
        if (!LinkIndex::isHost($device->id)) {
            return [];
        }

        $capabilities = [];

        foreach (DeviceLinks::activeSubLinks($device) as $link) {
            $capabilities = [...$capabilities, ...array_intersect(
                Capabilities::forDriver($link->device->device_driver),
                Capabilities::forContracts(DeviceRouting::DELEGATED_CONTRACTS),
            )];
        }

        return $capabilities;
    }

    /** The active sub device whose now playing the host shows, else null. */
    public function active(Device $device): ?Device
    {
        return LinkIndex::isHost($device->id) ? DeviceLinks::activeSubLinks($device)->first()?->device : null;
    }
}
