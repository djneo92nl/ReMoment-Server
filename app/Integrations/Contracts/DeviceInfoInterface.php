<?php

namespace App\Integrations\Contracts;

use App\Domain\Device\Settings\DeviceInfo;

interface DeviceInfoInterface
{
    public function getDeviceInfo(): DeviceInfo;

    /** Rename the device on the device itself. */
    public function setDeviceName(string $name): void;
}
