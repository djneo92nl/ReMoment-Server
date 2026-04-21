<?php

return [
    'discoverers' => [
        \App\Integrations\BangOlufsen\Ase\AseDiscovery::class,
        \App\Integrations\Sonos\SonosDiscovery::class,
    ],

    'Bang & Olufsen' => [
        'BeoSound Essence' => [
            'driver_name' => 'ASE',
            'driver' => \App\Integrations\BangOlufsen\Ase\MusicPlayerDriver::class,
            'speaker' => 'external',
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
    ],
    'Bang &amp; Olufsen' => [
        'BeoSound Moment' => [
            'driver_name' => 'ASE',
            'driver' => \App\Integrations\BangOlufsen\Ase\MusicPlayerDriver::class,
            'speaker' => 'external',
            'wisa' => true,
        ],
    ],
    'Spotify' => [
        'Spotify Connect' => [
            'driver_name' => 'Spotify',
            'driver' => \App\Integrations\Spotify\MusicPlayerDriver::class,
            'virtual' => true,
        ],
    ],
];
