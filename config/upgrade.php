<?php

return [
    // Free space `app:upgrade` wants in the backup directory on top of the database size (MB). The backup is
    // compressed, so this is generous on purpose.
    'disk_margin_mb' => (int) env('UPGRADE_DISK_MARGIN_MB', 100),

    // Run after a successful migration, in order. The deploy (ansible/deploy.yml) runs `optimize`, so this does too.
    'after_migrate' => ['optimize'],

    // Commands of `after_migrate` that only run in these environments. `optimize` caches config, events and routes
    // into bootstrap/cache; on a dev machine or in tests that freezes the current config (and breaks later test runs),
    // so it is skipped there and the step shows as `skipped`.
    'only_in' => [
        'optimize' => ['production'],
    ],

    // Seconds between health polls after an upgrade.
    'health_interval' => (int) env('UPGRADE_HEALTH_INTERVAL', 5),
];
