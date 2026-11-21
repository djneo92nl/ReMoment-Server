<?php

use App\Integrations\Jellyfin\JellyfinDriver;

/*
 * The registry of media-server types (workstream 15, docs/architecture/media-servers.md), like config/devices.php for
 * devices. A type is one driver class plus a declaration; a server is a row of `media_servers` with that type.
 * Read it through App\Domain\MediaServers\MediaServers, never by iterating this array.
 */
return [
    'drivers' => [
        'jellyfin' => [
            'label' => 'Jellyfin',
            // MediaServerDriverInterface (+ the capability interfaces it supports).
            'driver' => JellyfinDriver::class,
            // The ServiceCredentials declaration the key is read from (docs/architecture/credentials.md).
            'credentials' => 'jellyfin',
            // The ExternalServices probe that tests the server (Settings › Health).
            'probe' => 'jellyfin',
            'port' => 8096,
            // The client app on a TV whose now playing this server reports (an Android TV package name).
            'client_packages' => ['org.jellyfin.androidtv'],
            'icon' => 'fa-solid fa-clapperboard',
        ],
    ],
];
