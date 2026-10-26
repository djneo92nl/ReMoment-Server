<?php

return [
    // Hourly background scan (`device:discover`); it only fills the discovered-devices list. Turn off where multicast cannot reach this process.
    'auto_discover' => env('DEVICE_AUTO_DISCOVER', true),

    'discoverers' => [
        \App\Integrations\BangOlufsen\Ase\AseDiscovery::class,
        \App\Integrations\BangOlufsen\Ase\ConverterDiscovery::class,
        \App\Integrations\Sonos\SonosDiscovery::class,
        \Remoment\MozartDriver\MozartDiscovery::class,
        // The fallback, last: any other audio MediaRenderer that none of the dedicated drivers claims.
        \App\Integrations\Upnp\UpnpDiscovery::class,
    ],

    // Driver class => its DeviceListenerInterface implementation, run by `php artisan device:listen`.
    'listeners' => [
        \App\Integrations\BangOlufsen\Ase\MusicPlayerDriver::class => \App\Integrations\BangOlufsen\Ase\Services\DeviceListener::class,
        \App\Integrations\BangOlufsen\Ase\VideoPlayerDriver::class => \App\Integrations\BangOlufsen\Ase\Services\DeviceListener::class,
        // The converter pushes the ASE notifications (SOURCE, NOW_PLAYING_*, ...) plus its own tuner/track number.
        \App\Integrations\BangOlufsen\Ase\ConverterDriver::class => \App\Integrations\BangOlufsen\Ase\Services\ConverterListener::class,
        \App\Integrations\Sonos\MusicPlayerDriver::class => \App\Integrations\Sonos\Services\DeviceListener::class,
        \App\Integrations\Spotify\MusicPlayerDriver::class => \App\Integrations\Spotify\Services\DeviceListener::class,
        \Remoment\MozartDriver\MusicPlayerDriver::class => \Remoment\MozartDriver\Services\DeviceListener::class,
        \App\Integrations\Upnp\MusicPlayerDriver::class => \App\Integrations\Upnp\Services\DeviceListener::class,
        \App\Integrations\Philips\VideoPlayerDriver::class => \App\Integrations\Philips\Services\DeviceListener::class,
        \App\Integrations\Philips\Streamium\MusicPlayerDriver::class => \App\Integrations\Philips\Streamium\Services\DeviceListener::class,
    ],

    // device_meta keys holding a platform's multiroom peer ID (see MultiRoomInterface::multiRoomMetaKey()).
    // Peer IDs are matched across all of them: ASE and Mozart JIDs are mostly interchangeable, so an ASE
    // device can list a Mozart listener and vice versa. `converter_jid` is a BeoLink Converter NL/ML, which
    // an ASE player can join (it hosts, but is no MultiRoomInterface driver itself).
    'multiroom_meta_keys' => ['ase_jid', 'mozart_jid', 'sonos_uuid', 'converter_jid'],

    'Bang & Olufsen' => [
        'BeoSound Essence' => [
            'driver_name' => 'ASE',
            'driver' => \App\Integrations\BangOlufsen\Ase\MusicPlayerDriver::class,
            'speaker' => 'external',
        ],
        // The only ASE model with WiSA hardware (wireless speakers): see HardwareFeatures.
        'BeoSound Moment' => [
            'driver_name' => 'ASE',
            'driver' => \App\Integrations\BangOlufsen\Ase\MusicPlayerDriver::class,
            'speaker' => 'external',
            'wisa' => true,
        ],
        'Beoplay M5' => [
            'driver_name' => 'ASE',
            'driver' => \App\Integrations\BangOlufsen\Ase\MusicPlayerDriver::class,
            'speaker' => 'internal',
        ],
        'Beoplay M3' => [
            'driver_name' => 'ASE',
            'driver' => \App\Integrations\BangOlufsen\Ase\MusicPlayerDriver::class,
            'speaker' => 'internal',
        ],
        // A B&O TV box (the BeoPlay V1): an ASE device with inputs and apps, see docs/architecture/beovision-tv.md.
        'Beoplay V1' => [
            'driver_name' => 'ASE',
            'driver' => \App\Integrations\BangOlufsen\Ase\VideoPlayerDriver::class,
        ],
        // NetworkLink/MasterLink converter: not a UPnP renderer, found with `device:discover --brand=converter --host=IP`.
        'BeoLink Converter NL/ML' => [
            'driver_name' => 'ASE',
            'driver' => \App\Integrations\BangOlufsen\Ase\ConverterDriver::class,
        ],
        'Beoconnect Core' => [
            'driver_name' => 'Mozart',
            'driver' => \Remoment\MozartDriver\MusicPlayerDriver::class,
        ],
        'Beolab 8' => [
            'driver_name' => 'Mozart',
            'driver' => \Remoment\MozartDriver\MusicPlayerDriver::class,
        ],
        'Beolab 28' => [
            'driver_name' => 'Mozart',
            'driver' => \Remoment\MozartDriver\MusicPlayerDriver::class,
        ],
        'Beosound 2 3rd gen' => [
            'driver_name' => 'Mozart',
            'driver' => \Remoment\MozartDriver\MusicPlayerDriver::class,
        ],
        'Beosound A5' => [
            'driver_name' => 'Mozart',
            'driver' => \Remoment\MozartDriver\MusicPlayerDriver::class,
        ],
        'Beosound A9 5th gen' => [
            'driver_name' => 'Mozart',
            'driver' => \Remoment\MozartDriver\MusicPlayerDriver::class,
        ],
        'Beosound Balance' => [
            'driver_name' => 'Mozart',
            'driver' => \Remoment\MozartDriver\MusicPlayerDriver::class,
        ],
        'Beosound Emerge' => [
            'driver_name' => 'Mozart',
            'driver' => \Remoment\MozartDriver\MusicPlayerDriver::class,
        ],
        'Beosound Level' => [
            'driver_name' => 'Mozart',
            'driver' => \Remoment\MozartDriver\MusicPlayerDriver::class,
        ],
        'Beosound Premiere' => [
            'driver_name' => 'Mozart',
            'driver' => \Remoment\MozartDriver\MusicPlayerDriver::class,
        ],
        'Beosound Theatre' => [
            'driver_name' => 'Mozart',
            'driver' => \Remoment\MozartDriver\MusicPlayerDriver::class,
        ],
    ],
    'Spotify' => [
        'Spotify Connect' => [
            'driver_name' => 'Spotify',
            'driver' => \App\Integrations\Spotify\MusicPlayerDriver::class,
            'virtual' => true,
        ],
    ],
    'ReMoment' => [
        'Player' => [
            'driver_name' => 'ReMoment Player',
            'driver' => \App\Integrations\ReMoment\PlayerDriver::class,
            'virtual' => true,
        ],
    ],
    'Sonos' => [
        'Sonos Speaker' => [
            'driver_name' => 'Sonos',
            'driver' => \App\Integrations\Sonos\MusicPlayerDriver::class,
        ],
    ],
    // Any UPnP/DLNA MediaRenderer without a dedicated driver; discovered with its own manufacturer and model (see UpnpDiscovery).
    'UPnP' => [
        'Generic MediaRenderer' => [
            'driver_name' => 'UPnP',
            'driver' => \App\Integrations\Upnp\MusicPlayerDriver::class,
        ],
    ],
    // A Philips Android TV paired with `php artisan philips:pair` (see Philips\Pairing): the UPnP driver plus standby, apps and
    // the active app. Opt-in: an unpaired Philips TV stays a generic UPnP renderer, so this is not listed under a brand
    // that discovery would claim a Philips renderer for.
    'Philips TV' => [
        'Android TV' => [
            'driver_name' => 'Philips',
            'driver' => \App\Integrations\Philips\VideoPlayerDriver::class,
        ],
    ],
    // A Philips Streamium HiFi player (MCi900) enabled with `php artisan streamium:enable`: the UPnP driver plus its phone web
    // remote (standby, radio presets, the radio playing). Opt-in like the TV, so the brand and model here match no renderer.
    'Philips Streamium' => [
        'Wi-Fi Hi-Fi System' => [
            'driver_name' => 'Streamium',
            'driver' => \App\Integrations\Philips\Streamium\MusicPlayerDriver::class,
        ],
    ],
];
