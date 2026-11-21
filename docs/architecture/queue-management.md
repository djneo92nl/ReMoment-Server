# Queue management and stability (workstream 1b)

The queue runners keep running without attention, and the admin can see and steer what is in them: per-queue health, pause and resume, cancel and run-first of waiting jobs, a stuck-queue alert that tells a slow backlog from a stopped worker, a review of worker limits and job timeouts that a test keeps true, and a soak script for the owner's 24-hour run. REST: [admin-queues.md](../api/admin-queues.md); MCP tool `queues`; web: the Queue tab of `/settings/health` (`Livewire\QueueManager`).

Code: `App\Domain\Health\` `QueueInspector` (health rows, lag), `QueueAdmin` (rows and actions as one set of answers for web, REST and MCP), `QueueControl` (cancel, run first), `QueuePause`, `QueueProgress`, `QueueWorkers`, `Queues` (names, ceilings, thresholds), `Store\` (`QueueStore` with `RedisQueueStore` and `DatabaseQueueStore`), `App\Queue\` (the pausable queue classes), `config/queues.php`.

## The queues

| Queue | Worker program (`docker/8.4/supervisord*.conf`) | Runs | `--timeout` | Profiles |
|---|---|---|---|---|
| `health`, `default` | `queue` (`QUEUE_WORKERS` processes) | the heartbeat, artwork, enrichment, scrobbles, scenes, listeners' follow-up jobs | 420 s (`RunScene`, a scene with delays, is the longest) | all |
| `history` | `history-queue` (one process, never scaled: the single SQLite writer) | play history | 60 s | Control |
| `storage` | `storage-queue` | scans and imports (`RunStorageTask`) | 3600 s | Standard, Full |
| `heavy`, `catalogue` | `heavy-queue` | exports (`EXPORT_TIMEOUT`, 4 h), SD-card export, uploads, proxy transcodes; MusicBrainz discography syncs | 14400 s | Standard, Full |
| `analysis` | `analysis-queue` (`nice`) | audio analysis, deep analysis `sections` | 900 s | Full |
| `stems` | `stems-queue` (`nice`, one at a time) | deep analysis `stems`, `stem_audio` | 3300 s | Full |

`config/queues.php` `workers` is the shell-free copy of that table; supervisor cannot read the app config, so `tests/Unit/QueueWorkerConfigTest.php` parses both conf files and fails when they differ. `Queues::maxRuntime()` (the age after which a reserved job counts as a hung worker) is the worker's `--timeout` plus 100 s, read from the same table.

## Per-queue health

`QueueInspector::queues()` (Redis and database drivers; null for the others) gives each queue of the profile:

| Field | Meaning |
|---|---|
| `waiting`, `delayed`, `running` | ready jobs, retries and limiter releases, jobs a worker has reserved |
| `oldest_age` / `oldest_waiting` | age of the oldest waiting job |
| `state` | `ok` (heartbeat ran in 3 min), `busy` (none, but a job runs within its allowed time), `stuck` (worker down or hung), `paused`, `unknown` (no heartbeat is dispatched for the queue: `default`, `catalogue`, `history` outside Control) |
| `workers`, `worker` | `queue:work` processes listening on the queue, counted from `/proc` (`QueueWorkers`; the workers run in the app container next to the web process); `null`/`unknown` where `/proc` can't be read, never guessed as absent |
| `failed_24h` | failed jobs of the queue in the last 24 hours (one grouped query) |
| `last_progress` | when a job of the queue last finished (processed or failed): `QueueProgress`, a cache write by a listener on `JobProcessed` and `JobFailed` |
| `lag_after`, `lagging` | the queue's threshold and whether it is exceeded, below |

### Lagging, and why age alone is not enough

The oldest waiting job of `analysis` or of the enrichment backlog on `default` is hours old for as long as the backlog drains, so an age threshold alone would alert all night. A queue is **lagging** only when its oldest waiting job is older than the queue's threshold (`config/queues.php` `lag_after`, env `QUEUE_LAG_{QUEUE}` in seconds; defaults 300 `health`, 900 `default`, 3600 `analysis`, 4000 `storage`, 15000 `heavy`/`catalogue`, 7200 `stems`) **and** nothing moves: no job finished within the threshold and no job runs within its allowed time. A paused queue never lags.

### The alert

No new alert kind: `AlertChecks::queues` raises `queue.stuck` per queue from two signals of one condition, and `data.reason` says which. Runner state `stuck` is **critical** (`reason: heartbeat`, as before); a lagging queue is a **warning** (`reason: lag`, with `oldest_age`). `ok` or `paused` resolves; `busy`/`unknown` leave a heartbeat alert alone but end a lag alert as soon as the queue stops lagging. A queue with a heartbeat of its own (`health`, `storage`, `heavy`, `analysis`, `stems`) shows progress every minute while its worker lives, so for those the heartbeat state is the signal; the lag signal is what covers `default`, `catalogue` and `history`, which have none.

## Pause and resume

`QueuePause` keeps the list in the setting `queues_paused` (survives a restart and a cache flush) with a cache copy. The framework's queue classes are replaced by `App\Queue\PausableRedisQueue` and `PausableDatabaseQueue` (registered as the `redis` and `database` connectors in `AppServiceProvider`): `pop($queue)` returns nothing for a paused queue, before touching Redis or the table. Every supervised worker reads the list on each poll (3 to 10 s, `--sleep`), so a pause or resume takes effect without restarting a worker, per queue even where one worker serves two (`heavy,catalogue`).

- **A running job finishes.** Pausing stops new work; it never interrupts one. Waiting and delayed jobs stay where they are and run after a resume.
- **`health` cannot be paused**: the watchdog restarts the default workers when its heartbeat stops (`config('queues.unpausable')`). Pausing `default` leaves the heartbeat alone.
- A paused queue has state `paused` (its heartbeat waits too, which is not a stuck runner), raises no alert and resolves an active one.
- A pause list that can't be read (database down) pauses nothing: a worker never stops because of it.
- The pause is a setting of this install; it is not in the backup's secrets and survives `app:upgrade`.

## Cancel and run first

Both act on **waiting and delayed** jobs only; a job a worker has reserved is never listed, moved or removed. `QueueHeartbeat` jobs are never cancelled or moved.

- **Cancel** (`QueueControl::cancel($queue, $class = null)`): removes the waiting and delayed jobs of a queue, or of one class (short or full name), and answers `{ cancelled, skipped_running }`; `skipped_running` counts jobs a worker took between the scan and the removal (Redis `LREM` and `ZREM`, a database delete guarded by `reserved_at is null`, so each job is removed or run, never both). A cancelled `ShouldBeUnique` job gives its unique lock back (the job is restored from its payload; a job whose model is gone leaves the lock to expire by `uniqueFor`), otherwise the same work could not be queued again, for the heartbeat's two hours or an export's four. At most `config('queues.scan_limit')` (20000) rows are scanned per call.
- **Run first** (`prioritise($queue, $class)`): moves the waiting jobs of a class to the head of their queue in their order. Redis only: a worker takes from the head of the list (`LPOP`), so removing and pushing to the front is the whole mechanism. The **database driver cannot reorder** (it takes rows in id order): `prioritise` is refused (`422 unsupported`, the Run first button is not shown) and the way to run one thing first there is to pause the other queues, which works per queue, not per class. Moving a class to *another* queue is not offered: a job's queue is part of what its worker's `--timeout` and memory limits were chosen for.

The web page confirms each of these (`wire:confirm`); the API and MCP do not ask, they are the admin's explicit call.

## Review of the configuration (what was found and fixed)

Read from the repo, not from a running install. Rules the test now keeps (`QueueWorkerConfigTest`): every `queue:work` program of the production file states `--timeout`, `--memory`, `--max-time` and `--max-jobs`; `stopwaitsecs` is at least `min(timeout + 60, 3660)`; no job class with a `$timeout` (all of them are listed in the test, a new one fails it until it is added) outlives the `--timeout` of the worker of its queue; a job that outlives Redis `retry_after` (3700 s) runs on a queue with a single worker process; `retry_after` covers the default and storage workers.

| Finding | Fix |
|---|---|
| The heavy worker had `--timeout=900`, but `ExportLibrarySelection` has `EXPORT_TIMEOUT` 14400 (a job's own `$timeout` wins in Laravel). `Queues::maxRuntime('heavy')` was 1000 s, so **every export running longer than about 17 minutes made the heavy queue `stuck` and raised a critical `queue.stuck`**. | `--timeout=14400`; `maxRuntime` now comes from the worker table. `stopwaitsecs` 3660: a stop never waits more than an hour (`max_stop_wait`); a cut-off export cleans up in its `failed()` hook. |
| The default workers had no `--timeout` (Laravel's 60 s) while `RunScene` (420 s), `ProcessArtwork` (120 s) and `SendAlertPush` (120 s) set longer ones; `maxRuntime('default')` assumed 900 s. | `--timeout=420`, `stopwaitsecs` 480. |
| `history-queue` had no `--timeout`; its stop wait equalled the 60 s default. | `--timeout=60`, `stopwaitsecs` 120. |
| `storage-queue` had no `--max-jobs`. | `--max-jobs=100`. |
| Export (4 h) is longer than Redis `retry_after` (3700 s): a second worker would be handed the job once the time is up. Safe only because `heavy` has one process and a busy worker takes no new job. | Not changed; encoded: the test fails if a job that outlives `retry_after` shares a queue with several workers, and `heavy-queue` must not get `numprocs`. |

Worker memory is `--memory=128` everywhere; that measures the PHP process only, so ffmpeg and the stems service (`nice`d children and a sidecar) are outside it. Whether 128 MB is right on a Pi 2 (1 GB) with `QUEUE_WORKERS` default is a measurement for the soak, not a number to change here.

Failure behaviour (the review's item 3) is **not** part of this change: which job classes retry forever, what a failed job leaves behind and `failed_jobs` growth still need a read of each job class. The failed-job list shows the class and exception; the payload's entity (the track or file) is not shown yet.

## The 24-hour soak (the owner's)

`scripts/queue-soak/run.sh` (never run; nothing in this change ran it): for the chosen time it queues a mixed load of no-op jobs on every queue the profile runs except `health` and `history` (`App\Jobs\Soak\SoakNoop`: 70 % instant, 15 % a short wait, 10 % holding 4 to 24 MB, 5 % failing on purpose; they touch no library data) through `php artisan queue:soak enqueue`, appends one sample per round (queue health and the resident memory of every `queue:work` process, `queue:soak sample`), and writes `report.md` (`queue:soak report`): peak waiting and oldest waiting age per queue, failed jobs, samples lagging or stuck, worker memory first/peak/last and restarts (new pids), and a verdict. The report code is `App\Domain\Health\Soak\QueueSoak`, tested; the loop is a thin shell script.

```
sh scripts/queue-soak/run.sh --hours 24 [--per-queue 20] [--every 60]      # the gate, against the running compose stack
sh scripts/queue-soak/run.sh --minutes 5                                   # smoke run
sh scripts/queue-soak/run.sh --dry-run                                     # plan only
```

Run it **next to the SQLite stress test** of [hardware-check.md](hardware-check.md) (same 24 hours, real listeners and a library scan running meanwhile). Read the report for: any queue lagging or stuck; a worker above its `--memory` limit (160 MB is flagged; a worker restarted by `--max-time`/`--max-jobs`/`--memory` shows as a new pid, which is healthy); failed jobs beyond the 5 % that fail on purpose. "Done when" of 1b needs this run on the target hardware.

## Unverified

- Everything with Redis was tested against an in-memory Redis (`tests/Support/FakeRedisConnection`) and the database driver, not a running Redis: the Lua-free `LREM`/`LPUSH` run-first, `ZREM` of delayed jobs, and the pausable connector under a real `queue:work` loop are unverified on a live stack. phpredis's `lrem` argument order is handled by Laravel's connection class, which the store calls.
- Pause takes effect on each worker's next poll: verified by calling `pop`, not by a supervised worker.
- Worker presence from `/proc` assumes the web process and the workers share a container (the compose and Ansible layouts); behind any other layout it reports `unknown`.
- The soak script and its thresholds (the 160 MB flag, the lag defaults) have not been run.
