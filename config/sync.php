<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sync groups ("fake multiroom")
    |--------------------------------------------------------------------------
    |
    | Devices of a sync group follow their leader: same queue, same state, and a
    | progress kept close by seeking a follower that drifts. Positions are whole
    | seconds, as the devices report them, so a tighter threshold than 2 would
    | seek at every report. See docs/architecture/sync-groups.md.
    */

    // Seconds a follower may be off before it is seeked back.
    'drift_threshold' => (int) env('SYNC_DRIFT_THRESHOLD', 2),

    // Seconds between drift checks of one group (reads only the cache).
    'check_interval' => (int) env('SYNC_CHECK_INTERVAL', 3),

    // After a seek, seconds a follower is left alone: its reported position lags behind.
    'seek_cooldown' => (int) env('SYNC_SEEK_COOLDOWN', 6),

    // Seconds after the leader changed track before followers that didn't follow by themselves are restarted.
    'track_change_delay' => (int) env('SYNC_TRACK_CHANGE_DELAY', 4),

];
