<?php

namespace App\Integrations\Upnp;

use App\Domain\Device\DedicatedDrivers;
use App\Domain\Device\DeviceModels;
use App\Domain\Device\DiscoveredDevice;
use App\Integrations\Common\UpnpSoap;
use App\Integrations\Contracts\HostHintedDiscovery;
use App\Models\Device;
use App\Services\Discovery\SsdpClient;

/**
 * The fallback discoverer: every UPnP MediaRenderer on the network that can play audio and that no dedicated
 * driver claims ({@see DedicatedDrivers}). Registered last under `discoverers`. The renderer's own manufacturer
 * and model are kept as brand and product; the control addresses and what the SCPDs list go to the device's meta.
 */
class UpnpDiscovery implements HostHintedDiscovery
{
    private const SEARCH_TARGET = 'urn:schemas-upnp-org:device:MediaRenderer:1';

    /** @var string[] */
    private array $hosts = [];

    /** @var string[] */
    private array $diagnostics = [];

    public function __construct(private SsdpClient $ssdp) {}

    public function withHosts(array $hosts): static
    {
        $this->hosts = array_values(array_unique($hosts));

        return $this;
    }

    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function discover(): array
    {
        $this->diagnostics = [];
        $definition = DeviceModels::find('UPnP', 'Generic MediaRenderer');
        $discovered = [];

        foreach ($this->responders() as $info) {
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

    /**
     * Multicast first; then every given or stored renderer address by unicast, which is the part that works
     * in a Docker bridge network (multicast does not leave it).
     *
     * @return array<string, array{ip: string, location: string, st: string|null, usn: string|null}> by location
     */
    private function responders(): array
    {
        $responders = [];

        foreach ($this->ssdp->search(self::SEARCH_TARGET) as $info) {
            $responders[$info['location']] = $info;
        }

        if ($responders === []) {
            $this->diagnostics[] = 'No UPnP multicast (SSDP) replies. Normal inside a Docker bridge network: give the renderer\'s IP under "Known device IPs", or run with host networking.';
        }

        $hosts = array_fill_keys($this->hosts, 'given');
        foreach (Device::where('device_driver_name', DedicatedDrivers::GENERIC)->whereNotNull('ip_address')->pluck('ip_address') as $ip) {
            $hosts[$ip] ??= 'stored';
        }

        foreach ($hosts as $ip => $source) {
            if (collect($responders)->contains('ip', $ip)) {
                continue;
            }

            $answers = $this->ssdp->searchHost($ip, self::SEARCH_TARGET);

            foreach ($answers as $info) {
                $responders[$info['location']] = $info;
            }

            if ($answers === []) {
                $this->diagnostics[] = "{$ip} ({$source}) did not answer a UPnP search on port 1900 (not a MediaRenderer, or it blocks it).";
            }
        }

        return $responders;
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
