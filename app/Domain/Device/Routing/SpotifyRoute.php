<?php

namespace App\Domain\Device\Routing;

use App\Domain\Device\DeviceRouting;
use App\Domain\Device\SpotifyRouting;
use App\Models\Device;

/** Spotify Connect: a speaker that plays Spotify hands playback commands to the Spotify virtual device (see SpotifyRouting). */
final class SpotifyRoute implements RoutingRule
{
    public function target(Device $device, string $contract): ?Device
    {
        if (!in_array($contract, DeviceRouting::DELEGATED_CONTRACTS, true) || !SpotifyRouting::isRoutedTarget($device)) {
            return null;
        }

        return SpotifyRouting::deviceFor($device, $contract);
    }

    public function capabilities(Device $device): array
    {
        return SpotifyRouting::delegatedCapabilities($device);
    }
}
