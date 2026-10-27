# Backup and restore

`backup:run` writes one archive of everything the database and settings hold; `backup:restore` loads it into a fresh install. Part of workstream 1 of [v1-roadmap.md](v1-roadmap.md); `app:upgrade` (9a) runs `backup:run` first, and migrations are forward-only, so **the backup is the rollback**.

## Commands

```bash
php artisan backup:run [--path=] [--keep=N] [--no-db] [--with-secrets]
php artisan backup:restore {archive} [--force]
```

Scheduled nightly at 01:30 (`routes/console.php`). Config in `config/backup.php`: `BACKUP_PATH` (default `storage/app/backups`), `BACKUP_KEEP` (default 7). Retention only removes files named `remoment-backup-YYYYMMDD-HHMMSS.zip` in the backup directory: nothing else is ever deleted. `/settings/health` shows a warning when there is no archive or the newest is older than 48 hours (`App\Domain\Backup\BackupStatus` reads the directory, not the database, so it also works after a restore or a wipe).

## What is in an archive

`remoment-backup-….zip`, created with mode 0600:

| Entry | Content |
|---|---|
| `manifest.json` | format, creation time, app version, DB driver, latest migration and batch, row count per table, whether secrets are included |
| `data/{table}.jsonl` | every table, one JSON row per line (invalid UTF-8 as `{"$b64": …}`); the same format for MariaDB and SQLite |
| `env-safe.txt` | a whitelist of `.env` keys (`config/backup.php` `safe_env`: APP_URL, DB_CONNECTION, MQTT_PUBLIC_HOST, MUSIC_DIR …). Informational, restore does not apply it |

Included: devices and config, clients, library, playlists, favourites, plays, radio stations, sync groups, settings, analysis results, enrichment metadata.

**Left out:** `migrations`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `lost_and_found` (the same list as `db:import-sqlite`) and `failed_jobs`; the artwork cache (rebuilt: `artwork:backfill`, `artwork:prerender`); Redis; **your own audio files** (below).

### Secrets: excluded by default

A plain zip is not encrypted, so by default the archive holds **no credentials**:

- not written at all: the `users`, `admin_tokens`, `password_reset_tokens`, `personal_access_tokens` tables, and settings rows holding credentials: every key the credentials registry marks `secret` (`ServiceCredentials::secretSettingKeys()`: Spotify client secret, Last.fm key and secret, Discogs token, AcoustID and TheAudioDB keys; see `credentials.md`) plus, as a safety net, any key containing `token`, `secret`, `session_key` or `password` (Spotify access and refresh token, Last.fm session key);
- blanked: client `api_token` and `pairing_code`, and `registration_token` replaced by a random placeholder;
- never, even with the opt-in: anything from `.env` except the whitelist (no `APP_KEY`, no `DB_PASSWORD`, no API keys).

After restoring such a backup: the fresh install's own admin login applies (the seeded one), the service keys entered on Settings › Services & keys must be entered again (unless they are in `.env`), Spotify and Last.fm must be connected again, and wall panels / apps register again at `/settings/clients`. `--with-secrets` puts the credential tables and rows into the archive too (admin password hash, OAuth tokens, client tokens, so nothing needs reconnecting) after a loud warning; treat that file like a password.

## How it is made

No `mariadb-dump`: the production image installs `mysql-client` (Sail's default), not MariaDB's own client, and MySQL's `mysqldump` against MariaDB 11.4 is a version-skew risk. The export is table by table in PHP instead, one path for both drivers:

- **MariaDB**: one `REPEATABLE READ` transaction (a consistent snapshot, the effect of `--single-transaction`); tables are read in 1000-row chunks, so nothing is locked and memory stays flat.
- **SQLite** (the Control profile): `VACUUM INTO` a temp file (SQLite's online backup; correct for a live WAL database, never a raw file copy), read from that copy.

## Restore on a fresh install

1. Install the same or a newer version of the app and start MariaDB (or SQLite) with an empty database.
2. `php artisan migrate --force` (no `db:seed` needed: the seeded admin only matters for archives made with `--with-secrets`, and then `users` must be empty or `--force` used).
3. `php artisan backup:restore /path/to/remoment-backup-….zip`. It prints the backup's version, then a table with the row count before, in the backup and after.
4. `php artisan queue:restart`, reload `/settings/health`, check devices, library, playlists.

Refusals: the archive's latest migration is newer than the app's (a downgrade; update the app first), a different driver family (use `db:import-sqlite` for SQLite to MariaDB), a `--no-db` archive, and any table that already has rows unless `--force` (which empties those tables first). Foreign keys are switched off for the load (`SET FOREIGN_KEY_CHECKS` / `PRAGMA foreign_keys`) and back on after. Tables missing in the target are skipped with a warning; columns the target lacks are dropped.

**`APP_KEY` and `--with-secrets`.** Service secrets are stored encrypted with the install's `APP_KEY` (see [credentials.md](credentials.md)). An archive made with `--with-secrets` holds those encrypted values, so restoring it on an install with a different `APP_KEY` probably leaves them unreadable (the registry then treats them as not set and you enter the keys again). *Not verified.* Keep the `APP_KEY` with your backups if you use `--with-secrets`.

## Not in the backup: your music files

Own audio files (`MUSIC_DIR`, `LIBRARY_DIR`, S3 locations) are yours and large. Back them up with what you use for the rest of your files (rsync, restic, Proxmox backup of the mount, S3 versioning). After restoring on a new machine, mount them at the same paths and run `php artisan library:scan-files`; the database already knows the files (tracks, playlists, plays) and matches them.

## Where the archives live, and SD cards

The default directory `storage/app/backups` is inside the app directory, which `docker-compose.prod.yml` bind-mounts into the container (`.:/var/www/html`), so archives survive a container recreate and the Ansible redeploy (`git` leaves untracked files alone). It is a different volume from MariaDB's named volume, but the **same disk**.

- **Copy archives off the machine** (a NAS, another host, cloud storage): a backup on the disk that dies is not a backup. Point `BACKUP_PATH` at a mounted share, or sync the directory with cron/rsync.
- **Control profile on a Raspberry Pi (SQLite on an SD card):** SD cards corrupt on power loss, taking the database and the backups next to it. Keep the archives on a USB stick or network share (`BACKUP_PATH`), or copy them off nightly. A restore is then: fresh image, `migrate`, `backup:restore`.

## Upgrade flow

`app:upgrade` (planned, 9a) calls `backup:run`, then migrates. If the migration or the new version fails, install the previous version and `backup:restore --force` the archive from just before. The manifest's migration name is what refuses restoring a newer backup into older code. The planned `profile:upgrade` (Control to Standard) uses `backup:run` as its safety net and `db:import-sqlite` to move the data.

## Not verified

- The Docker image was not inspected on a real host: whether `mysql-client` is what ships follows from `docker/8.4/Dockerfile` (not needed by these commands).
- Run against a real MariaDB 11.4 only by reading, not by test: the suite runs on SQLite. Do a restore on a scratch MariaDB before trusting it (also asked by the "Tested upgrade" item of 9a).
