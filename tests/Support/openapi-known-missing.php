<?php

/*
 * Routes under /api that docs/api/openapi.yaml does not describe yet, and documented operations that are not routes under /api.
 * Keys are "METHOD /path" with every {parameter} written as {}. tests/Feature/ApiContractTest.php lets these lists only shrink:
 * it fails when an entry becomes documented (or routed), telling you to remove it, and when a new undocumented route appears.
 */

return [
    'missing' => [
        'GET /devices/{}/bluetooth',
        'PUT /devices/{}/bluetooth',
        'DELETE /devices/{}/bluetooth/devices/{}',
        'GET /devices/{}/controls',
        'POST /devices/{}/controls/{}',
        'GET /devices/{}/info',
        'PUT /devices/{}/info',
        'GET /devices/{}/sound-adjustment',
        'PUT /devices/{}/sound-adjustment',
        'GET /devices/{}/wired-speakers',
        'PUT /devices/{}/wired-speakers/{}',
        'GET /devices/{}/wireless-speakers',
        'POST /devices/{}/wireless-speakers/scan',
        'POST /librespot/event',
    ],

    'stale' => [
        // Documented under the /api server, but served outside /api (StreamController, route /stream/{track}).
        'GET /stream/{}',
    ],
];
