# MCP server

An [MCP](https://modelcontextprotocol.io) server lets an AI agent control the player devices and manage the library: `POST /mcp` (streamable HTTP, `laravel/mcp`; `GET`/`DELETE /mcp` are answered as the spec requires). Code: `app/Mcp/` (`Servers/ReMomentServer`, `Tools/`, `ApiBridge`), route in `routes/ai.php`.

## Auth

Same model as the REST API (see CLAUDE.md, Auth Model): control and browse tools are open, no login. The **ADMIN** tools are only listed and only work when the request carries the admin token: `Authorization: Bearer rmt_…` from `POST /api/admin/login` (`docs/api/admin-auth.md`). No new accounts, and the MCP endpoint is throttled to 120 requests a minute per client.

Example client config (Claude Code):

```bash
claude mcp add --transport http remoment http://remoment.local/mcp
# with admin tools:
claude mcp add --transport http remoment http://remoment.local/mcp --header "Authorization: Bearer rmt_…"
```

## How it works

Device and library tools do not re-implement anything: `ApiBridge` dispatches an internal request to the REST API (forwarding the bearer token), so validation, capability checks and errors are exactly the API's. A failing call becomes a tool error whose text starts with the API's code and status, e.g. `unreachable (503): Device is not reachable.`, `unsupported (422): …`, `invalid`, `driver_error (502)`, `not_playable`. Tools that have no API route (playlist editing, maintenance, removal) call the domain classes directly: `PlaylistEditor`, the `library:*` commands, `LibraryRemover`.

## Tools

| Tool | What it does |
|---|---|
| `list_devices`, `get_device` | `GET /devices`, `GET /devices/{id}` (now playing, capabilities, modes) |
| `playback_control` | play, pause, stop, next, previous, seek, shuffle, repeat, like, power |
| `volume` | read or set volume and mute |
| `queue` | `action`: `list` (default) Up Next, `jump`, `add` / `next` (track, album, artist or playlist at the end / after the current track), `remove`, `move`, `clear` (`confirm=true`); see `docs/api/queue.md` |
| `sources` | list/activate sources; list/run source controls (CD, FM presets) |
| `jellyfin` | Jellyfin on a TV: list continue watching and next up, or start an `item_id` (optional `position_ticks`) in the Jellyfin app on the TV; `GET /devices/{id}/jellyfin`, `POST …/jellyfin/play` |
| `remote_keys` | a TV's remote keys (`remote_control`): list the supported keys, or press one (`key`, e.g. `ok`, `up`, `volume_up`); `GET/POST /devices/{id}/keys` |
| `radio` | list stations, play one, favorite one |
| `beolink_stream` | the BeoLink stream (a B&O source's audio through the server): address, which B&O device it listens to, who plays it; with `device_id` play it there (`stream_url`, optional `host_device_id` = the B&O device to carry), `stop` stops it |
| `spotify_stream` | the Spotify stream's address and receiver name; with `device_id` start it on that device (`stream_url`) |
| `multiroom` | get, join, leave |
| `move_playback` | move what plays to another device (`POST /devices/{id}/move`): same queue and position for library tracks, station for radio, Spotify transfer; local inputs are refused; see `docs/api/playback-move.md` |
| `sync_group` | sync groups ("fake multiroom"): list, create, add, remove, offset, dissolve; see `docs/api/sync-groups.md` |
| `device_settings` | sound-adjustment, bluetooth, info (rename), sleep-timer (`docs/api/device-settings.md`) |
| `search_library`, `browse_library` | search; lists and details of artists, albums, tracks, genres, playlists, recent, favorites |
| `set_favorite` | favorite an album, artist or track |
| `play_from_library` | play a track (or a Spotify track by `spotify_track_id`), an album (optionally limited to a `source`), artist or playlist on a device, or `type: tracks` to shuffle the whole library |
| `physical` | the physical collection (CDs, records, tapes): `list`, `lookup` a barcode on MusicBrainz, `add`, `update` (also `ripped`), `remove`; an item makes its album owned; see `docs/api/physical.md` |
| `external` | browse an external service (Spotify) for any artist: `search`, `artist`, `album`, and the account's `playlists` / one `playlist` (read live); `play` an item (artist, album, track or playlist) on a device without importing it; nothing is added to the library; see `docs/api/availability.md` |
| `catalogue` | the MusicBrainz discography of an artist (owned / streamable / known per album, filters), the household wishlist, one entry, and `wish`/`unwish`/`ignore`/`unignore`/`wish_song`/`unwish_song`, `new_releases` (open, no admin token); see `docs/api/availability.md` |
| `manage_playlist` | create (normal or smart), rename, delete (`confirm`), add (tracks, or an album with `source`)/remove/move tracks, set smart rules; ids merged away by `library:merge-duplicates` still work (see `docs/api/library.md`) |
| `album_spotify` | an album as Spotify has it (`tracks`, with the library track holding each), or `fill` the library album from it |
| `library_maintenance` | **ADMIN**: `scan_dlna` and `verify_files` (queued), `enrich`, `merge_duplicates` (`dry_run`, else `confirm`), `sync_genres`, `refresh_smart_playlists` |
| `remove_library_item` | **ADMIN**, destructive: track, album or artist; `confirm` required, DLNA-available items are kept (tracks: `force`) |
| `storage` | **ADMIN**: locations, test/scan a location (queued), import, reorganize (`dry_run`, else `confirm`), track files, verify a file |
| `alerts` | `list` the server's alerts (stuck queue, silent scheduler, stale backup, unreachable storage, offline device, down listener; `state`, `since`, `limit`), open to everyone; `ack` an alert needs the admin token; see `docs/architecture/alerts.md` |

Destructive actions answer `confirmation_required (422)` until `confirm=true`. Queued actions need the queue worker.

## Tests

`tests/Feature/Mcp/ReMomentMcpTest.php` (fake drivers, as in the API tests). Try it by hand with `npx @modelcontextprotocol/inspector` against `http://localhost/mcp`.
