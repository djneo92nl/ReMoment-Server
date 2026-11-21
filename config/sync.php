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

    /*
    |--------------------------------------------------------------------------
    | Sync to offline targets (workstream 18.3): docs/architecture/offline-sync.md
    |--------------------------------------------------------------------------
    |
    | A target (a USB stick on a node, the iOS app's offline library) is kept filled with a rule-defined part of the
    | library. Transcodes are made once per fingerprint and shared by every target (`sync_artifacts`, the files under
    | `sync-artifacts/` on `disk`), in a cache that is capped and pruned oldest-used first.
    */

    /** Disk (private) of the artifact cache. A cache on the external library disk: point this disk's root there. */
    'disk' => env('SYNC_CACHE_DISK', env('EXPORT_DISK', 'local')),

    /** Most the artifact cache may hold, in GB; the least recently used go first. 0 = no cache, nothing is prepared. */
    'cache_gb' => (float) env('SYNC_CACHE_GB', 8),

    /** Artifacts of a plan made in the last this-many minutes are never pruned. */
    'protect_minutes' => (int) env('SYNC_PROTECT_MINUTES', 60),

    /** Most artifacts one target has queued or being made at once, so a 5,000-track first sync does not fill the cache. */
    'prepare_ahead' => (int) env('SYNC_PREPARE_AHEAD', 50),

    /** Hours a signed per-file download URL is valid (a download may be queued behind others; a re-plan mints fresh ones). */
    'url_hours' => (int) env('SYNC_URL_HOURS', 6),

    /** A plan that removes more than this percent of the owned entries, or more than `confirm_remove_files`, needs a yes. */
    'confirm_remove_percent' => (int) env('SYNC_CONFIRM_REMOVE_PERCENT', 30),
    'confirm_remove_files' => (int) env('SYNC_CONFIRM_REMOVE_FILES', 500),

    /** Default budget policy values (the owner decided these on 2026-11-20; unverified on a real library). */
    'reserve_percent' => 5,
    'max_churn_percent' => 25,

    /** Largest file a FAT32 volume can hold; a file above it is refused for a FAT32 target. */
    'fat32_max_bytes' => 4 * 1024 ** 3 - 1,

    /** Queue of the artifact job: the `heavy` worker, one at a time (Pi rule). */
    'queue' => env('SYNC_QUEUE', 'heavy'),

    /** Seconds of one artifact encode (a long lossless file on a Pi); the job's timeout is this plus a margin. */
    'artifact_timeout' => (int) env('SYNC_ARTIFACT_TIMEOUT', 900),

];
