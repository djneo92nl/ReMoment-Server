<?php

return [
    'discoverers' => [
        \App\Integrations\BangOlufsen\Ase\AseDiscovery::class,
        \App\Integrations\Sonos\SonosDiscovery::class,
        \Remoment\MozartDriver\MozartDiscovery::class,
    ],

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
    'Sonos' => [
        'Sonos Speaker' => [
            'driver_name' => 'Sonos',
            'driver' => \App\Integrations\Sonos\MusicPlayerDriver::class,
        ],
    ],
];
