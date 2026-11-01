# Schema plan (workstream 14.3): review before the migration squash

Status (2026-11-01): **reviewed, squash not done.** This document is the review of the schema as it stands (62 migration files,
44 tables, 419 columns), the plan for the squash to **one migration per table**, and an ordered list of small changes that
should land before it. The rehearsal harness that proves the squash is in [`scripts/schema/`](../../scripts/schema/README.md),
with the dumps of today's schema committed as `baseline/`. Nothing in `database/migrations/` was touched.

What was **not** verified (no access, by design): the real production and dev schemas. Every statement about "existing data"
below is a question for a read-only query on those databases, listed in [section 5](#5-the-fix-list-before-the-squash). The
first step of the squash day is to dump both with `scripts/schema/dump.php` and diff them against the baseline.

Baseline numbers (fresh `migrate`, MariaDB 11.4 and SQLite, from the committed dumps): **44 tables** (43 plus `migrations`),
**419 columns**, **33 foreign keys**, **129 indexes on MariaDB** (82 on SQLite; MariaDB counts `PRIMARY` and the indexes InnoDB
adds for foreign keys).

## 1. Inventory

Columns / secondary indexes / foreign keys come from the MariaDB baseline dump. Growth: **S** small (hundreds), **M** medium
(thousands to tens of thousands), **L** large (grows with listening or with the library, 100k+), **churn** (rows come and go).
"Control" = used by the SQLite Control profile (device control, no library; `config/profile.php`). "Backup" = in the archive of
`backup:run` (`Snapshot::skippedTables()` = `ImportSqlite::SKIP` + `failed_jobs`; `SECRET_TABLES` only with `--with-secrets`).

| Table | Purpose | Workstream | Created / changed by (migration, short) | cols/idx/fk | Growth | Control | Backup |
|---|---|---|---|---|---|---|---|
| `users` | the one admin login | auth | `create_users_table`, `make_email_nullable_on_users_table` | 8/1/0 | S | yes | secret (opt-in) |
| `password_reset_tokens` | Laravel stock; **no reset flow exists** | auth | `create_users_table` | 3/0/0 | S (empty) | yes | secret (opt-in) |
| `sessions` | Laravel stock; sessions run on Redis | auth | `create_users_table` | 6/2/0 | S (unused) | yes | skipped |
| `cache`, `cache_locks` | Laravel stock; cache is Redis | framework | `create_cache_table` | 3+3/0/0 | S (unused) | yes | skipped |
| `jobs`, `job_batches`, `failed_jobs` | Laravel stock; queue is Redis, `failed_jobs` and `QueueInspector` read it | framework | `create_jobs_table` | 7+10+7 | churn / S | yes | skipped |
| `admin_tokens` | one hashed row per app install | auth (9b) | `create_admin_tokens_table` | 7/1/0 | S | yes | secret (opt-in) |
| `settings` | key/value (flags, credentials encrypted) | all | `create_settings_table` (also `devices.ip_address` nullable) | 4/0/0 | S | yes | yes, secret rows filtered |
| `devices` | players | devices | `create_devices_table`, `create_device_meta_and_remove_device_uuid` (drops `uuid`), `create_settings_table`, `add_hidden_to_devices_table` | 11/0/0 | S | yes | yes |
| `device_meta` | per-device key/value (JIDs, Spotify name) | devices | `create_device_meta_and_remove_device_uuid` | 6/2/1 | S | yes | yes |
| `device_sources` | sources synced from a device | devices | `create_device_sources_table`, `add_hidden_and_sort_order_to_device_sources_table` | 14/1/1 | S | yes | yes |
| `discovered_devices` | discovery inbox | devices (2) | `create_discovered_devices_table` | 12/1/0 | S | yes | yes |
| `dlna_servers` | DLNA servers found | library | `create_dlna_servers_table` | 8/1/0 | S | no (library) | yes |
| `multiroom_presets` | named multiroom groups | devices | `create_multiroom_presets_table` | 5/0/0 | S | yes | yes |
| `sync_groups`, `sync_group_members` | fake multiroom | devices | `create_sync_groups_tables` | 5+6 | S | yes | yes |
| `clients` | wall panels / apps registered | clients | `create_clients_table`, `add_pairing_code_to_clients_table` (+backfill), `add_battery_to_clients_table`, `add_hidden_sources_to_clients_table` | 19/4/0 | S | yes | yes, tokens blanked |
| `client_device` | pivot client to device | clients | `create_client_device_table` | 3/1/2 | S | yes | yes |
| `plays` | play history (also text-only plays) | history | `create_plays_table`, `extend_plays_table` (nullable `track_id`, `ended_at`, source fields), `add_skipped_to_plays_table`, `add_radio_station_id_to_plays_table`, `add_indexes_to_tracks_and_plays_tables`, `add_unmatched_track_fields_to_plays` | 17/4/3 | **L** | yes (history, stats) | yes |
| `radio_stations` | stations | radio | `create_radio_stations_table`, `add_favorited_at_to_radio_stations`, `add_last_played_at_to_radio_stations`, `add_genre_to_radio_stations` | 8/2/0 | S | yes | yes |
| `radio_station_meta` | per-platform station ids | radio | `create_radio_stations_table` | 4/1/1 | S | yes | yes |
| `radio_plays` | station-level songs (ICY/NTS), pruned after `KEEP_DAYS` | radio | `create_radio_plays_table` | 6/1/1 | M (pruned) | yes | yes |
| `alerts` | alert model | 14.1 | `create_alerts_table` | 14/4/1 | S (resolved pruned) | yes | yes |
| `artists` | library artists | library | `create_media_tables`, `add_favorited_at_to_albums_and_artists`, `add_name_key_to_library_tables` | 7/3/0 | M | no | yes |
| `albums` | library albums | library | `create_media_tables`, `add_colors_to_albums_table`, `add_favorited_at_to_albums_and_artists`, `add_name_key_to_library_tables` | 11/3/1 | M | no | yes |
| `tracks` | library tracks (and radio stubs) | library | `create_media_tables`, `add_indexes_to_tracks_and_plays_tables`, `add_name_key_to_library_tables`, `add_favorited_at_to_tracks`, `split_local_files_from_dlna_library` (data) | 12/6/2 | **L** | no | yes |
| `metadata` | polymorphic key/value on artist, album, track, playlist | library | `create_media_tables` | 10/3/1 | **L** (10 to 30 rows per track) | no | yes |
| `genres`, `genreables` | canonical genres, polymorphic link | library | `create_genres_tables` (calls `library:sync-genres`) | 6+5 | S / M | no | yes |
| `playlists`, `playlist_track` | playlists (local, smart, Spotify live) | library | `create_playlists_table`, `add_last_played_at_to_playlists`, `add_rules_to_playlists` | 11+6 | S / M | no | yes |
| `storage_locations` | own-file storage (local/S3), config encrypted | storage | `create_storage_locations_table` | 8/1/0 | S | no | yes (see finding 12) |
| `files` | indexed audio files | storage | `create_files_table`, `add_hidden_at_to_files_table` | 19/3/1 | M to L | no | yes |
| `track_files` | track to file link | storage | `create_track_files_table` | 9/3/3 | M to L | no | yes |
| `file_analyses` | audio analysis, **wide rows** (waveform x2, fingerprint, sections, stems) | analysis | `create_file_analyses_table`, `add_waveform_...`, `add_quality_...`, `add_analyzed_at_...`, `add_waveform_peak_...`, `add_sections_...`, `add_stems_...` | 42/4/1 | **L** (about 5 to 10 KB a row) | no | yes (expensive to rebuild) |
| `id_aliases` | retired ids of merged records | 14.2 | `create_id_aliases_table` | 5/2/0 | S to M | no | yes |
| `catalogue_artists` | MusicBrainz sync state per artist | 3 | `create_catalogue_tables` | 7/1/1 | M | no | yes |
| `catalogue_albums` | release-groups of library artists | 3 | `create_catalogue_tables` | 18/5/2 | **M to L** (about 10 per artist) | no | yes |
| `album_availability` | provider answers (negative cache) | 3 | `create_catalogue_tables` | 11/3/2 | M | no | yes |
| `wanted_tracks` | wished songs | 3 | `create_wanted_tracks_table` | 13/3/1 | S | no | yes |
| `physical_items` | CDs, records, tapes | 6 | `create_physical_items_table` | 20/6/2 | S to M | no | yes |
| `migrations` | Laravel | framework | (by `migrate`) | 3 | S | yes | skipped |

Facts that matter for the squash:

- **Tables without a model** (cross-check, section 6): framework tables, the pivots `client_device`, `genreables`,
  `playlist_track` (used through relations), and `id_aliases` (raw `DB::table`, `IdAliases`).
- **Planned 1.0 tables that do not exist yet** (14.3's brief says they go straight into the consolidated set): `device_links` (2a),
  `physical_locations` (6, today `physical_items.location` is free text), `exports` (13), scenes (8.1), podcasts, episodes,
  `playback_positions` (8.2, 8.3), `push_subscriptions` (1.1), `media_servers` (15), plus the `plays` retention and history
  indexes (8.8). **This review does not design them**: each needs its own decision (see question 1 in section 8); the rules in
  section 3 and the conventions in section 2 are what they should follow. As long as they are not decided, they append after
  the squash as normal `create_*` migrations, which is fine and forward-only; the squash only has to avoid *add-column*
  migrations for tables that exist.

### Backup versus `db:import-sqlite`: the two skip lists

- `db:import-sqlite` skips `migrations, sessions, cache, cache_locks, jobs, job_batches, lost_and_found`.
- `backup:run` skips those **plus `failed_jobs`**, and without `--with-secrets` also `users, admin_tokens,
  password_reset_tokens, personal_access_tokens`.
- **Skipped by backup but copied by the SQLite import: `failed_jobs`.** The backup is right: failed payloads reference jobs of
  a code version and are not worth restoring. The import is *probably* also right to copy nothing, but it copies today; make
  `failed_jobs` part of `ImportSqlite::SKIP` (and drop the `array_merge` in `Snapshot`) so there is one list. Low risk.
- `lost_and_found` is a table SQLite's `.recover` creates; it exists in neither schema. Keep it in `ImportSqlite::SKIP` (the
  file may contain it), it is dead weight in the backup list.
- `personal_access_tokens` (Sanctum) **does not exist**: stale entry in `SECRET_TABLES`; remove (harmless).
- **Included in the backup but arguably wrong:** `storage_locations.config` (encrypted with `APP_KEY`, holds S3 key and
  secret) is **not** blanked without `--with-secrets`, so the archive holds ciphertext that cannot be read after a restore on
  another `APP_KEY`; the restored locations then fail to decrypt. Either blank `config` for `s3` rows like `clients` tokens, or
  document it ([backup-restore.md](backup-restore.md) covers `APP_KEY` only for settings). Code, not schema; listed in the
  fix-list. `alerts` (rebuilt by `alerts:check`) and `discovered_devices` (rebuilt, but holds the user's `ignored_at`) are
  fine to include.

## 2. Conventions the consolidated schema follows (and where today's schema breaks them)

Proposed, from what the newer tables already do: snake_case; `*_id` for foreign keys; `*_at` timestamp for a moment *and* for a
flag with a date (`favorited_at`, `hidden_at`, `ignored_at`, `wanted_at`: null = off); `*_key` for normalised comparison keys;
`string(n)` with the smallest honest `n` for enumerations (never `enum()`); JSON for structured blobs nobody queries by column;
typed columns for anything a smart playlist or report range-queries; `unsignedBigInteger` ids; every FK has an explicit
`ON DELETE`; `created_at`/`updated_at` on rows people edit, none on pure log or pivot rows.

## 3. Review findings (severity-ordered)

Severity: **High** = can lose or duplicate data, or will hurt at 1.0 scale; **Medium** = wrong or fragile, cheap to fix before
the squash, costly after; **Low** = tidiness, decide once and leave.

### High

**1. `metadata`: no uniqueness, wide index risk, wrong index shape.** `Enrichment::save()` is `updateOrCreate` on
`(metadatable_type, metadatable_id, key, source)`, but the table has no unique index on it: two queue workers racing (the enrichment jobs run on parallel queue workers) can insert twice, and `->value('value')` then returns one of them. The only indexes are the
`morphs` pair `(metadatable_type, metadatable_id)` and `key`. Real query patterns: per record and key
(`$model->metadata()->where('key', ...)`, many call sites, `Enrichment::backlog`'s correlated `whereHas`), and global by
key+source (`LibraryMerger` `dlna_url`/`files`, `DlnaTrackLookup` type+key). Proposed: widths `key` string(64), `source`
string(48), `type` string(16); **unique** `(metadatable_type, metadatable_id, key, source)` replacing the `morphs` index (its
prefix serves the old lookups); index `(key, source)` replacing `key`. Width matters: today's three `string(255)` columns in one
index would be 3,060 bytes of a 3,072 limit (utf8mb4, InnoDB DYNAMIC). *Existing data:* needs a duplicates query and a dedupe
before the unique index (keep the newest per tuple). Also check that nothing stores several values under one key+source
(`external_id` of a merged track: `LibraryPlayback` reads `->where('key','external_id')->where('source','spotify')->pluck`, which
suggests several). **Resolve that first; if multi-valued keys exist the unique index must include `value`'s hash or be skipped.**
`parent_id` (self FK, only ever written as `null` by `Enrichment::save`) is dead: drop (Low, same migration).

**2. `plays`: the one big table has no plain `played_at` index.** Indexes: `(device_id, played_at)`, `track_id`, `source_type`,
FK on `radio_station_id`. Queries that do not start with a device: `PlayHistory` (`orderByDesc('played_at')` over all devices,
`whereDate('played_at', ...)`), `RecentTracks` (`whereNotNull('track_id')->orderByDesc('played_at')->limit(120)`),
`StatsController` aggregates, `DeviceCard` `latest('played_at')`, and the planned retention and history API (8.8). On a
year of listening that is a filesort of 100k+ rows per page view. Proposed: index `played_at`; keep `(device_id, played_at)`;
drop `plays_source_type_index` only if the stats `GROUP BY source_type` stays small (8 values: an index barely helps, keep as
is if unsure). Code side, not schema: `whereDate('played_at', '>=', $d)` wraps the column in `DATE()` on both engines and cannot
use any index; use `where('played_at', '>=', startOfDay)`.

**3. `plays.device_id ON DELETE CASCADE`: removing a device deletes its listening history** (and `track_id` is already
`SET NULL` precisely to keep text-only plays). Decide on purpose: either keep (history belongs to the device; document it in the
delete confirmation) or make it `SET NULL` with `device_id` nullable (stats and history code must then cope with a null
device; `Play::device` is used in views). Question 2 in section 8. No change before the squash if the answer is "keep".

**4. `device_meta`: no unique `(device_id, key)`, unlike `radio_station_meta`.** Code reads `->where('key', 'spotify_connect_name')
->value('value')` and the multiroom JID lookups assume one row per key; concurrent writers (listeners, `device:sync-sources`)
can duplicate. Proposed: unique `(device_id, key)` (drop the `(key, value)` index only if `MultiRoomPeers::devices()` finds JIDs
through it: it does (`whereIn value`), so keep it). *Existing data:* duplicates query first. `value` is `string(255)`: fine.

### Medium

**5. `name_key` is nullable on `artists`, `albums`, `tracks`** (and `catalogue_albums`, `wanted_tracks`, `physical_items` already
NOT NULL). It was nullable only so the *add-column* migration could run on existing rows; a `saving` hook sets it on every
write. Proposed: NOT NULL in the consolidated create. *Existing data:* `select count(*) ... where name_key is null` on production
must be 0 (if not, `Normalizer` backfill first); pre-squash migration `change()` to NOT NULL, which rebuilds the table on SQLite
(fine: Laravel 12 does it natively; Control has no library tables with data).

**6. Redundant indexes (written before the composites existed).** `tracks_album_id_index` is the prefix of
`(album_id, name_key)`, `tracks_artist_id_index` of `(artist_id, name_key)`: drop both (InnoDB still finds an index for the FK).
`albums` and `catalogue_*` are clean. `physical_items.medium` (3 to 5 distinct values) is not selective: drop. `alerts (kind, key)`:
all lookups use `active_key` (unique) or `resolved_at`; the only `where('kind', ...)` runs on the already loaded active
collection: drop unless history by kind is planned. `plays_source_type_index`: see finding 2. Each drop saves write cost on the
large tables (`tracks`, `plays`).

**7. Enum columns: `clients.type`, `clients.status`.** MariaDB `ENUM`, SQLite `varchar` plus a `CHECK` (the SQLite dump shows
it), so adding a value later is an `ALTER` on MariaDB and a **table rebuild** on SQLite. Everything else in the schema uses
`string(n)`. Proposed: `string(16)` with the same defaults. Existing data: none lost (`ALTER ... MODIFY`).

**8. Unique `playlist_track (playlist_id, track_id)` forbids the same track twice in a playlist.** Spotify and most user
playlists allow it; `PlaylistEditor` probably guards in code. If duplicates are meant to be impossible, say so in the plan and
the docs; if not, drop the unique and keep `(playlist_id, position)`. Question 3.

**9. `file_analyses`: wide rows and a width mismatch.** `fingerprint` (text, ~3 KB), `waveform`, `waveform_peak` (JSON, ~1.5 KB
each), `sections`, `stems` sit in the same row as the typed numeric columns smart playlists range-query; reports join this table
and any `select *` drags the blobs along (`LibraryReports::tracked()` joins it). At 50k tracks that is 250 to 500 MB in one table.
Proposed: keep one table for the squash (splitting is a refactor of `FileAnalysis`, `NowPlayingWaveform`, the stages), but
(a) `content_hash` `string(255)` becomes `string(64)` like `files.hash`, (b) add a rule to `analysis` code: list queries select
named columns, never the blobs, and (c) record the split (`file_analysis_blobs`) as a post-1.0 option in the roadmap, because it
can only be done as a data-moving migration. `key`/`mode` column names need quoting on some drivers; Laravel does it, no change.

**10. Names: 255 characters.** `artists.name`, `albums.name`, `tracks.name` are `varchar(255)`; longer DLNA tags are shortened
on save (`Normalizer::storedName()`, [database.md](database.md)). Widening is **not** possible as things stand: `artists
(name, source)` and `albums (artist_id, name, source)` are unique indexes, and 2 x `varchar(512)` plus `artist_id` is 3,076
bytes, over InnoDB's 3,072. Proposed: keep 255 and truncation (decision, not a defect); if widening is ever wanted, replace
those uniques first with `(artist_id, name_key)` style keys. Are the `name`/`source` uniques needed at all? They are the
fallback find in `LibraryIdentity` and on SQLite they compare case-sensitively while MariaDB does not (finding 13). Keep.

**11. JSON columns are not the same thing on both engines.** MariaDB `json()` is `longtext` + `CHECK json_valid()`, SQLite `text`
with no check: invalid JSON is rejected on one and stored on the other. All JSON columns are read through casts, so this is
tolerable; do **not** add JSON-function SQL (`JSON_EXTRACT`, `->` queries) anywhere Control runs. Today none exists
(`LibraryReports` uses `!= '[]'` and `LIKE` on JSON text, which works on both). `mood_tags like '%"name":"x"%'` is a text
match: fine, but case rules differ (finding 13).

**12. Backup holds `storage_locations.config` ciphertext without `--with-secrets`.** See section 1; code change, no schema change.

**13. Collation and case.** All app tables are `utf8mb4_unicode_ci` (explicit in `config/database.php`), the database default
of a fresh MariaDB 11.4 is `utf8mb4_uca1400_ai_ci` (the `migrations` table is `unicode_ci` too, so no mixed-collation join
today). Consequences: (a) MariaDB unique indexes and `=` compare case- and accent-insensitively, SQLite compares bytes, so
`artists (name, source)` accepts `Beyonce` and `Beyoncé` on SQLite and rejects the second on MariaDB. `name_key` is already
lowercase and unaccented, so the code is safe; **raw `name` comparisons are not portable**. (b) `profile:upgrade` and
`db:import-sqlite` copy SQLite rows into MariaDB: a unique-index collision that SQLite allowed is *skipped and listed*, never
silent (existing behaviour); Control has no library tables, so only `devices`/`plays`/`radio_*` matter. (c) `PAD SPACE`:
`'a'` and `'a '` are equal in `unicode_ci`; keys are trimmed, fine. Proposed: pin `--character-set-server=utf8mb4
--collation-server=utf8mb4_unicode_ci` in `compose.yaml` and the Ansible template so tables made by hand or by a package match;
no table change.

**14. `catalogue_albums`/`album_availability`: "exactly one FK" and "not both wanted and ignored" are model rules.** Correct and
documented (no `CHECK` for SQLite parity). Add model tests (exist) and make the consolidated migrations carry the comment; no change.

### Low

15. **Naming:** `devices.last_seen` (the rest: `last_seen_at`) is an API field name too (`DeviceListResource.last_seen`); `dlna_servers.ip`
    (others `ip_address`); booleans `devices.hidden`, `device_sources.hidden` (others use `hidden_at`/`*_at`);
    `plays.played_at`/`ended_at` versus `radio_plays.started_at`; `files.mtime` (unix seconds, not `_at`, by design); `devices.device_*`
    prefixes and `device_driver` (class) versus `device_driver_name` (label). Renaming costs API, MQTT and client changes for no
    behaviour: **do not rename**, write the exceptions down here. The one cheap fix: `dlna_servers.ip` stays.
16. **Types:** `tracks.duration` signed `int`, `plays.duration`/`files.duration` unsigned: unify to `unsignedInteger` in the consolidated
    create (a track never has a negative duration; check no `-1` sentinel: grep `duration` writes). `albums.released_at` is a
    `date` but the API and `ReleaseDate` handle partial dates ("2023"): verify what the scanner writes for a year-only tag (strict mode
    rejects `2023-00-00`); `catalogue_albums` solved it with `string(10)`. `multiroom_presets.device_ids` JSON of ids has no integrity
    (a deleted device leaves a stale id; harmless, the activation skips it).
17. **Timestamps absent by design:** `radio_plays`, `radio_station_meta`, `client_device`, `genreables`, `catalogue_artists`
    (sync state), `id_aliases` (`merged_at`). Fine; `radio_station_meta.value` is NOT NULL while `device_meta.value` is nullable: harmless.
18. **Unused framework tables** `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `password_reset_tokens`:
    sessions, cache and queue run on Redis in all profiles ([profiles.md](profiles.md)), there is no password reset. `failed_jobs` and
    `jobs` are read by `QueueInspector` (database driver supported), `QUEUE_CONNECTION=database` is a legitimate dev fallback.
    Proposed: **drop `password_reset_tokens`**; keep the rest (they cost nothing and the fallback is real); question 4.
19. **`devices.ip_address` is nullable and not unique** (Spotify's virtual device has none). Discovery dedupes in code. A unique index
    over a nullable column works on both engines (NULLs distinct): add `unique(ip_address)` only if two devices may never share an
    address; B&O converters and Sonos satellites may, so: no.
20. **`metadata.value` is `text` (64 KB).** Lyrics (`lyrics_synced`) and bios fit; a very long synced lyric could not. MariaDB strict
    mode would reject the row (SQLite would not): use `mediumText` for `metadata.value`. Cheap, no data impact.
21. **Orphans (no FK possible or enforced):**
    - `metadata`, `genreables`: polymorphic, no FK. `LibraryRemover` deletes both (`metadata()->delete()`, `genreRelation()->detach()`).
      `LibraryMerger` re-points them; `PlaylistEditor::delete` and `AlbumController` delete paths were **not** audited. Add an
      orphan query for both to the rehearsal (below) and `library-health`.
    - `files` outlive their `track_files` (cascade runs the other way): intended, reported as orphans by `LibraryReports`.
    - `id_aliases`: no FK on purpose (`old_id` is gone; `new_id` may be removed: a real 404).
    - `catalogue_albums.album_id`, `physical_items.album_id`/`file_id`, `wanted_tracks.track_id`, `alerts.device_id`: `SET NULL`,
      right. `album_availability.album_id`/`catalogue_album_id` cascade: right (a derived answer).
    - `tracks.album_id` and `artist_id` are both nullable with `cascade`: an artist delete removes its albums and tracks, whose
      `plays.track_id` become `SET NULL` (history stays as text) and whose `track_files` cascade (the files stay). Good.
22. **MariaDB-only or SQLite-hostile constructs in the migrations:** none blocking. `->after()` (about 30 uses) is ignored on SQLite
    (column order differs between engines; the dump compares by name). `change()` (3 uses: `plays.track_id`, `devices.ip_address`,
    `users.email`) runs natively on SQLite in Laravel 12 (proved: the harness migrates SQLite). Raw SQL: only
    `split_local_files_from_dlna_library` (query builder with `whereExists`, portable). Five migrations call app code
    (`Artisan::call('library:sync-genres')`, `ServiceCredentials::encryptPlaintext()`, `SpotifyLibraryPurge::run()`, and the `FileAnalysis`/`Metadata`
    models in `prune_weak_mood_tags` and the two `requeue_*`): **a migration that calls app classes breaks the day those classes change**; this is the strongest argument to drop them in
    the squash (section 4). No `enum` other than finding 7; no JSON functions.

### Checked and fine

`alerts.active_key` unique (one active alert per `(kind, key)`, NULLs distinct on both engines); `id_aliases` unique
`(entity, old_id)` and `(entity, new_id)` index; `catalogue_albums` unique `(artist_id, release_group_mbid)`; `album_availability` two
uniques over nullable FKs; `wanted_tracks (artist_key, title_key)` (2,040 bytes, within limits); `files (storage_location_id,
path_hash)` (the 1,024-character path stays out of any index); `tracks (external_id, source)`; FK `ON DELETE` is explicit on all 33
foreign keys; `bigint unsigned` ids everywhere that grows (`plays`, `metadata`, `radio_plays`, `file_analyses`); `genreables` unique
plus morph index; `files.hash` non-unique on purpose (duplicates are a report).

### Which migrations alter or drop things (final column set = the baseline dump)

29 migrations create (three of them also alter another table or run app code: `create_device_meta_and_remove_device_uuid`, `create_settings_table`, `create_genres_tables`), 27 only alter, 6 are data fixes. Net effect that the consolidated creates must reproduce:

| Table | Alterations over the creates | Final shape (see dump) |
|---|---|---|
| `devices` | drops `uuid` (`create_device_meta...`), `ip_address` nullable, `+hidden` | no `uuid`; `ip_address` null |
| `users` | `email` nullable | `email` null, unique |
| `plays` | `track_id` NOT NULL cascade then nullable `SET NULL` (drop FK, `change()`, re-add), `+source_type, radio_name, source_name, ended_at, skipped, radio_station_id, track_name, artist_name, album_name, image_url, duration`, indexes `source_type`, `track_id` | as dump; FK on `track_id` is `SET NULL` |
| `albums` | `+colors, favorited_at, name_key` | |
| `artists` | `+favorited_at, name_key` | |
| `tracks` | `+name_key, favorited_at`, indexes `album_id`, `artist_id`, `(album_id,name_key)`, `(artist_id,name_key)` | |
| `device_sources` | `+hidden, sort_order` | |
| `clients` | `+pairing_code (unique, backfilled), battery_percent, battery_charging, hidden_sources` | |
| `playlists` | `+last_played_at (index), rules, refreshed_at` | |
| `radio_stations` | `+favorited_at, last_played_at, genre` | |
| `files` | `+hidden_at (index)` | |
| `file_analyses` | `+waveform, quality columns (10), analyzed_at, waveform_peak, sections, stems` | 42 columns |

Nothing is dropped except `devices.uuid` and the temporary FK of `plays.track_id`; there are no add-drop-add column cycles left
besides that FK. The `->after()` positions disappear in the consolidated files; lay columns out in a logical order.

## 4. Data migrations versus schema migrations

| Migration | Kind | Fresh install needs it? | In the squash |
|---|---|---|---|
| all `create_*` (29 files, see inventory) | schema-create | yes | become one file per table |
| all `add_*`, `extend_plays`, `make_email_nullable`, `create_device_meta_and_remove_device_uuid` (the drop part) | schema-alter | yes (as part of the create) | folded into the create |
| `add_pairing_code_to_clients_table` (backfill loop) | schema-alter + data | **no** (no clients yet) | fold column, drop loop |
| `create_genres_tables` (`Artisan::call('library:sync-genres')`) | schema-create + data | **no** (no library) | drop the call; `library:sync-genres` stays a command |
| `requeue_artist_audiodb_for_gallery` | data-fix | no | drop |
| `requeue_audiodb_after_dead_public_key` | data-fix | no | drop |
| `prune_weak_mood_tags` | data-fix | no | drop |
| `split_local_files_from_dlna_library` | data-fix | no | drop (a fresh install never had `dlna:local`) |
| `encrypt_plaintext_service_secrets` | data-fix | no (settings empty); **calls `ServiceCredentials::encryptPlaintext()`** | drop; **must never run after the squash**: the method is app code that will change |
| `drop_spotify_library_data` | data purge | **never** (it deletes library rows by source; on a fresh install it is a no-op today and **dangerous** if a later install ever has Spotify-sourced rows) | drop; `library:drop-spotify` stays as a command |

No migration seeds data a fresh install needs: the only seed is `DatabaseSeeder` (the admin user), outside the migrations, and
`settings` defaults live in code. **Keep it that way**: after 1.0 a data fix is a normal migration only when it is idempotent,
fast, uses `DB::table` (no models, no app classes) and is safe on a fresh database; otherwise it is an artisan command called
from the upgrade notes.

The two `requeue_*` and `prune_weak_mood_tags` migrations have run on production and dev (the `migrations` table says so) and
change nothing on a fresh database. Dropping them loses nothing.

## 5. The fix-list before the squash

Ordered by "do first". Each: change, why, risk for existing data, migration on production. **None is implemented here.** All are
forward migrations dated before the squash, so they run on production like any other; the squash then consolidates the *result*,
and `scripts/schema/baseline/` is regenerated in the same commit as each one. Recommended: do 1 to 7 and 10; decide 8, 9, 11 with
the owner; 12 to 14 are code.

| # | Change | Why | Risk for existing data | Production migration |
|---|---|---|---|---|
| 0 | **Dump production and dev (`dump.php`) and diff against the baseline.** | the squash proof assumes production equals the migrations | none (read-only) | no |
| 1 | `metadata`: first resolve whether a key+source can hold several values; query duplicates; dedupe; widths `key` 64, `source` 48, `type` 16, `value` mediumText; unique `(metadatable_type, metadatable_id, key, source)`; index `(key, source)`; drop `morphs` index, `key` index, `parent_id`. | finding 1, 20 | duplicates are removed (keep newest); a longer `key`/`source` than the new width would be rejected: `max(length(key))` first | yes |
| 2 | `plays`: index `played_at`. (Code: replace `whereDate` ranges.) | finding 2 | none; index build on a big table, minutes | yes |
| 3 | `device_meta`: unique `(device_id, key)` after dedupe. | finding 4 | duplicates removed (newest wins) | yes |
| 4 | `tracks`: drop `tracks_album_id_index`, `tracks_artist_id_index`. | finding 6 | none | yes |
| 5 | `artists`, `albums`, `tracks`: `name_key` NOT NULL (after a zero-null check or backfill). | finding 5 | a null row fails the migration: check first | yes |
| 6 | `clients.type`, `clients.status`: `string(16)` instead of `enum`. | finding 7 | none | yes |
| 7 | `physical_items.medium` and `alerts (kind, key)`: drop the indexes. Drop `password_reset_tokens` (and its entries in `Snapshot::SECRET_TABLES`, with `personal_access_tokens`). | findings 6, 18 | none | yes |
| 8 | `file_analyses.content_hash` `string(64)`; `duration` columns unsigned. | findings 9, 16 | `max(length(content_hash))` must be 64 (it is a sha256 hex of `files.hash`) | yes |
| 9 | Decide `plays.device_id` (keep cascade or `SET NULL`) and `playlist_track` unique. | findings 3, 8 | `SET NULL` needs a nullable `device_id` and code changes | maybe |
| 10 | `ImportSqlite::SKIP` gains `failed_jobs` and `Snapshot` uses it alone; `backup:run` blanks `storage_locations.config` of `s3` rows without secrets (or documents it). | section 1 | none | no (code) |
| 11 | Pin the MariaDB server collation in `compose.yaml` and the Ansible template. | finding 13 | none for existing tables | no (infra) |
| 12 | Add an orphan check for `metadata` and `genreables` (polymorphic targets that no longer exist) to the library health report and the rehearsal. | finding 21 | read-only | no |
| 13 | Verify `albums.released_at` and the scanner for year-only dates; if values are lost, make it `string(10)` like `catalogue_albums`. | finding 16 | data conversion only if the column is changed | maybe |
| 14 | Add the planned-feature tables the owner has decided on (question 1) as ordinary `create_*` migrations, so they land in the squash. | roadmap 14.3 | none | yes |

## 6. Cross-check: models versus migrations

`scripts/schema/schema.sh crosscheck` (booted models against a freshly migrated SQLite; checks `$fillable`, `$casts`,
`$attributes`, `timestamps`):

- **Columns named by a model but missing from its table: none. Timestamps flag versus columns: all agree.**
- **Models without a table: none** (32 models, 32 tables).
- **Tables without a model:** `cache`, `cache_locks`, `failed_jobs`, `job_batches`, `jobs`, `migrations`, `password_reset_tokens`,
  `sessions` (framework; `password_reset_tokens` is dead, finding 18), the pivots `client_device`, `genreables`, `playlist_track`
  (relations, plus raw `DB::table('playlist_track')` in the playlist code), and **`id_aliases`** (read and written only through
  `DB::table` in `IdAliases`/`LibraryMerger`; correct, a model would add nothing, but note it so nobody "fixes" it).
- Two models on different tables share a name pattern but no table: `DiscoveredDeviceRecord` to `discovered_devices`, `Setting` to
  `settings`, `RadioPlay` to `radio_plays`: all resolved.
- Reverse direction (columns no code reads) was **not** swept: it needs a usage grep per column. Known candidates:
  `metadata.parent_id`, `clients.metadata`, `devices.device_driver_name` (both used), `albums.images` versus the processed artwork;
  none is removed by this plan.
- A claim in the task brief to correct: `plays` has `played_at`/`ended_at`, **not** `started_at` (`radio_plays` does).

## 7. The squash design (done in one sitting, last step before the release tag)

### 7.1 Shape

One file per table, named for the table, ordered by foreign-key dependency, dated at the squash (so they sort **after** every
old migration name; `Snapshot::latestMigration()` and `BackupRestorer` compare names with `max()`, and a post-squash name that
sorts before the old rows would make a pre-squash backup look newer than the code):

`2026_MM_DD_0001xx_create_<table>_table.php`, the `xx` giving the order. Order (parents first): `users`, `password_reset_tokens` (if
kept), `sessions`, `cache`, `jobs`; `settings`, `admin_tokens`, `devices`, `device_meta`, `device_sources`, `discovered_devices`,
`dlna_servers`, `multiroom_presets`, `sync_groups`, `sync_group_members`, `clients`, `client_device`, `radio_stations`,
`radio_station_meta`, `radio_plays`, `artists`, `albums`, `tracks`, `metadata`, `genres`, `genreables`, `playlists`,
`playlist_track`, `plays` (needs `devices`, `tracks`, `radio_stations`), `storage_locations`, `files`, `track_files`,
`file_analyses`, `alerts`, `id_aliases`, `catalogue_artists`, `catalogue_albums`, `album_availability`, `wanted_tracks`,
`physical_items` (needs `albums`, `files`). Tables that Laravel's stock files create in groups (`cache` + `cache_locks`, `jobs` +
`job_batches` + `failed_jobs`) stay one file per *group*: splitting them is churn and "one per table" means one per table the app owns.
Each file's `down()` drops the table. The old comments ("the schema review consolidates this") are replaced by a one-line header
pointing at this document.

Rules for writing them: copy the final shape from the **baseline dump**, not from the old files; constraint and index names must
equal the baseline's (Laravel derives them from table and columns, so the same call gives the same name; the diff checks it);
no `->after()`; no `enum()`; no model or app class inside a migration; no `Artisan::call`.

### 7.2 Proof (the harness)

1. Before: `schema.sh dump all` gives the baselines (done: committed, regenerate only with a fix of section 5).
2. Squash: delete the 62 files, add the consolidated ones (one commit).
3. `schema.sh check` must print `mariadb: identical` and `sqlite: identical` (exit 0). Any difference is a defect in a
   consolidated file, never a reason to edit the baseline.
4. `schema.sh crosscheck` unchanged; `composer test` green (tests migrate in-memory SQLite from the new files).
5. Against the owner's **existing** databases: dump production and dev with `dump.php` (README) and diff with the baseline; this
   proves the thing the baseline mechanism assumes (production equals what the files used to build).
6. Restore rehearsal: `backup:run` on a pre-squash checkout, `backup:restore` into a fresh database built by the new set
   (`backup-restore.md` already supports a fresh `migrate`); row counts equal.

### 7.3 Marking the existing databases as migrated

Laravel ignores `migrations` rows whose file is gone, but runs every file without a row: on production the consolidated `create_*`
files would try to create existing tables and fail (or, worse, a guarded one would half-run). So the squash ships a **one-time
`php artisan schema:baseline`** command (new, small, tested; delete after a few releases):

- Preconditions, all checked, none optional: the `migrations` table exists; it contains **the last pre-squash migration**
  (`2026_10_31_100000_add_stems_to_file_analyses_table`, or the final one of the tag; the name is a constant of the command)
  *and* every pre-squash name before it (the whole list is embedded: the command refuses a database that is missing any, with
  "upgrade to release X.Y first, then to this one"); it contains **none** of the consolidated names.
- Action, in one transaction: insert the consolidated names with the next batch number. Old rows stay (harmless, and they keep
  `migrate:status` honest about history).
- Idempotent: a second run, a fresh database (no `migrations` table, or empty) and an already-baselined database do nothing
  and exit 0 ("nothing to do").
- Where it runs: **before `migrate`** in `ansible/deploy.yml` (line 89), in the image's start script for Docker-only installs, and
  in `composer setup`'s dev path. A guard in each consolidated file (`if (Schema::hasTable(...)) return;`) was **rejected**: it
  hides real drift and half-built tables, which is exactly what the proof is for. But the command must therefore run on every path
  that runs `migrate`; the install docs list them. `Snapshot::latestMigration()` keeps working because the new names sort last.
- The local dev database is baselined the same way (`schema:baseline`, nothing manual).

### 7.4 Upgrades and what comes after

- An upgrade from **any pre-squash tag** must first migrate to the **last pre-squash tag** (it contains every old file), then to
  the squash release: `schema:baseline` enforces it by refusing otherwise. State this in the release notes and in the upgrade doc
  (`9a`). Anyone on 0.x older than that tag upgrades in two steps, there is no shortcut and no support for skipping.
- A **fresh install** runs only the consolidated files; a Control install does the same on SQLite (proved by the harness).
- After 1.0: **forward-only.** No consolidated file is edited, renamed or deleted; every schema change is a new, dated migration
  with a working `down()`, written to run on MariaDB and SQLite (rules of section 3: no `enum`, no JSON functions, `change()`
  checked on SQLite). `scripts/schema/schema.sh check` is run in CI against a baseline updated *with* each migration, and
  `tests/` gets a test that fails when a file older than the release tag changes (hash list), so the rule is enforced rather than
  remembered.
- Later squashes (if ever) follow the same recipe with `schema:baseline` generalised: the constant and the name list move.

### 7.5 Backup and import interplay

`Snapshot::latestMigrationFile()` and `BackupRestorer` compare the archive's `latest_migration` with the shipped one by name.
Archives made before the squash carry an old name, which sorts **before** the consolidated names: a restore of a pre-squash
archive into a post-squash install is accepted (same schema, proved by the harness). The reverse (a post-squash archive into a
pre-squash install) is refused as newer. Both directions are what the rule should be; add a test with a fake pre-squash name when
the squash lands.

## 8. Questions for the owner

1. **Planned tables** (`device_links`, `physical_locations`, scenes, podcasts/episodes/`playback_positions`, `push_subscriptions`,
   `exports`, `media_servers`): which of them are really in 1.0? Each needs a short design (names, keys, `ON DELETE`) and a
   decision before it can be folded in; the review does not invent them. Undecided ones simply append after the squash.
2. Deleting a device: lose its play history (today), or keep the plays with no device (`SET NULL`)?
3. May a track appear twice in one playlist (`playlist_track` unique)?
4. Keep the unused framework tables (`sessions`, `cache*`, `jobs*`) as the no-Redis fallback, or drop them from the schema?
5. Is it acceptable that a user on a pre-squash tag must hop through the last pre-squash release (the baseline command refuses
   to guess)?
