# Queue stability review

Date: 2026-10-27. Review part of workstream 1b in [v1-roadmap.md](v1-roadmap.md), read from the repository only: no running install, no soak. Line numbers refer to the tree at commit `2e1b1f7d` (the fixes below).

## Queues and workers

All programs are in `docker/8.4/supervisord.prod.conf`; Redis is the queue (`config/queue.php:68-76`, `retry_after` 3700 s via `REDIS_QUEUE_RETRY_AFTER`; the database variant is 1800 s, `:44`, used by tests/dev only).

| Queue | Workers | Flags (after the fix) | `stopwaitsecs` | Jobs on it | Longest job `$timeout` | Risks |
|---|---|---|---|---|---|---|
| `health`, `default` | `QUEUE_WORKERS`: Ansible 2 (`ansible/group_vars/all.yml:71`), compose default 1 (`docker-compose.prod.yml:18`) | `--tries=3 --sleep=3 --memory=128 --max-time=3600 --max-jobs=1000`; no `--timeout` (60 s default, a job's own `$timeout` overrides it) | 960 (was 10) | `QueueHeartbeat` (health, polled first); every enrichment/import job, `ProcessArtwork`, Last.fm, `PollRadioTitle`, `SyncFollower`, `FetchSpotifyArtistImages`, `ProcessLibraryUpload`, `BuildSdCardExport`, `TranscodeProxy` (`PROXIES_QUEUE` default `default`) | 900 (`BuildSdCardExport`) | Long jobs share workers with the heartbeat (finding 2). Jobs without `$timeout` get 60 s (finding 6). |
| `storage` | 1 | `--tries=1 --timeout=3600 --memory=128 --max-time=3600` | 3660 (was 10); Docker `stop_grace_period` 3700 s | `RunStorageTask` (scan, import, reorganize) | 3600 | Killed mid-task leaves cache state `running` and a unique lock (fixed, finding 1). |
| `analysis` | 1, `nice -n 15` | `--tries=1 --timeout=900 --memory=128 --max-time=3600 --max-jobs=200` | 960 (was 10) | `AnalyzeAudioFile` (`ANALYSIS_TIMEOUT` 900) | 900 | A killed job loses its stage results; the nightly `library:analyze` retries them. |
| scheduler | 1 | `schedule:work` | 10 | closures: heartbeat, watchdog | | |
| listeners | 1 | `device:listen` | 10 | | | not a queue |

## Answers to the six questions

**1. Worker flags and RAM.** Before this review no worker had `--memory`, `--max-jobs` or a `stopwaitsecs` (supervisor's default is 10 s, so every stop or deploy SIGKILLed the worker whatever it ran: a scan of up to 3600 s). `--memory` now says explicitly what Laravel defaulted to (128 MB; the worker exits cleanly between jobs and supervisor restarts it); `--max-jobs` bounds a leak in the two queues with many short jobs; `--max-time=3600` was already there. `--tries=3` on `default` is only a fallback: enrichment and Spotify import jobs set `$tries = 0` (`app/Jobs/Concerns/EnrichesFromSource.php:21`), so their limits are `maxExceptions 6` and `retryUntil` 12 h. RAM on a 1 GB board: three always-running workers (default ×`QUEUE_WORKERS`, storage, analysis) plus the scheduler and listeners are five-plus PHP processes at roughly 40-60 MB each (estimate, not measured), i.e. 250-350 MB before MariaDB, Redis, Meilisearch, Mosquitto, `artisan serve` (4 `PHP_CLI_SERVER_WORKERS`) and the analysis sidecar. Control/Standard profiles on a Pi 3 should run `QUEUE_WORKERS=1` (compose default; the Ansible default of 2 targets the Proxmox container with 4 GB). Not changed: a worker count is a guess without the soak.

**2. `retry_after` vs timeouts.** 3700 s is above the longest timeout on every queue (3600 s `RunStorageTask`, 900 s the rest), so no job is handed out twice while running. A worker `--timeout` below `retry_after` is asserted by `tests/Feature/QueueStabilityConfigTest.php`. Unique locks: `RunStorageTask` had none (`ShouldBeUnique` without `uniqueFor` = a lock that never expires; if the worker was killed, dispatching that task was silently dropped while the UI said `queued`); now 7200 s, the same 120 minutes after which `StorageTasks::isActive()` treats a task as lost (`StorageTasks.php:21`). `AnalyzeAudioFile` 3600 s (timeout 900), `TranscodeProxy` 900 s (timeout 600), enrichment jobs 86400 s (retry window 12 h): all above their timeouts. Note `AnalyzeAudioFile`'s 3600 s lock is shorter than a long analysis backlog's wait, so a duplicate can be queued; harmless (the stage check skips done work).

**3. Per job class** (`app/Jobs`):

| Job | Retry behaviour | On failure it leaves | Notes |
|---|---|---|---|
| `Enrich*` (12 classes), `ImportSpotifyAlbum(ById)` | `$tries=0`, `maxExceptions 6`, `retryUntil` 12 h, backoff 60-3600 s; `deleteWhenMissingModels`. Idempotent (`isDone`/`markDone`, `Enrichment::save`) | lock released by Laravel on failure; a row in `failed_jobs` | MusicBrainz (1/s), AudioDB (25/min), Discogs (50/min), LRCLIB (1/s) use `RateLimited` (`AppServiceProvider.php:56-61`); releases do not count as attempts because `$tries=0`. **No limiter for Spotify or Last.fm** jobs (finding 5). |
| `ProcessArtwork` | tries 3, backoff 60 | nothing partial; `failed_jobs` row (a dead cover URL fails after 3 downloads) | Idempotent; no `$timeout` (finding 6). |
| `FetchSpotifyArtistImages` | tries 3, backoff 60 | nothing | A Spotify 429 uses all three tries in about 2 minutes; the daily `library:enrich` re-queues. |
| `ScrobbleToLastfm`, `SendNowPlayingToLastfm` | tries 3, backoff 60 | nothing | A Last.fm outage over about 3 minutes loses the scrobble. |
| `RunStorageTask` | tries 1; `failed()` writes `state: failed` | cache state `running` if hard-killed (fixed by `stopwaitsecs`; `isActive` ages out after 120 min); a half-imported file is the importer's concern (not reviewed) | |
| `ProcessLibraryUpload` | tries 1; `failed()` marks the upload | staged file in `_uploads/` | |
| `AnalyzeAudioFile` | tries 1; each stage records its own error | `FileAnalysis` error per stage; hard kill leaves the activity marker (`AnalysisActivity`) until overwritten | |
| `TranscodeProxy` | tries 1; `failed()` settles the waiting players | partial proxy file (not reviewed) | |
| `BuildSdCardExport` | tries 1; `failed()` and `finally` clear the pending flag | `.zip.tmp` file | Hard kill skips the `finally`; the pending flag then relies on its own expiry. |
| `PollRadioTitle` | tries 1, timeout 20 | the chain (finding 4) | |
| `SyncFollower` | tries 1; catches everything and logs | nothing | |
| `ReportRadioClick` | tries 1, no `$timeout`; 60 s default | nothing | Best effort. |
| `QueueHeartbeat` | worker default | nothing | |
| `EnrichTrackMetadata` | worker default (3) | nothing | Legacy shim only. |

**4. Queue placement and starvation.** `QueueHeartbeat` is on `health`, which every default worker polls before `default`, so a backlog of thousands of enrichment jobs cannot delay it. What can: all default workers busy with a job that outlasts the watchdog's 5 minutes. `BuildSdCardExport` (900 s), `ProcessLibraryUpload` (600 s) and `TranscodeProxy` (600 s, default queue) are such jobs. With `QUEUE_WORKERS=1` the heartbeat goes stale, `QueueWatchdog` (`app/Domain/Health/QueueWatchdog.php`) SIGKILLs every `health,default` worker, which cannot tell a hung worker from a busy one, and the job is lost (this is the same failure `RunStorageTask`'s own queue was created for). With 2 workers both must be busy. Scheduler: `library:warm` (every 5 minutes) is an artisan command, not a job, so it cannot flood the queue; its `withoutOverlapping()` lock lasted 24 h, so a run killed by a restart (Redis survives in a volume) stopped the warm-up for a day: now 10 minutes (`routes/console.php`); `device:discover` 120 minutes. `PollRadioTitle`: one chain per station guarded by `Cache::add("radio:polling:{id}")` for 180 s (`StreamTitles.php:47`); a thrown exception ends the chain silently, but the next `touch()` after the lock lapses (at most 180 s) restarts it. It can multiply by one: if a 15 s delayed re-dispatch waits more than 180 s behind a default-queue backlog, the lock lapses, `touch()` starts a second chain, and both then continue until the listener stops (no exponential growth, no crash). Recommendation below.

**5. Heartbeats.** `health:scheduler` (`routes/console.php`, every minute) proves `schedule:work` ticks. `health:queue` proves that some `health,default` worker ran `QueueHeartbeat` in the last 3 minutes (`SystemHealth::STALE_SECONDS`); it says nothing about the `storage` or `analysis` workers, which the watchdog deliberately ignores. A stuck `storage` or `analysis` runner therefore looks healthy on `/settings/health`: the page shows `Queue::size()` (the `default` queue only) and the failed count, and the Queue tab shows pending jobs per class with an `oldest` time. That `oldest` is the original `pushedAt` over waiting, running and delayed jobs together (`QueueInspector.php:50-60`, `redisRows()`), and is per class, not per queue: it cannot say "nothing in `storage` has started for 40 minutes". Missing: a per-queue oldest-waiting age (head of `queues:{name}` list), and a per-queue worker heartbeat (a job per queue, or the worker's `JobProcessed` event). That is the "per-queue health" in the roadmap's done-criteria, not built here.

**6. `failed_jobs`.** `database-uuids` driver (`config/queue.php:136`), and nothing pruned it: rows grow with every dead cover URL, rate-limited-out job and killed worker. Fixed: `queue:prune-failed --hours=720` daily at 04:30. `QueueManager` lists the latest 25 (class, queue, first line of the exception, time) with retry, forget and clear (`QueueInspector::failed()`); it does not show which track/file the payload held.

## Findings by severity

1. **High, fixed.** Supervisor stopped every worker with the 10 s default, so `docker compose up -d`, `queue:restart` plus a stop, or a host reboot SIGKILLed a running storage scan/import, analysis or SD-card export. `supervisord.prod.conf:38,56,74`, `docker-compose.prod.yml` `stop_grace_period`. The unique lock of `RunStorageTask` never expiring made this worse (`RunStorageTask::$uniqueFor`).
2. **High, fixed (second commit).** `QueueWatchdog` returns `busy` and does not kill while the oldest reserved `default` job (Redis `queues:default:reserved`, score − `retry_after`) has run under 1000 s; past that the worker counts as hung. It used to kill busy workers: with `QUEUE_WORKERS=1` a default-queue job over 5 minutes (`TranscodeProxy`, `BuildSdCardExport`, `ProcessLibraryUpload`) gets the worker SIGKILLed. A separate `heavy` queue is still an option if the soak shows the heartbeat starving.
3. **Medium, fixed.** `library:warm` and `device:discover` `withoutOverlapping()` locks of 24 h (`routes/console.php`). `backup:run` and `library:merge-duplicates` still use the default; a killed run blocks only the next one for up to a day, and `backup:run` at 01:30 can race its own lock expiry at the same minute: consider `withoutOverlapping(120)`.
4. **Medium, fixed.** `failed_jobs` never pruned.
5. **Medium, fixed.** New `spotify` (3/s) and `lastfm` (4/s) limiters on the Spotify and Last.fm jobs; `FetchSpotifyArtistImages` now `$tries=0`, `maxExceptions 4`, 6 h; the two Last.fm send jobs 5 tries with backoff 60/300/900/3600. Was: no rate limiter for Spotify (`EnrichTrackSpotify`, `FetchSpotifyArtistImages`, `ImportSpotifyAlbum*`) or Last.fm jobs. A 429 throws, which counts toward `maxExceptions 6` (enrichment) or burns all 3 tries in 2 minutes (`FetchSpotifyArtistImages`, `ScrobbleToLastfm`, `SendNowPlayingToLastfm`, fixed `$backoff = 60`). Suggest a `RateLimited` limiter or `Retry-After` release, and a growing `backoff()` for the Last.fm jobs. Not changed: it alters retry policy.
6. **Medium, fixed (timeouts set: `ProcessArtwork` 120 s, `ReportRadioClick` 20 s; confirm 120 s in the soak).** They relied on the worker's 60 s default. Resizing a large cover into three backgrounds on a Pi 3 may exceed it (then killed, retried 3 times, failed). Measure in the soak and set `$timeout = 120` if it does.
7. **Low, fixed.** `PollRadioTitle` ends a duplicate chain (`radio:polled:{id}` lock of INTERVAL − 5 s).
8. **Low, fixed.** `QueueInspector` lists `PROXIES_QUEUE` too. `backup:run` and `library:merge-duplicates` overlap locks now expire (120/240 min).
10. **Medium, fixed.** The Queue tab has a per-queue table (waiting, running, delayed, oldest waiting age; Redis only; `QueueInspector::queues()`). A per-queue worker heartbeat and the `/api/info` alert are still not built (roadmap 1b).
9. **Info.** `QUEUE_WORKERS` default differs: 1 (compose) vs 2 (Ansible).

## What was fixed (commit `2e1b1f7d`, tests in `tests/Feature/QueueStabilityConfigTest.php`)

`stopwaitsecs` 960/3660/960 and Docker `stop_grace_period: 3700s`; `--memory=128` on all three workers, `--max-jobs` 1000 (default) and 200 (analysis); `queue:prune-failed` daily; expiring overlap locks for `library:warm` and `device:discover`; `RunStorageTask::$uniqueFor = 7200`. Not touched: the dev `supervisord.conf` (Sail), worker counts, any job's `$tries`/`$backoff`.

A deploy now waits for a running storage job to finish (up to an hour) before the container stops. If that is unwanted, shorten `stopwaitsecs` and accept the kill; the task is then marked lost after 120 minutes.

## Soak test plan (24 h, with the SQLite stress test of 1a)

Setup: a real Pi-class install (Control/Standard profile), real listeners, a library scan plus import of a large folder in the first hour, `ANALYSIS_ENABLED=true`, Spotify and Last.fm connected, a radio station playing (poll chain), `queue:work` counts as configured for the profile. Sample every 60 s into a CSV with a shell loop (script in `storage/soak/`, not committed):

| Measure | How | Pass if |
|---|---|---|
| Failed jobs per class | `SELECT queue, JSON_UNQUOTE(JSON_EXTRACT(payload,'$.displayName')) AS job, COUNT(*) FROM failed_jobs GROUP BY 1,2` hourly; at the end the first line of every `exception` | no unexplained class; `ProcessArtwork` failures all dead URLs |
| Worker restarts per queue | `supervisorctl status` (uptime per program) every minute; `docker logs` for `exited:`/`spawned:` lines; count `--max-time`/`--max-jobs` exits separately from unexpected ones | restarts only at the 1 h / job limits, none for the watchdog |
| RSS per worker | `ps -o pid,rss,args -C php` filtered by `queue:work`, plus `docker stats` for the container | each worker under 128 MB; total fits the profile's budget; no upward trend within one worker's life |
| Oldest waiting age per queue | Redis: `LINDEX queues:{health,default,storage,analysis} 0` → `pushedAt`, age = now − pushedAt; also `ZCARD queues:*:reserved` and `:delayed` | `health` under 10 s; `default` under a few minutes outside the first-scan backlog; `storage`/`analysis` explained by running job |
| Queue depth | `LLEN queues:*` per queue | drains after the scan backlog |
| Heartbeats | `redis-cli GET health:queue` and `health:scheduler` every minute | never older than 3 min; log every watchdog kill (`Queue worker did not run its heartbeat`) | 
| Errors in logs | grep the Laravel log and container log for `database is locked`, `MaxAttemptsExceeded`, `Connection refused`, `LOADING`, `OOM`, `Killed` | none, or each explained |
| Poll chains | count `queues:default*` entries of `PollRadioTitle` while one station plays | at most 1 in flight per station |
| Overlap and unique locks | `redis-cli KEYS '*framework/schedule*'` and `*unique*` during and after a forced `supervisorctl restart` | cleared or expiring |
| Forced stop | at hour 6 run `docker compose stop` during a scan | stops within `stopwaitsecs`, scan state `done` or resumable, next scan can be queued |

Record the three numbers the roadmap needs for the profiles: peak RSS sum, worker count that kept `health` under 10 s, and the time per file of `ProcessArtwork` (decides finding 6).

## Not verifiable without a running install

Real RSS per worker; whether `ProcessArtwork`/`TranscodeProxy` exceed their timeouts on a Pi 3; supervisor stop behaviour under Docker's grace period with `network_mode: host`; Redis behaviour of `RateLimited` under a large backlog; how often the watchdog kills in practice.
