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
    /** Some renderers (Philips TVs) announce only their services, never the device type. */
    private const SEARCH_TARGETS = [RendererSetup::MEDIA_RENDERER, RendererSetup::AV_TRANSPORT];

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
                meta: RendererSetup::meta($description),
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

        foreach (self::SEARCH_TARGETS as $target) {
            foreach ($this->ssdp->search($target) as $info) {
                $responders[$info['location']] ??= $info;
            }
        }

        if ($responders === []) {
            $this->diagnostics[] = 'No UPnP multicast (SSDP) replies. Normal inside a Docker bridge network: give the renderer\'s IP (or the URL of its description) under "Known device IPs", or run with host networking.';
        }

        $hosts = array_fill_keys($this->hosts, 'given');
        foreach (Device::where('device_driver_name', DedicatedDrivers::GENERIC)->whereNotNull('ip_address')->pluck('ip_address') as $ip) {
            $hosts[$ip] ??= 'stored';
        }

        foreach ($hosts as $host => $source) {
            // The URL of the renderer's description: no search needed (and the only way to a renderer that answers multicast only).
            if (preg_match('#^https?://#i', $host)) {
                $responders[$host] ??= ['ip' => (string) parse_url($host, PHP_URL_HOST), 'location' => $host, 'st' => null, 'usn' => null];

                continue;
            }

            if (collect($responders)->contains('ip', $host)) {
                continue;
            }

            $answers = [];
            foreach (self::SEARCH_TARGETS as $target) {
                $answers = $this->ssdp->searchHost($host, $target);

                if ($answers !== []) {
                    break;
                }
            }

            foreach ($answers as $info) {
                $responders[$info['location']] = $info;
            }

            if ($answers === []) {
                $this->diagnostics[] = "{$host} ({$source}) did not answer a UPnP search on port 1900. Some renderers answer multicast only: give the URL of its description instead (e.g. http://{$host}:49153/description.xml).";
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
}
