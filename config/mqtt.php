<?php

return [
    'host' => env('MQTT_HOST', 'localhost'),
    'port' => (int) env('MQTT_PORT', 1883),
    'client_id' => env('MQTT_CLIENT_ID', 'remoment-server'),

    // WebSocket URL browsers use to subscribe for live UI updates.
    // Null = derive from the page's host as ws(s)://{host}:9001.
    'ws_url' => env('MQTT_WS_URL'),
];
