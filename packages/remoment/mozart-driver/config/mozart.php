<?php

return [
    'rest_port' => env('MOZART_REST_PORT', 8080),

    // UNVERIFIED — the Mozart OpenAPI spec documents no WebSocket connection
    // info at all (no servers:, no port, no path). This default is based on
    // external/community knowledge of B&O's official client libraries and
    // needs confirming against real hardware.
    'ws_port' => env('MOZART_WS_PORT', 9000),

    // Mozart has no continuous volume increment/decrement endpoint (unlike
    // ASE) — incrementVolume()/decrementVolume() step by this amount.
    'volume_step' => env('MOZART_VOLUME_STEP', 2),
];
