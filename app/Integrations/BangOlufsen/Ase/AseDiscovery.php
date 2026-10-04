<?php

namespace App\Integrations\BangOlufsen\Ase;

use App\Domain\Device\DiscoveredDevice;
use App\Integrations\Contracts\DiscoveryInterface;
use App\Services\Discovery\SsdpClient;

class AseDiscovery implements DiscoveryInterface
{
    public function __construct(private SsdpClient $ssdp) {}

    public function discover(): array
    {
        $discovered = [];

        foreach ($this->ssdp->search('urn:schemas-upnp-org:device:MediaRenderer:1') as $info) {
            $descriptor = $this->ssdp->describe($info['location']);
            if (!$descriptor) {
                continue;
            }

            $deviceInfo = $descriptor['device'] ?? [];
            $manufacturer = $deviceInfo['manufacturer'] ?? null;
            $model = $deviceInfo['modelName'] ?? null;

            if (!$manufacturer || !$model) {
                continue;
            }

            $driverConfig = config("devices.{$manufacturer}.{$model}");
            if (!$driverConfig) {
                continue;
            }

            $udn = $deviceInfo['UDN'] ?? null;

            $discovered[$info['ip']] = new DiscoveredDevice(
                ip_address: $info['ip'],
                device_name: $deviceInfo['friendlyName'] ?? $info['ip'],
                device_brand_name: $manufacturer,
                device_product_type: $model,
                device_driver: $driverConfig['driver'],
                device_driver_name: $driverConfig['driver_name'],
                meta: $udn ? ['upnp_uuid' => $udn] : [],
            );
        }

        return array_values($discovered);
    }
}
