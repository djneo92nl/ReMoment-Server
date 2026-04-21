<?php

namespace App\Integrations\Sonos;

use App\Domain\Device\DiscoveredDevice;
use App\Integrations\Contracts\DiscoveryInterface;
use duncan3dc\Sonos\Devices\Device as SonosDevice;
use duncan3dc\Sonos\Network;

class SonosDiscovery implements DiscoveryInterface
{
    public function __construct(private ?Network $network = null)
    {
        $this->network ??= new Network;
    }

    public function discover(): array
    {
        $discovered = [];

        foreach ($this->network->getSpeakers() as $speaker) {
            $ip = $speaker->getIp();
            $uuid = $speaker->getUuid();

            $sonosDevice = new SonosDevice($ip);
            $model = $sonosDevice->getModel();

            $discovered[] = new DiscoveredDevice(
                ip_address: $ip,
                device_name: $speaker->getName() ?: ($speaker->getRoom() ?: $ip),
                device_brand_name: 'Sonos',
                device_product_type: $model,
                device_driver: MusicPlayerDriver::class,
                device_driver_name: 'Sonos',
                meta: ['sonos_uuid' => $uuid],
            );
        }

        return $discovered;
    }
}
