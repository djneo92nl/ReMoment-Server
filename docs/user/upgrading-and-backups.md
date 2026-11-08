# Upgrading and backups

## What exists today

- **Backups:** `php artisan backup:run` and `php artisan backup:restore`, plus a nightly backup. Full detail: [backup-restore.md](../architecture/backup-restore.md).
- **Moving Control to Standard or Full:** `php artisan profile:upgrade` (makes a backup first, copies SQLite into MariaDB, prints what to change in `.env`; it edits nothing and restarts nothing). The reverse is not supported.
- **Updating the software:** by hand, below.

> **What exists:** `php artisan app:upgrade` (after you have installed the new code): it checks, makes a backup, puts the app in maintenance mode, migrates, clears caches, restarts the queue workers, checks health and prints how to go back (`--check` only looks; `--force` skips the question; `--json` for scripts). If a migration fails the app stays in maintenance mode and you restore the backup it names. **Not built:** an upgrade from the web, pulling new images or code for you, and a Manager service. Migrations only go forward, so the backup is your way back: install the previous version, then `php artisan backup:restore <archive> --force`.

Run artisan inside the container, for example `docker compose -f docker-compose.prod.yml exec -u sail app php artisan backup:run` (see [install-docker.md](install-docker.md)).

## Backups

```bash
php artisan backup:run [--path=] [--keep=N] [--no-db] [--with-secrets]
php artisan backup:restore {archive} [--force]
```

- It runs **every night at 01:30**. Archives are `remoment-backup-YYYYMMDD-HHMMSS.zip` in `storage/app/backups` (`BACKUP_PATH` changes it), the newest 7 are kept (`BACKUP_KEEP`). `/settings/health` warns when there is none or the newest is older than 48 hours.
- An archive holds every table (devices, clients, library, playlists, favourites, history, radio stations, settings, analysis results) as JSON lines, plus a manifest. It does **not** hold your music files, the artwork cache (rebuilt), Redis, or sessions.
- **Secrets are left out by default.** Admin password hash, service keys (Spotify, Last.fm, Discogs, AcoustID, TheAudioDB), OAuth tokens and client tokens are not written, and `.env` is never included (no `APP_KEY`, no `DB_PASSWORD`). After a restore, the install's own seeded admin login applies, keys must be entered again at `/settings/services`, Spotify and Last.fm connected again, and wall panels registered again. `--with-secrets` includes them in the archive: treat that file like a password.
- **`APP_KEY`:** stored service secrets are encrypted with it. Keep a copy of your `APP_KEY` (the `.env` line, or `vault_app_key`) outside the machine. With a different key, secrets in an archive made with `--with-secrets` probably cannot be read (*not verified*).
- **Never run against a real MariaDB 11.4 by the project itself:** the test suite uses SQLite. Try a restore on a scratch install before you trust your archives.

### Copy archives off the machine

The default folder is on the same disk as the database. A backup on a disk that dies is not a backup: point `BACKUP_PATH` at a mounted share, or copy the folder elsewhere with cron and rsync.

**Raspberry Pi:** SD cards can corrupt when power is lost, and that takes the database and the backups beside it. Put archives on a USB drive or network share, or copy them off every night.

### Your music

Your own audio files (`MUSIC_DIR`, `LIBRARY_DIR`, S3) are not in the archive. Back them up with what you use for other files (rsync, restic, a Proxmox backup of the mount).

### Restore on a fresh install

1. Install the same or a newer version and start with an empty database.
2. `php artisan migrate --force`
3. `php artisan backup:restore /path/to/remoment-backup-….zip` (`--force` empties tables that already have rows).
4. `php artisan queue:restart`, then check `/settings/health`, devices, library and playlists.
5. Mount your music at the same paths and run `php artisan library:scan-files`; the database already knows the tracks and matches the files.

A restore refuses an archive from a newer app version, from a different database type (use `db:import-sqlite` for SQLite to MariaDB), or one made with `--no-db`.

## Updating by hand

1. `php artisan backup:run` and copy the archive away.
2. Pull the new code. Ansible: re-run `ansible/deploy.yml` (it pulls, rebuilds the image, runs `composer install`, `npm ci`, `npm run build` and `migrate`; `-e rebuild_image=true` rebuilds without cache). Docker: `git pull --recurse-submodules`, then `docker compose -f docker-compose.prod.yml build`, `up -d`, and the `composer install`, `npm ci`, `npm run build`, `php artisan migrate --force`, `php artisan optimize` and restart steps of [install-docker.md](install-docker.md). The supervisor configuration changes between versions, so rebuild the image rather than just restarting.
3. Check `/settings/health`.

If an update fails: install the previous version and `backup:restore --force` the archive from step 1. That path has not been rehearsed end to end (*unverified*).
