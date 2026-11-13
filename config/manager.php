<?php

// The ReMoment Manager: a host service next to Docker (manager/, docs/architecture/manager.md). The app only talks to
// it from Settings › Manager; the manager works without the app. In production the app runs on the host network, so
// the manager is on 127.0.0.1, and its token file comes in through the read-only mount of the manager's home
// (docker-compose.prod.yml). On a dev machine without a manager the page says so.
return [
    'url' => env('MANAGER_URL', 'http://127.0.0.1:8099'),

    // The token file the manager created (mode 0600, owned by the deploy user, who is the app user too).
    'token_file' => env('MANAGER_TOKEN_FILE', '/run/remoment-manager/token'),

    // Alternative to the file, for a manager reached some other way. The file wins when it exists.
    'token' => env('MANAGER_TOKEN'),

    'timeout' => (int) env('MANAGER_TIMEOUT', 8),
];
