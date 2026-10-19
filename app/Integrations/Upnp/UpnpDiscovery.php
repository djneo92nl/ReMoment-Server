<?php

namespace App\Integrations\Upnp;

use App\Domain\Device\DedicatedDrivers;
use App\Domain\Device\DeviceModels;
use App\Domain\Device\DiscoveredDevice;
use App\Integrations\Common\UpnpSoap;
use App\Integrations\Contracts\DiscoveryInterface;
use App\Models\Device;
use App\Services\Discovery\SsdpClient;

/**
 * The fallback discoverer: every UPnP MediaRenderer on the network that can play audio and that no dedicated
 * driver claims ({@see DedicatedDrivers}). Registered last under `discoverers`. The renderer's own manufacturer
 * and model are kept as brand and product; the control addresses and what the SCPDs list go to the device's meta.
 */
class UpnpDiscovery implements DiscoveryInterface
{
    public function __construct(private SsdpClient $ssdp) {}

    public function discover(): array
    {
        $definition = DeviceModels::find('UPnP', 'Generic MediaRenderer');
        $discovered = [];

        foreach ($this->ssdp->search('urn:schemas-upnp-org:device:MediaRenderer:1') as $info) {
            $description = DeviceDescription::fetch($info['location']);

            if (!$description || !$description->udn || !$this->wanted($description)) {
                continue;
            }

            $discovered[$info['ip']] = new DiscoveredDevice(
                ip_address: $info['ip'],
                device_name: $description->friendlyName,
                device_brand_name: $description->manufacturer ?? 'UPnP',
                device_product_type: $description->modelName ?? 'MediaRenderer',
                device_driver: $definition['driver'] ?? MusicPlayerDriver::class,
                device_driver_name: DedicatedDrivers::GENERIC,
                meta: $this->meta($description),
            );
        }

        return array_values($discovered);
    }

    private function wanted(DeviceDescription $description): bool
    {
        if (DedicatedDrivers::claims($description->manufacturer, $description->modelName)) {
            return false;
        }

        // A stored device of another driver with this UDN is never taken over.
        $taken = Device::whereHas('meta', fn ($q) => $q->where('key', 'upnp_uuid')->where('value', $description->udn))
            ->where('device_driver_name', '!=', DedicatedDrivers::GENERIC)
            ->exists();

        if ($taken || !$description->controlUrl(DeviceDescription::AV_TRANSPORT)) {
            return false;
        }

        return $this->playsAudio($description);
    }

    /** A TV or video-only renderer lists no audio format. A renderer that doesn't answer is given the benefit of the doubt. */
    private function playsAudio(DeviceDescription $description): bool
    {
        $control = $description->controlUrl(DeviceDescription::CONNECTION_MANAGER);

        if (!$control) {
            return true;
        }

        try {
            $sink = (new UpnpSoap($control, DeviceDescription::CONNECTION_MANAGER))->call('GetProtocolInfo')['Sink'] ?? '';
        } catch (\Throwable) {
            return true;
        }

        return $sink === '' || stripos($sink, 'audio/') !== false;
    }

    /** @return array<string, string> */
    private function meta(DeviceDescription $description): array
    {
        $transportActions = $description->actions(DeviceDescription::AV_TRANSPORT);
        $renderingControl = $description->controlUrl(DeviceDescription::RENDERING_CONTROL);
        $renderingActions = $renderingControl ? $description->actions(DeviceDescription::RENDERING_CONTROL) : [];

        return array_filter([
            'upnp_uuid' => $description->udn,
            'upnp_location' => $description->location,
            'upnp_avtransport_url' => $description->controlUrl(DeviceDescription::AV_TRANSPORT),
            'upnp_renderingcontrol_url' => $renderingControl,
            'upnp_volume_max' => (string) ($description->volumeMax() ?? 100),
            'upnp_capabilities' => json_encode(UpnpCapabilities::fromActions($transportActions, $renderingActions)),
        ], fn ($value) => $value !== null);
    }
}
