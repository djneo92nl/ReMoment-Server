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

    /*
     * Edition matching (App\Domain\Catalogue\EditionKey): a library album named like a release ("One By One Special Norwegian
     * Edition (82876 512002)") is matched to the MusicBrainz release-group "One By One". Catalogue only: Normalizer::album and
     * albums.name_key are never touched. Words are compared lower case without apostrophes.
     */
    'edition_matching' => [
        // Years the library's album year may differ from the release-group's first release for a loose match.
        'year_tolerance' => 2,

        // Words that are a pressing, not a different work. "Live", "Acoustic", "Remix", "Unplugged", "Demo", "Instrumental",
        // "Mono" and numbers ("Vol 2") must never be listed.
        'regions' => [
            'uk', 'us', 'usa', 'eu', 'eur', 'jp', 'jpn', 'japan', 'japanese', 'german', 'germany', 'european', 'europe', 'australian',
            'australia', 'canadian', 'canada', 'norwegian', 'norway', 'dutch', 'french', 'france', 'swedish', 'british', 'american',
            'italian', 'spanish', 'korean', 'brazilian', 'mexican', 'russian', 'asian', 'latin',
        ],
        'editions' => [
            'special', 'deluxe', 'expanded', 'remastered', 'remaster', 'anniversary', 'limited', 'collectors', 'collector', 'bonus',
            'digipak', 'digipack', 'import', 'reissue', 'promo', 'standard', 'enhanced', 'exclusive', 'edition', 'version', 'release',
            'retail', 'repress', 'original', 'international', 'tour',
        ],
        'retailers' => ['best_buy', 'target', 'walmart', 'amazon', 'itunes', 'hmv', 'fnac', 'media_markt'],
        'formats' => ['ep', 'cd', 'cdep', 'lp', 'vinyl', 'digital'],

        // Glue words that may sit between marker words ("Special and Limited Edition").
        'fillers' => ['edition', 'the', 'and', 'with', 'track', 'tracks', 'year'],

        // A trailing run of bare marker words (no brackets) is only stripped when it holds one of these, so "Something Special" stays whole.
        // A run in brackets or after " - " needs no anchor.
        'anchors' => ['edition', 'version', 'exclusive', 'import', 'ep', 'cdep', 'best_buy', 'walmart', 'amazon', 'itunes', 'hmv', 'fnac', 'media_markt', 'digipak', 'digipack', 'release'],
    ],
];
