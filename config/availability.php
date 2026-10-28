<?php

/*
 * Providers a library item can be browsed, played and added from (docs/architecture/availability-layer.md). The key is the
 * provider id and the id of its entry in App\Domain\Library\LibrarySources::LIBRARIES; the class implements
 * App\Domain\Availability\BrowsesExternalCatalogue. A new service (Deezer, Tidal, a media server) is a class here
 * plus its LIBRARIES entry; the API, MCP tool and web search need no change.
 */
return [
    /*
     * Every provider an album can be had from, and whether that is owned (counts for `owned`, closes a wish) or streamable
     * (App\Domain\Availability\Providers). A provider whose id is also a key of LibrarySources::LIBRARIES is that library
     * (same id, no mapping needed); `physical` and future media servers are not libraries (a label unless a device link exists).
     * A test asserts that every library has a provider here.
     */
    'providers' => [
        'files' => ['ownership' => 'owned'],
        'dlna' => ['ownership' => 'owned'],
        'spotify' => ['ownership' => 'streamable'],
        'physical' => ['ownership' => 'owned'],
    ],

    'browse' => [
        'spotify' => App\Domain\Availability\SpotifyBrowser::class,
    ],
];
