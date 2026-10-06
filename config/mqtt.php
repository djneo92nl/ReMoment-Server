<?php

return [
    'host' => env('MQTT_HOST', 'localhost'),
    'port' => (int) env('MQTT_PORT', 1883),
    'client_id' => env('MQTT_CLIENT_ID', 'remoment-server'),

    // WebSocket URL browsers use to subscribe for live UI updates.
    // Null = derive from the page's host as ws(s)://{host}:9001.
    'ws_url' => env('MQTT_WS_URL'),

    // Broker address as client devices (ESP32 etc.) should reach it, returned
    // by GET /api/info. MQTT_HOST is usually a Docker service name ("mosquitto")
    // that only resolves inside the compose network. Null = the host the client
    // used for the HTTP request, since the broker port is published on the same machine.
    'public_host' => env('MQTT_PUBLIC_HOST') ?: null,
    'public_port' => (int) env('MQTT_PUBLIC_PORT', env('MQTT_PORT', 1883)),

    // WebSocket listener port as clients should reach it (GET /api/info `mqtt.ws_port`).
    'public_ws_port' => (int) env('MQTT_PUBLIC_WS_PORT', 9001),
];
