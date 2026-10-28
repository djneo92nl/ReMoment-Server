<?php

/*
 * Providers a library item can be browsed, played and added from (docs/architecture/availability-layer.md). The key is the
 * provider id and the id of its entry in App\Domain\Library\LibrarySources::LIBRARIES; the class implements
 * App\Domain\Availability\BrowsesExternalCatalogue. A new service (Deezer, Tidal, a media server) is a class here
 * plus its LIBRARIES entry; the API, MCP tool and web search need no change.
 */
return [
    'browse' => [
        'spotify' => App\Domain\Availability\SpotifyBrowser::class,
    ],
];
