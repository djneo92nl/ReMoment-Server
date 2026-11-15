<?php

namespace App\Integrations\Wiim;

use App\Domain\Device\DeviceModels;
use App\Domain\Device\DiscoveredDevice;
use App\Integrations\Contracts\HostHintedDiscovery;
use App\Integrations\Upnp\DeviceDescription;
use App\Integrations\Upnp\RendererSetup;
use App\Services\Discovery\SsdpClient;

/**
 * Finds WiiM streamers among the UPnP MediaRenderers. A renderer is a WiiM when its descriptor's manufacturer says
 * LinkPlay or WiiM, or its model starts with "WiiM" (what a real WiiM puts in these fields is not in the vendor
 * document: unverified, see docs/architecture/wiim.md). Registered before the generic UPnP discoverer, which
 * skips what {@see \App\Domain\Device\DedicatedDrivers} claims.
 */
class WiimDiscovery implements HostHintedDiscovery
{
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

    public static function isWiim(?string $manufacturer, ?string $model): bool
    {
        $manufacturer = mb_strtolower((string) $manufacturer);

        return str_contains($manufacturer, 'linkplay') || str_contains($manufacturer, 'wiim') || str_starts_with(mb_strtolower(trim((string) $model)), 'wiim');
    }

    public function discover(): array
    {
        $this->diagnostics = [];
        $fallback = DeviceModels::find('WiiM', 'WiiM Mini');
        $locations = [];

        foreach ($this->ssdp->search(RendererSetup::MEDIA_RENDERER) as $info) {
            $locations[$info['location']] = $info['ip'];
        }

        foreach ($this->hosts as $host) {
            if (in_array($host, $locations, true)) {
                continue;
            }

            foreach ($this->ssdp->searchHost($host, RendererSetup::MEDIA_RENDERER) as $info) {
                $locations[$info['location']] = $info['ip'];
            }
        }

        $discovered = [];

        foreach ($locations as $location => $ip) {
            $description = DeviceDescription::fetch($location);

            if (!$description || !$description->udn || !self::isWiim($description->manufacturer, $description->modelName)) {
                continue;
            }

            $definition = DeviceModels::find('WiiM', (string) $description->modelName) ?? $fallback;
            $meta = RendererSetup::meta($description);
            // Volume and seek go through the WiiM's own API, whatever the UPnP descriptor lists.
            $capabilities = array_values(array_unique([...(json_decode($meta['upnp_capabilities'] ?? '[]', true) ?: []), 'seek', 'volume_control']));
            $meta['upnp_capabilities'] = json_encode($capabilities);

            $discovered[$ip] = new DiscoveredDevice(
                ip_address: $ip,
                device_name: $description->friendlyName,
                device_brand_name: 'WiiM',
                device_product_type: $description->modelName ?? 'WiiM',
                device_driver: $definition['driver'] ?? MusicPlayerDriver::class,
                device_driver_name: $definition['driver_name'] ?? 'WiiM',
                meta: $meta,
            );
        }

        if ($discovered === []) {
            $this->diagnostics[] = 'No WiiM answered a UPnP search. Inside a Docker bridge network multicast does not arrive: give the WiiM\'s IP under "Known device IPs", or run with host networking.';
        }

        return array_values($discovered);
    }
}
