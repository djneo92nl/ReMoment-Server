<?php

namespace App\Integrations\Common;

use App\Domain\Device\DiscoveredDevice;
use App\Integrations\Contracts\DiscoveryInterface;
use App\Services\Discovery\SsdpClient;

/**
 * SSDP discovery of UPnP MediaRenderers, for platforms that announce themselves that way (ASE, Mozart).
 * A device is reported when its manufacturer and model are in config/devices.php with this
 * discoverer's driver name, so each platform only finds its own models.
 */
abstract class UpnpMediaRendererDiscovery implements DiscoveryInterface
{
    public function __construct(private SsdpClient $ssdp) {}

    /** The `driver_name` in config/devices.php whose models this discoverer reports. */
    abstract protected function driverName(): string;

    public function discover(): array
    {
        $models = config('devices', []);
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

            // Not config("devices.{$manufacturer}.{$model}"): a model name may contain a dot.
            $driverConfig = $models[$manufacturer][$model] ?? null;
            if (!is_array($driverConfig) || ($driverConfig['driver_name'] ?? null) !== $this->driverName()) {
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
