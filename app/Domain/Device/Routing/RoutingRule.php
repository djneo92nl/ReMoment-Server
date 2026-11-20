<?php

namespace App\Domain\Device\Routing;

use App\Models\Device;

/**
 * One way a device is made of two: while the rule holds, playback commands sent to $device are executed by another
 * device's driver and $device reports that device's playback capabilities on top of its own. `DeviceRouting` asks the
 * rules in order; the first one that routes a contract wins. Spotify Connect (`SpotifyRoute`) and a sub device whose link is
 * active (`SubDeviceRoute`) are the two.
 */
interface RoutingRule
{
    /** The device whose driver executes $contract for $device, or null when this rule does not route it. */
    public function target(Device $device, string $contract): ?Device;

    /**
     * The playback capabilities $device gains from this rule (the routed device's, limited to the delegated contracts).
     *
     * @return string[]
     */
    public function capabilities(Device $device): array;
}
