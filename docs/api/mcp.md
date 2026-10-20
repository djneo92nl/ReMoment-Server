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
| `queue` | list Up Next, or jump to a position |
| `sources` | list/activate sources; list/run source controls (CD, FM presets) |
| `radio` | list stations, play one, favorite one |
| `multiroom` | get, join, leave |
| `device_settings` | sound-adjustment, bluetooth, info (rename), sleep-timer (`docs/api/device-settings.md`) |
| `search_library`, `browse_library` | search; lists and details of artists, albums, genres, playlists, recent, favorites |
| `set_favorite` | favorite an album, artist or track |
| `play_from_library` | play a track (or a Spotify track by `spotify_track_id`), an album (optionally limited to a `source`), artist or playlist on a device |
| `manage_playlist` | create (normal or smart), rename, delete (`confirm`), add (tracks, or an album with `source`)/remove/move tracks, set smart rules |
| `album_spotify` | an album as Spotify has it (`tracks`, with the library track holding each), or `fill` the library album from it |
| `library_maintenance` | **ADMIN**: `scan_dlna` and `verify_files` (queued), `enrich`, `merge_duplicates` (`dry_run`, else `confirm`), `sync_genres`, `refresh_smart_playlists` |
| `remove_library_item` | **ADMIN**, destructive: track, album or artist; `confirm` required, DLNA-available items are kept (tracks: `force`) |
| `storage` | **ADMIN**: locations, test/scan a location (queued), import, reorganize (`dry_run`, else `confirm`), track files, verify a file |

Destructive actions answer `confirmation_required (422)` until `confirm=true`. Queued actions need the queue worker.

## Tests

`tests/Feature/Mcp/ReMomentMcpTest.php` (fake drivers, as in the API tests). Try it by hand with `npx @modelcontextprotocol/inspector` against `http://localhost/mcp`.
