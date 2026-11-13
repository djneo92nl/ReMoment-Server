<?php

/*
 * Routes under /api that docs/api/openapi.yaml does not describe yet, and documented operations that are not routes under /api.
 * Keys are "METHOD /path" with every {parameter} written as {}. tests/Feature/ApiContractTest.php lets these lists only shrink:
 * it fails when an entry becomes documented (or routed), telling you to remove it, and when a new undocumented route appears.
 */

return [
    'missing' => [
        'POST /admin/files',
        'DELETE /admin/files/{}',
        'POST /admin/files/{}/move',
        'PUT /admin/files/{}/tags',
        'POST /admin/files/{}/verify',
        'POST /admin/storage/import',
        'GET /admin/storage/locations',
        'POST /admin/storage/locations',
        'DELETE /admin/storage/locations/{}',
        'PUT /admin/storage/locations/{}',
        'POST /admin/storage/locations/{}/scan',
        'POST /admin/storage/locations/{}/test',
        'POST /admin/storage/reorganize',
        'DELETE /admin/tracks/{}/files',
        'GET /admin/tracks/{}/files',
        'GET /devices/{}/bluetooth',
        'PUT /devices/{}/bluetooth',
        'DELETE /devices/{}/bluetooth/devices/{}',
        'GET /devices/{}/controls',
        'POST /devices/{}/controls/{}',
        'GET /devices/{}/info',
        'PUT /devices/{}/info',
        'GET /devices/{}/network',
        'PUT /devices/{}/network/interface',
        'PUT /devices/{}/network/wifi',
        'PUT /devices/{}/network/wired',
        'GET /devices/{}/sound-adjustment',
        'PUT /devices/{}/sound-adjustment',
        'GET /devices/{}/wired-speakers',
        'PUT /devices/{}/wired-speakers/{}',
        'GET /devices/{}/wireless-speakers',
        'POST /devices/{}/wireless-speakers/scan',
        'POST /librespot/event',
        'GET /radio/{}/now',
    ],

    'stale' => [
        // Documented under the /api server, but served outside /api (StreamController, route /stream/{track}).
        'GET /stream/{}',
    ],
];
