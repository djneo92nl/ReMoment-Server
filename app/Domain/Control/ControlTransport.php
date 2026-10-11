<?php

namespace App\Domain\Control;

use App\Models\Device;

/**
 * Turns an abstract profile command into the real call for a device: an ASE
 * one-way command, an HTTP call to an ESP, … Registered under `transports` in
 * config/control-profiles.php.
 */
interface ControlTransport
{
    /** Whether commands for this profile can be sent to this device. */
    public function supports(Device $device, ControlProfile $profile): bool;

    /**
     * The command ids of the profile this transport can send to the device, or
     * null for all of them. Controls the transport cannot deliver are not listed.
     *
     * @return string[]|null
     */
    public function commands(Device $device, ControlProfile $profile): ?array;

    /**
     * @throws \App\Integrations\Common\UnsupportedOperationException when the transport has no call for the command
     */
    public function send(Device $device, ControlProfile $profile, string $commandId): void;
}
