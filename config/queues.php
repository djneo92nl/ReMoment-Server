<?php

/*
 * Queue management and health (docs/architecture/queue-management.md). `config/queue.php` is Laravel's own file (drivers);
 * this one holds what the supervised workers are told and the per-queue thresholds of the health view and its alert.
 */
return [

    /*
    | The supervised worker programs of docker/8.4/supervisord*.conf: the queues each one serves and the ceilings the
    | program is started with. Supervisor cannot read the app config, so tests/Unit/QueueWorkerConfigTest.php parses the
    | conf files and fails when this table and the commands differ. `timeout` is the worker's `--timeout`: no job on its
    | queues may run longer (a job's own `$timeout` wins over it in Laravel, so the test checks every job class against it).
    | `processes` is the program's `numprocs` (QUEUE_WORKERS for the default workers, shown here as the minimum).
    */
    'workers' => [
        'queue' => ['queues' => ['health', 'default'], 'timeout' => 420, 'stop_wait' => 480, 'processes' => 1],
        'history-queue' => ['queues' => ['history'], 'timeout' => 60, 'stop_wait' => 120, 'processes' => 1],
        'storage-queue' => ['queues' => ['storage'], 'timeout' => 3600, 'stop_wait' => 3660, 'processes' => 1],
        'heavy-queue' => ['queues' => ['heavy', 'catalogue'], 'timeout' => 14400, 'stop_wait' => 3660, 'processes' => 1],
        'analysis-queue' => ['queues' => ['analysis'], 'timeout' => 900, 'stop_wait' => 960, 'processes' => 1],
        'stems-queue' => ['queues' => ['stems'], 'timeout' => 3300, 'stop_wait' => 3360, 'processes' => 1],
    ],

    /*
    | Supervisor never waits longer than this (seconds) for a worker to stop: a job with a longer timeout (an export of
    | hours) is cut off by a restart and cleans up in its `failed()` hook instead of holding up an upgrade.
    */
    'max_stop_wait' => 3660,

    /*
    | Seconds the oldest waiting job of a queue may wait before the queue counts as lagging, per queue. A queue that
    | is lagging raises `queue.stuck` only when it also made no progress in that time (no job finished and none
    | running within its allowed runtime): a long backlog that drains (analysis, enrichment) is waiting, not stuck.
    | Env: QUEUE_LAG_{QUEUE} in seconds, e.g. QUEUE_LAG_ANALYSIS.
    */
    'lag_after' => [
        'health' => (int) env('QUEUE_LAG_HEALTH', 300),
        'default' => (int) env('QUEUE_LAG_DEFAULT', 900),
        'history' => (int) env('QUEUE_LAG_HISTORY', 900),
        'storage' => (int) env('QUEUE_LAG_STORAGE', 4000),
        'heavy' => (int) env('QUEUE_LAG_HEAVY', 15000),
        'catalogue' => (int) env('QUEUE_LAG_CATALOGUE', 15000),
        'analysis' => (int) env('QUEUE_LAG_ANALYSIS', 3600),
        'stems' => (int) env('QUEUE_LAG_STEMS', 7200),
    ],

    /** Threshold of a queue that is not listed above (a queue name set by an env var). */
    'default_lag_after' => (int) env('QUEUE_LAG_DEFAULT_OTHER', 1800),

    /** Queues that cannot be paused: the heartbeat the watchdog restarts workers on lives on `health`. */
    'unpausable' => ['health'],

    /** Pending rows read at most when cancelling or reordering one queue's jobs, so a huge backlog can't hold a request. */
    'scan_limit' => 20000,
];
