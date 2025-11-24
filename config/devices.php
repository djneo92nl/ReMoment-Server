<?php

return [
    'Bang & Olufsen' => [
        'BeoSound Essence' => [
            'driver' => 'ASE',
            'type' => \App\DeviceDrivers\BangOlufsen\Ase\MusicPlayerDriver::class,
            'speaker' => 'external',
        ],
        'Beoplay M5' => [
            'driver' => 'ASE',
            'type' => \App\DeviceDrivers\BangOlufsen\Ase\MusicPlayerDriver::class,
            'speaker' => 'internal',
        ],
        'Beoplay M3' => [
            'driver' => 'ASE',
            'type' => \App\DeviceDrivers\BangOlufsen\Ase\MusicPlayerDriver::class,
            'speaker' => 'internal',
        ],
    ],
    'Bang &amp; Olufsen' => [
        'BeoSound Moment' => [
            'driver' => 'ASE',
            'type' => \App\DeviceDrivers\BangOlufsen\Ase\MusicPlayerDriver::class,
            'speaker' => 'external',
            'wisa' => true,
        ],
    ],
];
