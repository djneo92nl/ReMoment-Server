# Admin library settings and maintenance (`/api/admin/library`)

The library form of **Settings › Storage › Library** and the `library:*` maintenance commands, behind the admin token (`401 unauthenticated` without one). Profile feature `library` (standard and full; Control answers `422 not_available_in_profile`). Web and API call `App\Domain\Library\LibrarySettingsAdmin` and `LibraryMaintenance`. MCP: tools `library_settings` and `library_maintenance` (both go through these routes).

```
GET  /api/admin/library/settings               → Settings
PUT  /api/admin/library/settings               { any of the keys below }   → Settings (only the keys sent change)
POST /api/admin/library/maintenance/{action}   { options }                 → { status, command, output? }
```

## Settings

```json
{ "leading_source": "auto", "leading": "files", "add_played_tracks": false, "playback_loudness": false, "nts_enabled": false, "history_keep_months": null }
```

| Key | Values | Notes |
|-----|--------|-------|
| `leading_source` | `auto`, `files`, `dlna`, `all` | what the library pages and API show by default; `leading` is what `auto` resolves to now. There is no `spotify` mode: Spotify extends the library live and never leads |
| `add_played_tracks` | bool | add a played track that is not in the library to it (default off) |
| `playback_loudness` | bool | level the loudness of converted streams; same switch as `PUT /api/admin/storage/playback-loudness` |
| `nts_enabled` | bool | NTS Radio, which reads NTS' unofficial website data: opt-in, off by default |
| `history_keep_months` | 1 to 600, or `null` | months of play history the daily `history:prune` keeps; `null` keeps everything |

`history_keep_months` lives on the History page in the web UI; it is here so one call reads and sets everything. Spotify and Last.fm connections, scrobbling toggles and the Spotify Connect name mapping stay on the web (decided, see the 8b roadmap).

## Maintenance

`{action}` is one of:

| Action | Options | Behaviour |
|--------|---------|-----------|
| `scan_dlna` | `server` (IP), `root` (folder) | queued `library:scan`: `{ status: "queued" }` |
| `verify_files` | | queued `library:verify-files` |
| `enrich` | `limit` (1 to 5000, default 200), `only` (`metadata`, `artist-images`), `dry_run` | queues lookups; runs in the request and answers with the command output |
| `merge_duplicates` | `dry_run`, `confirm` | **cannot be undone** (merged ids disappear, clients get `404` on them): without `dry_run` or `confirm` it answers `422 confirmation_required` |
| `sync_genres` | | rebuilds canonical genres, in the request |
| `refresh_smart_playlists` | `playlist_id` | re-selects smart playlists, in the request |

Answer: `{ "status": "queued" | "ok" | "failed", "command": "library:enrich", "output": "…" }` (`output` for the actions that run in the request). An unknown action is `404`, a bad option `422`. `merge_duplicates`, `sync_genres` and `enrich` run inside the request and can take a while on a large library: give the client a long timeout. Storage scans, the inbox import and reorganize are the storage routes (`/api/admin/storage/*`, in `openapi.yaml` since 2026-11-21).
