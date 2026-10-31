# Profiles: Control, Standard, Full

Workstream 1a of [v1-roadmap.md](v1-roadmap.md). One codebase runs on a Raspberry Pi 2/3 as a device controller and on a mini PC as the full media server. The user picks a **profile**; the stack starts only what the profile needs and answers clearly for what it lacks.

| Profile | For | Runs | Database |
|---|---|---|---|
| **Control** | Wall panels (ESP32), a few devices | Device listeners, REST API, MQTT, live web UI, radio, play history, alerts, backup | **SQLite** (named volume) |
| **Standard** | Control plus a library | Adds library browsing and scan, enrichment, artwork, DLNA, own-file indexing, catalogue sync | MariaDB |
| **Full** | Everything | Adds audio analysis and its sidecars (Essentia, structure, stems), library health reports, rip upload, optional Meilisearch | MariaDB |

Hardware per profile: [system-requirements.md](system-requirements.md).

## The switch

`PROFILE=control|standard|full` in `.env` is the single source of truth. **Default `standard`**: an install without `PROFILE` behaves as before profiles existed, with one exception: the audio analysis, structure and stems workers and schedule are *Full* (below), so an install that runs analysis sets `PROFILE=full` (the Ansible `profile: full` in `group_vars/all.yml` does that for the owner's host).

- `App\Domain\Profile\Profile` (`app/Domain/Profile/Profile.php`) reads it through `config/profile.php`: `current()`, `is('control')`, `allows('library')`, `featuresOf()`, `lowestFor()`, `workers()`. An unknown value falls back to `standard` and is logged once; an unknown *feature* name in code throws (a typo is a bug, not a "no").
- `config/profile.php` is the one gate table: the capability table, the URLs of each feature, the supervised workers, the compose profiles, the verify list of `profile:upgrade`.
- **Changing it.** Set `PROFILE` in `.env` (Ansible: `profile:` in `group_vars/all.yml`, then deploy) and restart the containers: the profile also decides which containers run (below), so a restart is needed. `/settings/profile` (admin, [setup wizard](setup-wizard.md) step) shows the three cards and stores the *choice* as the Setting `profile_chosen`; the environment wins, and when the two differ the page says what to change.
- `GET /api/info` reports `features.profile` ([server-info.md](../api/server-info.md)).

## Capability table

Kept in step with `config/profile.php` by `tests/Unit/ProfileDocsTest.php`.

<!-- capability-table:start -->
| Gate | What | Control | Standard | Full |
|---|---|---|---|---|
| `devices` | Devices, playback, sources, multiroom | yes | yes | yes |
| `history` | Play history and insights | yes | yes | yes |
| `radio` | Radio | yes | yes | yes |
| `alerts` | Alerts, health and backup | yes | yes | yes |
| `library` | Library browsing, playlists, library cache, artwork prerender, Spotify browse | – | yes | yes |
| `enrichment` | Metadata enrichment (MusicBrainz, Wikipedia, Last.fm, lyrics ...) | – | yes | yes |
| `dlna` | DLNA servers and scan | – | yes | yes |
| `file_storage` | Own files: storage locations, indexing, import, streaming to speakers | – | yes | yes |
| `catalogue` | Catalogue sync, wishlist, new releases, physical collection | – | yes | yes |
| `analysis` | Audio analysis (loudness, fingerprints, Essentia) and library health reports | – | – | yes |
| `deep_analysis` | Deep analysis of one track: sections (structure) and stems | – | – | yes |
| `rip_upload` | Rip upload from the ripper app | – | – | yes |
| `search_engine` | Meilisearch (optional, unused by the app today) | – | – | yes |
<!-- capability-table:end -->

Devices, playback, sources, multiroom, sync groups, history, insights, radio, alerts, health, backup and `/api/info` work in **every** profile (tested: `ProfileTest`).

## How each layer is gated

| Layer | How | Where |
|---|---|---|
| Scheduled tasks | `->when(fn () => Profile::allows('…'))` | `routes/console.php` |
| API and web URLs | One middleware, `EnforceProfile`, prepended to the `web` and `api` groups (before route model binding), matching the URL patterns of each feature in `config('profile.routes')`; the first matching feature wins, so narrow rows precede `library`. A URL no row matches is open in every profile. | `app/Http/Middleware/EnforceProfile.php` |
| API answer | `422 { "error": "not_available_in_profile", "message", "profile", "feature", "required_profile" }`; the message names the profile ("… is not part of the Control profile; it needs the Standard or Full profile.") | same |
| Web answer | A redirect to `/not-in-profile/{feature}`: a short notice, not an error | `routes/profile.php`, `resources/views/profile/unavailable.blade.php` |
| Navigation | The sidebar hides Library (Radio becomes its own entry in Control); Settings hides Music library, Audio analysis, Library Health, DLNA and the SD-card export | `nav-list.blade.php`, `settings/index.blade.php`, `settings/clients.blade.php` |
| Supervised programs | `autostart=%(ENV_START_…)s`; `docker/8.4/profile-env.sh` (sourced by `start-container`) turns `PROFILE` into the `START_*` variables | `docker/8.4/supervisord*.conf` |
| Queue heartbeats and alerts | `Queues::heartbeat()` lists only queues with a worker in the profile; the storage alert check is off without own files | `Domain/Health/Queues.php`, `AlertChecks` |
| Deep analysis in `/api/info` | `DeepAnalysis::availableKinds()` is empty without the feature (no probe of a sidecar that is not there) | `Domain/Analysis/DeepAnalysis.php` |
| MCP | Tools go through `ApiBridge` (an in-process API call), so a gated endpoint answers the same 422 | `app/Mcp` |

Nothing is deleted: the code paths stay, only the entry points are gated. Livewire components of a gated page are only reachable from that page.

### Scheduled tasks

| Task | Control | Standard | Full |
|---|---|---|---|
| `device:sync-sources`, `device:discover`, `radio:refresh`, `radio:fix-artwork`, radio plays prune, `backup:run`, `queue:prune-failed`, health heartbeat, queue watchdog, `alerts:check`, alerts prune | yes | yes | yes |
| `library:warm` (every 5 min), `artwork:backfill`, `artwork:prerender`, `playlists:refresh-smart`, `library:merge-duplicates` (`library`) | – | yes | yes |
| `library:enrich` (`enrichment`) | – | yes | yes |
| `library:scan-files` (`file_storage`) | – | yes | yes |
| `catalogue:sync`, `catalogue:new-releases`, `catalogue:availability`, `catalogue:reconcile` (`catalogue`) | – | yes | yes |
| `library:analyze` (`analysis`) | – | – | yes |

`tests/Feature/ProfileGatesTest.php` fails when a task is added to `routes/console.php` and forgotten in the gate (Control may only run the base list).

### Supervised programs

| Program | Control | Standard | Full |
|---|---|---|---|
| `php` (FrankenPHP), `queue` (`health,default`), `scheduler`, `listeners`, `radio-relay` | yes | yes | yes |
| `history-queue` (queue `history`, one process: the single writer of the play history) | **yes** | – | – |
| `storage-queue`, `heavy-queue` (`heavy,catalogue`) | – | yes | yes |
| `analysis-queue`, `stems-queue` | – | – | yes |

The play history is written by a queued listener (`StorePlaybackHistory`). Control runs it on the `history` queue with exactly one worker, so SQLite has one writer for it (`Profile::historyQueue()`, `viaQueue()`); the other profiles keep the default queue as before. `tests/Unit/ProfileSupervisorTest.php` renders the effective program list per profile from `docker/8.4/profile-env.sh` and checks it against `config('profile.workers')`; render it yourself with `PROFILE=control bash docker/8.4/profile-env.sh`. The supervisor config needs the new `START_*` variables, so **rebuild the image** (`docker compose build`) after pulling: an old image's entrypoint does not set them and supervisor refuses to start.

### Containers

`docker-compose.prod.yml` (the repository comment at the top has the same table):

| Container | Control | Standard | Full |
|---|---|---|---|
| app (FrankenPHP + supervisor), redis, mosquitto | yes | yes | yes |
| mariadb (compose profile `db`) | – | yes | yes |
| meilisearch (compose profile `search`) | – | – | optional |
| analysis, structure, stems (compose profiles of the same name) | – | – | optional |
| icecast, librespot, beolink (their own opt-in profiles) | opt-in | opt-in | opt-in |

`COMPOSE_PROFILES` in `.env` selects them; `ansible/templates/env.j2` writes it (MariaDB for Standard and Full, the sidecars only for `full` and only when `analysis_enabled`, `structure_enabled`, `stems_enabled` are true; `structure_enabled` and `stems_enabled` stay opt-in even on Full because of their size). A hand-started install that used MariaDB before profiles must add `db` to `COMPOSE_PROFILES`. **Meilisearch is unused**: nothing in the app uses Scout or talks to it (checked with a grep of `app`, `config`, `routes`, `database` and `composer.json`), so it is a compose profile that only Full may enable, and the app no longer depends on it.

`docker compose config` was run per profile (`COMPOSE_PROFILES=` empty, `db`, `db,search,analysis,structure,stems`): Control lists app, redis, mosquitto; Standard adds mariadb; Full adds meilisearch and the sidecars. `tests/Unit/ProfileComposeTest.php` keeps that shape.

## SQLite for Control, and its conditions

SQLite failed in 2026-10 ([database.md](database.md#why-not-sqlite): lock contention and a corrupt file on a macOS bind mount). Control may use it only under these conditions, all in the code or the compose file:

1. **WAL and a busy timeout, on a named volume.** `config/database.php` (sqlite connection): `journal_mode=WAL`, `busy_timeout=10000` ms, `synchronous=NORMAL` (env `DB_JOURNAL_MODE`, `DB_BUSY_TIMEOUT`, `DB_SYNCHRONOUS`), and **`transaction_mode=IMMEDIATE`** (`DB_TRANSACTION_MODE`): with Laravel's default `DEFERRED`, a transaction that reads and then writes fails at once with `database is locked` when another writer committed in between, and the busy timeout cannot help (the stress test below showed 18 such errors in 4 minutes; `IMMEDIATE` takes the lock when the transaction begins). The file lives in the `remoment-sqlite` named volume at `/data/sqlite/database.sqlite` (`DB_DATABASE`, written by `env.j2`); `SqliteGuard` logs a warning once a day when the file is on a filesystem known to break SQLite (virtiofs, osxfs, grpcfuse, 9p, NFS, SMB, FUSE, vboxsf). A plain Linux bind mount cannot be told from a volume, so that is not detected; the compose file never bind-mounts it. In-memory SQLite (the tests) ignores both settings.
2. **Redis stays** for queue, cache and sessions in Control (`QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`): the database only holds data.
3. **One writer for the play history**: the `history-queue` program above.
4. **A 24-hour stress test on a real Pi gates the decision** (the owner's): `php artisan profile:stress-sqlite --minutes=1440` (below). If it fails, the Pi 2 is not supported rather than flaky.
5. **Backup** covers an SD-card install: `backup:run` snapshots the SQLite file (see [backup-restore.md](backup-restore.md)).

### MariaDB-only SQL

Everything the Control features run was checked for SQL that exists only on MariaDB (a grep of `app/` and `routes/` for `HOUR(`, `DAYOFWEEK(`, `JSON_`, `FULLTEXT`, `ON DUPLICATE`, `GREATEST(`, `LEAST(`, `DATE_FORMAT`, `FIELD(`, `IFNULL`, `GROUP_CONCAT`, `UNIX_TIMESTAMP`, `TIMESTAMPDIFF`, `DATEDIFF`, `REGEXP`, `MATCH(`, `RAND(`, `lockForUpdate`, `whereJson`, `upsert` and every `selectRaw`, `whereRaw`, `orderByRaw`, `DB::statement`):

| Spot | Feature | State |
|---|---|---|
| `Play::secondsListened()` (`TIMESTAMPDIFF`) | history, stats | already has a SQLite branch (`julianday`) |
| `StatsController` (`HOUR()`, `DAYOFWEEK()`) | insights | already has SQLite branches (`strftime`); `DATE()` and `COUNT` work on both |
| `LibraryOverview` (`CAST(… AS DECIMAL)`), `LibraryReports` (`cast(… as integer)`) | library, library health | not in Control; both run on SQLite anyway (the tests use it) |
| `ClientManager` (`status = 'pending' DESC`), `RadioStationController` (`favorited_at IS NULL`), `ReadsMetadata` (`source = ? desc`) | clients, radio | boolean expressions, valid on both |
| The `ImportSqlite` copy (`SET FOREIGN_KEY_CHECKS`) | SQLite to MariaDB only | the shared `SqliteImporter` switches to `PRAGMA foreign_keys` for a SQLite target |

No other MariaDB-only SQL exists in `app/`. All migrations run on SQLite (the test suite migrates in memory); `profile:stress-sqlite` migrates a fresh file with them too.

### `profile:stress-sqlite`

`php artisan profile:stress-sqlite [--minutes=1] [--listeners=6] [--workers=2] [--web=2] [--intensity=1] [--file=] [--keep] [--max-p95=250] [--json]` simulates the Control write load on a **temporary** SQLite file (it refuses an existing file and the configured database): one process per fake device listener (a state/last-seen update each second), the single history writer (a read-then-write transaction: close the last play, open the next), queue workers (setting upserts), web processes (reads and now and then a write) and the scheduler (raise and resolve an alert), plus `--intensity` as a multiplier. It prints writes per second, `database is locked` count, other errors, p50/p95/p99/max write latency, the `PRAGMA integrity_check` and a verdict (`PASS` only with zero lock errors, zero other errors, no crashed worker, `ok` integrity and p95 within `--max-p95`).

Measured on the owner's Mac (a laptop SSD, not an SD card: only the relative numbers and the error counts carry over), 17 processes at `--intensity=5` (about five times the real write rate):

| Configuration | Duration | Writes | `database is locked` | Write latency p50 / p95 / p99 / max (ms) | Verdict |
|---|---|---|---|---|---|
| Rollback journal, no busy timeout (what failed in 2026-10) | 1 min | 3,160 (52/s) | **1,004** (and 22 other errors) | 0.6 / 2.4 / 3.4 / 11 | FAIL |
| WAL, 10 s busy timeout, `DEFERRED` transactions | 4 min | 16,682 (69/s) | **18** (all in the read-then-write history transaction) | 0.35 / 1.0 / 1.8 / 9.6 | FAIL |
| **WAL, 10 s busy timeout, `IMMEDIATE` transactions (the shipped configuration)** | 5 min | 20,899 (70/s) | **0** (0 other errors, `integrity_check` ok) | 0.4 / 1.2 / 1.8 / 10 | **PASS** |

Per role in the last run: listeners 14,733 writes, queue workers 2,949, web 2,174, history 744, scheduler 299.

The 24-hour gate is the owner's: run `php artisan profile:stress-sqlite --minutes=1440 --listeners=6` in the app container on a Pi 2 and a Pi 3, with the real listeners also running, and keep the output with the 9b budget numbers.

## Upgrading: `profile:upgrade`

Control to Standard (or Full) is SQLite to MariaDB. `php artisan profile:upgrade [--to-profile=standard|full] [--database=remoment] [--to=mariadb] [--from=file] [--dry-run] [--force]`:

1. runs `backup:run` and **refuses to continue if it fails** (`--dry-run` skips the backup and every write);
2. checks the MariaDB connection (`DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` in `.env`; the database name is `--database`, default `remoment`, because `DB_DATABASE` is the SQLite file in Control);
3. migrates the target;
4. copies a consistent snapshot of the SQLite file (`VACUUM INTO`, so the listeners may keep writing) with the `db:import-sqlite` machinery (`SqliteImporter`); refuses a target that already has rows unless `--force`;
5. compares the row counts of the important tables (`profile.upgrade_verify_tables`: users, settings, devices, plays, radio stations, clients, alerts, library tables …) and fails on any difference;
6. prints exactly what to change: `PROFILE`, `DB_CONNECTION=mariadb` and `DB_*`, `COMPOSE_PROFILES` and the restart. It **edits no `.env` and restarts nothing**: the install keeps running on SQLite until you switch, and rows written after the snapshot are not in MariaDB, so stop the stack before switching (run the command again after stopping if it was busy).

**Standard to Control is not supported** (the library data would be dropped), and there is no `profile:downgrade`. Standard and Full differ only in what runs, not in the database: switch between them by changing `PROFILE` and the compose profiles.

## Setup wizard

`/setup` starts with the three profile cards (what it runs, what it suits, what it needs, the database), marks the one running (from `PROFILE`) and the one chosen, and has no library step in Control (the progress reads "of 2 steps"). See [setup-wizard.md](setup-wizard.md).

## Not verified

- **A Pi 2 or Pi 3 has not run any of this.** The memory and load budgets of 9b are still to be measured per profile.
- **The 24-hour stress test** on a real Pi with real listeners (above) has not been run; the numbers here are minutes on a Mac.
- **armv7 images.** The Pi 2 is 32-bit: the app (FrankenPHP's official image lists armv7), Redis and Mosquitto each need an armv7 build; unverified. If one is missing, the Control floor is the Pi 3 (arm64).
- **`docker compose` on the real stacks**: the files validate (`docker compose config`) but no stack was started with `PROFILE=control`; the Ansible template was rendered offline for each profile.
- **WAL on an SD card**: fsync behaviour of cheap cards is the usual risk; not tested.
