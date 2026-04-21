<?php

namespace App\Integrations\Spotify;

use App\Models\Device;

class SpotifyDevice
{
    public static function findOrProvision(): Device
    {
        return Device::firstOrCreate(
            ['device_driver' => MusicPlayerDriver::class],
            [
                'device_name'         => 'Spotify Connect',
                'device_brand_name'   => 'Spotify',
                'device_driver_name'  => 'Spotify',
                'device_product_type' => 'Spotify Connect',
                'ip_address'          => null,
            ]
        );
    }
}
