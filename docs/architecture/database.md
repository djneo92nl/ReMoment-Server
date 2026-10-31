# Database: MariaDB

The **Standard** and **Full** profiles run on **MariaDB 11.4** (`compose.yaml` service `mariadb`, `docker-compose.prod.yml` compose profile `db` for production). The **Control** profile ([profiles.md](profiles.md)) runs on SQLite under the conditions below. The test suite uses in-memory SQLite (`composer test`).

## Why not SQLite

SQLite was the default until 2026-10-20 and failed in two ways:

- **Lock contention.** Queue workers, the per-device listeners, the scheduler and web requests all write. SQLite has one writer at a time: `database is locked` showed up hundreds of times a day (the `jobs` table delete, play history).
- **Corruption on a bind mount.** In development the project directory is a Docker bind mount from macOS. SQLite's locking and, worse, WAL's shared-memory file do not hold across the host/VM boundary, so the file got `database disk image is malformed` (page references twice, rows out of order). Do not enable `journal_mode=WAL` on a bind-mounted database.

## SQLite for Control (allowed under conditions)

The Control profile (device control on a Pi) uses SQLite again, because a second database container does not fit a 1 GB board. That is only acceptable under the conditions that answer the two failures above, all built (see [profiles.md](profiles.md#sqlite-for-control-and-its-conditions)): WAL, a 10 s `busy_timeout` and `IMMEDIATE` transactions (`config/database.php`), the file in a **named volume** (`remoment-sqlite`, `DB_DATABASE=/data/sqlite/database.sqlite`; a boot-time warning for filesystems known to break SQLite), Redis for queue/cache/sessions, a single worker for the play history, and a 24-hour stress test on a real Pi (`php artisan profile:stress-sqlite`) that gates the decision. Control to Standard moves the data with `php artisan profile:upgrade`; Standard to Control is not supported.

MariaDB keeps its data in a named Docker volume (`sail-mariadb` / `remoment-mariadb`), never on the bind mount.

## Configuration

`.env`:

```
DB_CONNECTION=mariadb
DB_HOST=mariadb        # Sail; production uses host networking: 127.0.0.1
DB_PORT=3306
DB_DATABASE=remoment
DB_USERNAME=remoment
DB_PASSWORD=remoment   # production: vault_db_password in ansible/group_vars/vault.yml
```

These must be in `.env`: `artisan serve` (the dev web server) removes every variable defined in `.env` from its workers' environment, so container `environment:` values for `DB_*` are ignored by it. The Ansible `env.j2` template writes them for production. Sessions, cache and the queue run on Redis.

## Moving data from a SQLite file

```bash
php artisan migrate
php artisan db:import-sqlite path/to/database.sqlite --force
```

`App\Console\Commands\Database\ImportSqlite` (the machinery is `App\Domain\Database\SqliteImporter`, shared with `profile:upgrade`) copies every table the target has (columns present on both sides), with foreign key checks off, and prints source and target row counts. Rows MariaDB's strict mode rejects are skipped and listed. It does not copy `migrations`, `sessions`, `cache`, `jobs`, `job_batches` or `lost_and_found`.

A corrupt file is recovered first: `sqlite3 database.sqlite ".recover" | sqlite3 recovered.sqlite`, then imported. Rows that landed in the wrong table through corruption (queue job JSON in `metadata`) fail the import and are skipped, which is what you want. A string over 255 characters (SQLite never enforced the column length: a DLNA tag held a 1,400-character credits list as artist) is shortened and the row kept; `Artist`, `Album` and `Track` shorten such names on save too (`Normalizer::storedName()`, the key is taken from the full name).

## Driver-specific SQL

Code that must differ per driver branches on `DB::getDriverName() === 'sqlite'`, because tests and the Control profile run on SQLite: `Play::secondsListened()` and `StatsController` (`HOUR()`, `DAYOFWEEK()` on MariaDB, `strftime`/`julianday` on SQLite). Keep both branches when you add such a query, and keep any feature that Control runs (devices, history, stats, radio, alerts, backup, health, clients) free of MariaDB-only SQL; the list of what was checked is in [profiles.md](profiles.md#mariadb-only-sql).

## Leaving the streaming library data behind

`php artisan library:drop-streaming [--dry-run] [--force]` removes the library data of DLNA and Spotify: tracks of those sources that no own file backs (the radio stubs and anything available from `files` stay), their albums and artists, and the Spotify playlists. It reuses `LibraryRemover::track($track, force: true)`, so plays of a removed track stay as text plays (names, cover, duration). Favorites on removed rows are lost. The production deploy runs it once after the SQLite import (`ansible/deploy.yml`); afterwards the daily `spotify:sync-library` and a manual `php artisan library:scan` bring the streaming library back.
