<?php

return [
    // Free space `app:upgrade` wants in the backup directory on top of the database size (MB). The backup is
    // compressed, so this is generous on purpose.
    'disk_margin_mb' => (int) env('UPGRADE_DISK_MARGIN_MB', 100),
];
