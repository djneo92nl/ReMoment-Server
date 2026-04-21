<?php

namespace App\Domain\Device;

class DiscoveredDevice
{
    public function __construct(
        public readonly string $ip_address,
        public readonly string $device_name,
        public readonly string $device_brand_name,
        public readonly string $device_product_type,
        public readonly string $device_driver,
        public readonly string $device_driver_name,
        /** @var array<string, string> Driver-specific identifiers stored in DeviceMeta (e.g. upnp_uuid, sonos_uuid) */
        public readonly array $meta = [],
    ) {}
}
