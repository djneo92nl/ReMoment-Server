# Database: MariaDB

The app runs on **MariaDB 11.4** (`compose.yaml` service `mariadb`, `docker-compose.prod.yml` for production). SQLite is used by the test suite only (`composer test`, in-memory).

## Why not SQLite

SQLite was the default until 2026-10-20 and failed in two ways:

- **Lock contention.** Queue workers, the per-device listeners, the scheduler and web requests all write. SQLite has one writer at a time: `database is locked` showed up hundreds of times a day (the `jobs` table delete, play history).
- **Corruption on a bind mount.** In development the project directory is a Docker bind mount from macOS. SQLite's locking and, worse, WAL's shared-memory file do not hold across the host/VM boundary, so the file got `database disk image is malformed` (page references twice, rows out of order). Do not enable `journal_mode=WAL` on a bind-mounted database.

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

`App\Console\Commands\Database\ImportSqlite` copies every table the target has (columns present on both sides), with foreign key checks off, and prints source and target row counts. Rows MariaDB's strict mode rejects are skipped and listed. It does not copy `migrations`, `sessions`, `cache`, `jobs`, `job_batches` or `lost_and_found`.

A corrupt file is recovered first: `sqlite3 database.sqlite ".recover" | sqlite3 recovered.sqlite`, then imported. Rows that landed in the wrong table through corruption (queue job JSON in `metadata`) fail the import and are skipped, which is what you want. A string over 255 characters (SQLite never enforced the column length: a DLNA tag held a 1,400-character credits list as artist) is shortened and the row kept; `Artist`, `Album` and `Track` shorten such names on save too (`Normalizer::storedName()`, the key is taken from the full name).

## Driver-specific SQL

Code that must differ per driver branches on `DB::getDriverName() === 'sqlite'`, because tests run on SQLite: `Play::secondsListened()` and `StatsController` (`HOUR()`, `DAYOFWEEK()` on MariaDB, `strftime`/`julianday` on SQLite). Keep both branches when you add such a query.

## Leaving the streaming library data behind

`php artisan library:drop-streaming [--dry-run] [--force]` removes the library data of DLNA and Spotify: tracks of those sources that no own file backs (the radio stubs and anything available from `files` stay), their albums and artists, and the Spotify playlists. It reuses `LibraryRemover::track($track, force: true)`, so plays of a removed track stay as text plays (names, cover, duration). Favorites on removed rows are lost. The production deploy runs it once after the SQLite import (`ansible/deploy.yml`); afterwards the daily `spotify:sync-library` and a manual `php artisan library:scan` bring the streaming library back.
