# Artisan commands

Full command list with flags and notes, moved out of CLAUDE.md.

```bash
# Initial setup
composer setup

# Start full dev environment (Laravel, queue, Vite, log viewer)
composer dev

# Run tests
composer test

# Run a single test file or method
php artisan test --filter=TestClassName
php artisan test tests/Path/To/TestFile.php

# Code style
./vendor/bin/pint

# Profiles: stress the Control write load on a temp SQLite file (the 24-hour gate: --minutes=1440), and move Control to Standard (SQLite to MariaDB; backup first, edits no .env); see docs/architecture/profiles.md
php artisan profile:stress-sqlite [--minutes=1] [--listeners=6] [--workers=2] [--web=2] [--intensity=1] [--json]
php artisan profile:upgrade [--to-profile=standard|full] [--database=remoment] [--dry-run] [--force]

# Supported-hardware table of docs/user/hardware.md, generated from config/devices.php, the driver contracts and config/hardware-status.php (verification status, date, note per driver/model); --check exits 1 when the page is stale (a test runs it); see docs/architecture/hardware-table.md
php artisan hardware:table [--format=markdown|json] [--check]

# Version: print the VERSION file's version (`--json`: version, recorded version, profile; `--record`: store it as the installed version, Setting `app_version`)
php artisan app:version [--json] [--record]

# Upgrade (9a): pre-flight (downgrade, unknown newer migration, pending migrations, disk, heartbeats, profile), mandatory backup, maintenance mode, migrate, caches (config('upgrade.after_migrate')), queue:restart, record version, health check, rollback instructions, last 10 runs in Setting `upgrade_history` (shown on /settings/health). Exit 0 ok / 10 upgrade available (--check) / 1 blocked / 2 backup failed / 3 migration failed (app left down) / 4 health check failed; --json needs --force to run and adds steps, migrations_run, exit_reason, rollback; Ansible `upgrade_command`; see docs/architecture/backup-restore.md
php artisan app:upgrade [--check] [--json] [--force] [--no-maintenance] [--health-timeout=120]

# Diagnostics bundle (9b): one redacted zip for an issue (versions, profile, health, queues, failed jobs, devices without addresses, alerts, upgrade history, log tail; never secrets, IP/MAC addresses or library contents; no flag adds them); also `GET /api/admin/diagnostics` (admin token) and a button on /settings/health; see docs/architecture/diagnostics.md
php artisan diagnostics:bundle [--path=]

# Backup (database + safe settings + manifest, no secrets unless --with-secrets; scheduled nightly, keeps BACKUP_KEEP) and restore on a fresh `migrate`; see docs/architecture/backup-restore.md
php artisan backup:run [--path=] [--keep=N] [--no-db] [--with-secrets] [--no-copy]   # then copies the archive to Setting backup_copy_path if set (atomic, same retention, a failure is the backup.copy_failed alert, never a failed backup; not with --with-secrets)
php artisan backup:restore {archive} [--force]

# Copy the data of a SQLite file into the configured MariaDB (after `php artisan migrate`); see docs/architecture/database.md
php artisan db:import-sqlite path/to/database.sqlite [--force]

# Discover devices of every registered brand (SSDP/mDNS/Sonos topology) and list them at /settings/devices to be added (hourly in the background; --add stores them as devices right away)
php artisan device:discover [--brand=sonos|ase|mozart] [--host=192.168.1.9] [--add]

# Sync B&O source/JID data for all ASE devices
php artisan device:sync-sources

# Map a B&O ASE device's (or NetworkLink/MasterLink converter's) REST API — GET-only crawl; --compare diffs two devices
php artisan ase:map {device id|ip} [--seed=BeoZone/Zone/Video] [--compare=storage/app/ase-maps/other.json]

# Ask a WiiM the read-only commands of its HTTP API (getStatusEx, getPlayerStatus, getMetaInfo, getPresetInfo, EQ list ...), GET only, and record the answers to storage/app/wiim-probes; see docs/architecture/wiim.md
php artisan wiim:probe {ip} [--out=]

# Listen to a Master Link Gateway (IP protocol + bus log), read-only, and record to storage/app/mlgw-probes; see docs/architecture/masterlink-gateway.md
php artisan mlgw:probe {ip} [--user=admin] [--seconds=120] [--no-http|--no-api|--no-bus]

# Scan DLNA servers' libraries (triggered via UI or manually)
php artisan library:scan [--server=192.168.1.20] [--root=Muziek]   # --root: start in a folder (title, object id or Muziek/Albums) so a server with films and series isn't crawled first

# Queue MusicBrainz/lyrics/Spotify/Last.fm/TheAudioDB/Discogs enrichment for tracks, artists and albums still missing a source, plus Spotify photos for artists without one (scheduled daily; artists with a Spotify id are fetched 50 per request, others looked up by exact name). --only=metadata|artist-images runs one part
php artisan library:enrich [--only=metadata --only=artist-images] [--limit=200] [--dry-run]

# Upcoming concerts of library artists from MusicBrainz events (opt-in setting `concerts_enabled`; scheduled daily 03:10 only while on): queues one lookup per artist not read in --days, drops past concerts (docs/api/concerts.md)
php artisan concerts:sync [--limit=100] [--days=7] [--dry-run]

# Detect the language (and a guessed explicit flag) of stored lyrics not looked at yet; local, no requests (scheduled daily 03:20; docs/api/lyrics.md)
php artisan library:lyrics-analysis [--limit=2000] [--redo]

# Audio analysis of the own files (loudness, fingerprint + AcoustID, BPM/key/mood via the optional Essentia sidecar); needs ANALYSIS_ENABLED=true, runs on the `analysis` queue (scheduled nightly). --now with --file runs inline
php artisan library:analyze [--limit=100] [--stage=loudness|waveform|quality|fingerprint|acoustid|essentia] [--file=ID --now] [--dry-run]

# Rebuild the optional sqlite-vec index of the sound embeddings from `file_embeddings` (similar tracks; the PHP scan needs none; scheduled daily 04:40 only with SIMILAR_ENGINE=sqlite-vec); --status shows rows against the table; see docs/architecture/similar-tracks.md
php artisan similar:index [--status]

# Remove kept stem audio (`stems/{file id}`, kind `stem_audio`) of files that were removed or changed (scheduled daily 03:30, Full profile); --dry-run counts; see docs/api/stems.md
php artisan stems:prune [--dry-run]

# Keep the cache of prepared sync files (transcodes shared by every sync target) under SYNC_CACHE_GB, least recently used first, never one used in the last SYNC_PROTECT_MINUTES; also drops rows stuck while preparing and orphan files (hourly, file_storage); see docs/api/sync.md
php artisan sync:prune

# Helper of scripts/queue-soak/run.sh (the owner's 24-hour queue soak; never run by a test or a session): queue a mixed load of no-op jobs on every queue of the profile, sample queue health and worker memory as one JSON line, write report.md from a samples file; see docs/architecture/queue-management.md
php artisan queue:soak {enqueue|sample|report} [--per-queue=20] [--input=samples.jsonl] [--output=report.md]

# Opt-in nightly stems (STEMS_NIGHTLY=true, 02:00): queue stems for tracks that have none, newest first, within STEMS_NIGHT_HOURS (default 4) of estimated work on the single `stems` worker; --hours overrides, --dry-run counts; see docs/architecture/stems-service.md
php artisan library:analyze-stems [--hours=4] [--dry-run]

# Web Push (8.6 phase 3): create the VAPID key pair once or show its PUBLIC key (`--rotate` makes a new pair and deletes every stored browser subscription; the private key is encrypted in the settings table and never printed or backed up), and push a test to every subscribed browser without raising an alert; see docs/frontend/pwa.md
php artisan push:vapid [--rotate] [--force]
php artisan push:test [--message=]

# Home Assistant MQTT discovery (opt-in: switch on /settings/mqtt or HA_DISCOVERY_ENABLED): publish the retained configs + states of the "ReMoment Server" device (stats, health, clients, sources, event entities; never media_player), or clear them with --remove; the scheduler republishes every 5 minutes while on; unverified in a real HA; see docs/architecture/home-assistant.md
php artisan ha:discovery [--remove]

# Home Assistant command path (opt-in, needs discovery on): `ha:commands on|off` (no argument shows the state; scene buttons appear or go), `ha:listen` is the supervised subscriber to remoment/ha/command/# (program ha-commands; idles until both are on); scene buttons and scene-allow-listed operations only, results on remoment/ha/events/command_result; unverified in a real HA; see docs/architecture/home-assistant.md
php artisan ha:commands [on|off]
php artisan ha:listen

# Home Assistant rest_command package (YAML) for the scene-capable operations of config/scene-operations.php, generated from docs/api/openapi.yaml; unverified in a running HA; see docs/architecture/home-assistant.md
php artisan ha:package [--output=remoment.yaml] [--base-url=http://remoment.local]

# Delete plays older than the history retention (Setting `history_keep_months`, set on /history; default keep forever = no-op); only `plays` rows go, scheduled daily; --dry-run counts; see docs/api/history.md
php artisan history:prune [--months=N] [--dry-run]

# Hide ReMoment Players that have not reported for PLAYERS_HIDE_AFTER_DAYS (default 30; never deletes; scheduled daily 04:10); see docs/api/players.md
php artisan players:hide-stale [--days=N] [--dry-run]

# Queue a refresh job for every podcast feed that is due (scheduled every 30 min where the profile has podcasts; conditional GET, spaced out, back-off on failure); --force ignores the interval, --now fetches here instead of queueing; see docs/api/podcasts.md
php artisan podcasts:refresh [--force] [--podcast=ID] [--now]

# Re-select the tracks of smart (rule-based) playlists (scheduled daily)
php artisan playlists:refresh-smart [--playlist=ID]

# Rebuild canonical genres from stored genre metadata (after changing GenreNormalizer)
php artisan library:sync-genres

# Remove the DLNA/Spotify library data (tracks without an own file, Spotify playlists); library:scan brings the DLNA part back. Used once by the production SQLite→MariaDB move
php artisan library:drop-streaming [--dry-run] [--force]

# Remove what an old Spotify import left in the library (tracks only Spotify has, Spotify playlists, empty albums/artists). The library is the local tracks; Spotify extends it live. A migration ran it once
php artisan library:drop-spotify [--dry-run] [--force]

# Merge library duplicates across sources (DLNA / Spotify / plays) — dry run first
php artisan library:merge-duplicates --dry-run
php artisan library:merge-duplicates [--force]

# Index audio files of the storage locations (local/S3), stream them from this server; see docs/architecture/file-storage.md
php artisan library:scan-files [location]   # never the inbox; library:purge-inbox-index [--dry-run] forgets rows an older scan made for it
php artisan library:extract-covers [--limit=50]   # covers embedded in the own files → albums without an image (also run by every file scan)
php artisan library:import                  # inbox → library, organized by artist/album
php artisan library:reorganize [--dry-run]
php artisan library:verify-files

# Queue missing artwork for playlist covers and the last N played albums (scheduled daily)
php artisan artwork:prerender [--limit=500] [--max-jobs=100]

# Record the pixel size and hash of covers processed before the cover health report existed (daily, 200 per run; `config/artwork.php` holds the report's thresholds); see docs/architecture/library-health.md
php artisan artwork:measure-covers [--limit=200]

# Build the SD card artwork zip for the touch client (also on /settings/clients)
php artisan artwork:export-sd [--size=1024x600|320x480|480x480] [--limit=500] [--queue]
```
