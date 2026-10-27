<?php

return [
    // Where backup:run writes its archives. In production the app directory is bind-mounted, so the default
    // survives a container recreate; copy the archives off the machine (see docs/architecture/backup-restore.md).
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    // How many archives the nightly run keeps (only files this command made, by name).
    'keep' => (int) env('BACKUP_KEEP', 7),

    // The health page warns when the newest archive is older than this many hours.
    'stale_hours' => 48,

    // .env keys that may be written to the archive as an informational env-safe.txt. Nothing else is ever
    // copied from .env: no APP_KEY, no passwords, no API secrets.
    'safe_env' => [
        'APP_NAME', 'APP_URL', 'APP_VERSION', 'APP_TIMEZONE', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE',
        'MQTT_PUBLIC_HOST', 'MQTT_PUBLIC_WS_PORT', 'MQTT_WS_URL', 'MUSIC_DIR', 'LIBRARY_DIR', 'QUEUE_WORKERS',
        'ANALYSIS_ENABLED', 'DEVICE_AUTO_DISCOVER', 'LIBRESPOT_ENABLED', 'BACKUP_PATH', 'BACKUP_KEEP',
    ],
];
