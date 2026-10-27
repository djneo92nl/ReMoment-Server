<?php

// Thresholds of the alert checks (docs/architecture/alerts.md). Minutes unless noted.
return [
    // A device whose state is `unreachable` this long raises device.offline (standby never does).
    'device_offline_minutes' => (int) env('ALERTS_DEVICE_OFFLINE_MINUTES', 10),

    // A device that should have a listener but has no listener heartbeat this long raises listener.down.
    'listener_down_minutes' => (int) env('ALERTS_LISTENER_DOWN_MINUTES', 3),

    // A device or listener alert only resolves after the condition has been gone this long, so a flapping one
    // does not raise and resolve in a loop.
    'resolve_after_minutes' => (int) env('ALERTS_RESOLVE_AFTER_MINUTES', 2),

    // The scheduler heartbeat is older than this (seconds): scheduler.silent. Same limit as the health page.
    'scheduler_silent_seconds' => 180,

    // Storage locations are probed at most this often (an S3 probe is a network call).
    'storage_check_minutes' => 5,
];
