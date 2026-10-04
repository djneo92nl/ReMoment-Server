<?php

namespace App\Integrations\Sonos;

use App\Domain\Device\DiscoveredDevice;
use App\Integrations\Contracts\HostHintedDiscovery;
use App\Integrations\Sonos\Services\SonosTopology;
use App\Models\Device;
use App\Services\Discovery\SsdpClient;

/**
 * Finds Sonos speakers without relying on multicast alone.
 *
 * Seeds: hosts given by the user, speakers already stored, and an SSDP search.
 * The first seed that answers lists the whole household (ZoneGroupTopology), so one
 * reachable speaker is enough, which is what makes this work behind Docker's bridge.
 */
class SonosDiscovery implements HostHintedDiscovery
{
    private const SEARCH_TARGET = 'urn:schemas-upnp-org:device:ZonePlayer:1';

    /** @var string[] */
    private array $hosts = [];

    /** @var string[] */
    private array $diagnostics = [];

    public function __construct(private SsdpClient $ssdp, private SonosTopology $topology) {}

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

        $seeds = [];
        foreach ($this->hosts as $ip) {
            $seeds[$ip] = 'given';
        }
        foreach (Device::where('device_brand_name', 'Sonos')->pluck('ip_address') as $ip) {
            $seeds[$ip] ??= 'stored';
        }

        // Other UPnP gear (e.g. a Hue bridge) answers any search: keep only replies that say they are a Sonos.
        $ssdp = array_filter(
            $this->ssdp->search(self::SEARCH_TARGET),
            fn ($found) => str_contains(($found['st'] ?? '').($found['usn'] ?? ''), 'ZonePlayer')
                || str_contains($found['usn'] ?? '', 'RINCON_')
        );
        foreach ($ssdp as $found) {
            $seeds[$found['ip']] ??= 'ssdp';
        }
        if ($ssdp === []) {
            $this->diagnostics[] = 'No multicast (SSDP) replies. Normal inside a Docker bridge network; rely on stored/given IPs, or run with host networking.';
        }

        $members = [];
        foreach ($seeds as $ip => $source) {
            if (collect($members)->contains('ip', $ip)) {
                continue;
            }
            $found = $this->topology->members($ip);
            if ($found === null) {
                $this->diagnostics[] = "{$ip} ({$source}) did not answer on port 1400.";

                continue;
            }
            foreach ($found as $member) {
                $members[$member['uuid']] ??= $member;
            }
        }

        if ($seeds === []) {
            $this->diagnostics[] = 'Nothing to probe: enter a Sonos IP address to find the rest by topology.';
        }

        $discovered = [];
        foreach ($members as $member) {
            $info = $this->topology->describe($member['ip']);

            $discovered[] = new DiscoveredDevice(
                ip_address: $member['ip'],
                device_name: $member['name'] ?: ($info['name'] ?? $member['ip']),
                device_brand_name: 'Sonos',
                device_product_type: $info['model'] ?? 'Sonos Speaker',
                device_driver: MusicPlayerDriver::class,
                device_driver_name: 'Sonos',
                meta: ['sonos_uuid' => $member['uuid']],
            );
        }

        return $discovered;
    }
}
