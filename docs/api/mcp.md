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
| `edit_tags` | **ADMIN**: tag editor for own files: `show`/`album` (the tags files hold), `update` (`file_id` with `tags`/`clear`/`organize`, or `album_id` with `tags` for all and `files` per file), `cover` (`image_base64`), `remove_cover`, `undo`; safe copy-and-swap write, inbox files never edited; `GET|PUT /admin/files/{id}/tags`, `/admin/albums/{id}/tags`…; see `docs/api/tag-editor.md` |
| `podcasts` | podcasts and their episodes: `list`, `episodes` (`podcast_id`?, `unplayed`, `limit`, `cursor`), `play` (`device_id`, `episode_id`, `resume`), `mark_played`, `mark_all_played`; ADMIN `subscribe` (`feed_url`), `refresh`, `keep` (`keep_episodes`), `unsubscribe` (`confirm`); `GET /podcasts`, `/podcast-episodes`, `POST /devices/{id}/podcast-episodes/{id}/play`, `/admin/podcasts`; see `docs/api/podcasts.md` |
| `continue_listening` | resume positions of audiobooks, long tracks and podcast episodes: `list`, `set` (`kind`, `id`, `position`), `clear`; plays resume by themselves, `resume=false` starts over; `GET/PUT/DELETE /library/positions`; see `docs/api/playback-positions.md` |
| `playback_control` | play, pause, stop, next, previous, seek, shuffle, repeat, like, power |
| `volume` | read or set volume and mute; the answer has `limit`, the device's volume limit now (`null`: none), and a volume above it is lowered to it |
| `queue` | `action`: `list` (default) Up Next, `jump`, `add` / `next` (track, album, artist or playlist at the end / after the current track), `remove`, `move`, `clear` (`confirm=true`); see `docs/api/queue.md` |
| `sources` | list/activate sources; list/run source controls (CD, FM presets) |
| `jellyfin` | Jellyfin on a TV: list continue watching and next up, or start an `item_id` (optional `position_ticks`) in the Jellyfin app on the TV; `GET /devices/{id}/jellyfin`, `POST …/jellyfin/play` |
| `media_servers` | media servers (Jellyfin): without `device_id` list the servers and their capabilities; with it that device's continue watching and next up (normalised items); with `item` (a listed `id`, optional `position` seconds) start it in the server's app on the device; `GET /media-servers`, `GET /devices/{id}/media`, `POST …/media/play`; see `docs/api/media-servers.md` |
| `remote_keys` | a TV's remote keys (`remote_control`): list the supported keys, or press one (`key`, e.g. `ok`, `up`, `volume_up`); `GET/POST /devices/{id}/keys` |
| `radio` | list stations, play one, favorite one; `relays: true` shows the radio relay (`GET /api/radio/relays`: on, manager running, running and restarting relays), no device needed |
| `beolink_stream` | the BeoLink stream (a B&O source's audio through the server): address, which B&O device it listens to, who plays it; with `device_id` play it there (`stream_url`, optional `host_device_id` = the B&O device to carry), `stop` stops it |
| `line_in` | the line-ins of nodes with the line-in role (live, listeners, who plays them); with `device_id` play one there (`stream_url`; `node_id` when several), 2 to 5 s delay, not for TV |
| `rips` | **ADMIN**: rip an audio CD on a node with the ripper role into the library: `nodes` (who can rip, disc as of the last check), `check` (`node_id`: read the disc, then `status` for the MusicBrainz `candidates`), `start` (`node_id`, `release_mbid`), `status` (`id`: progress, percent, rip log), `list`, `cancel` (`id`); `GET /nodes/rippers`, `/admin/rips`; see `docs/api/rips.md` |
| `spotify_stream` | the Spotify stream's address and receiver name; with `device_id` start it on that device (`stream_url`) |
| `multiroom` | get, join, leave |
| `multiroom_presets` | multiroom presets (open): `list`, `create` (`name`, `device_ids` ≥ 2, the first is the host), `update`, `delete`, `activate` (who joined, who failed); see `docs/api/multiroom-presets.md` |
| `manage_devices` | **ADMIN**: add, change and remove devices and the discovery inbox: `models`, `list`, `add`, `update`, `remove` (`confirm`), `found`, `scan` (queued, poll `found`), `add_found`, `add_all_found`, `ignore_found`, `restore_found`; see `docs/api/admin-devices.md` |
| `admin_tokens` | **ADMIN**: the app sign-ins: `list` (never a hash), `revoke` (`confirm`), `revoke_all` (`confirm`, `keep_current`); see `docs/api/admin-tokens.md` |
| `analysis_status` | **ADMIN**: audio analysis (full profile): `status`, `problems` (`stage`), `run`, `analyse` (`file_id`), `retry` (`file_id`, `stage`); see `docs/api/admin-analysis.md` |
| `deep_analysis` | analysis of ONE track on request (open): `request` (`track_id`, `kinds`: `sections`, `stems`, `stem_audio`, `mood`, `section_sound`), `status`, `remove`, `stems` (kept audio), `weights` / `download_weights` (ADMIN), and `similar_sections` (`track_id`, `section`, `limit`): sections of other analysed tracks that sound like one section; see `docs/api/deep-analysis.md`, `docs/api/section-similarity.md` |
| `clients` | **ADMIN**: client registrations: `list`, `get` (with the API token), `approve`, `reject`, `update` (devices, hidden sources, roles), `regenerate_token`, `delete` (`confirm`); see `docs/api/admin-clients.md` |
| `radio_stations` | **ADMIN**: manage the stations themselves: `list`, `get`, `add`, `update` (identifiers; empty removes), `remove` (`confirm`), `search` (stream finder), `add_found`; playing and favourites are `radio`; see `docs/api/admin-radio.md` |
| `server_health` | **ADMIN**: `summary` (broker, scheduler, queue worker, queue, backup, upgrade, alerts, devices with listeners), `services` (cached probes), `check_services` (run them now, optional `only`), `backup_copy` / `set_backup_copy_path` / `test_backup_copy` (the optional second copy of every backup); see `docs/api/admin-health.md` and `docs/api/admin-backup.md` |
| `device_links` | **ADMIN**: sub devices (a Chromecast on a TV's HDMI input, a converter a player borrows): `list` (`device_id`), `suggestions` (`device_id`: what an ASE player lists in its Products), `add` (`device_id` = sub device, `host_device_id`, `kind`, `via`, `meta`), `update` (`id`), `remove` (`id`); the relation itself is in `get_device` (`host`, `sub_devices`) for everyone; see `docs/api/sub-devices.md` |
| `move_playback` | move what plays to another device (`POST /devices/{id}/move`): same queue and position for library tracks, station for radio, Spotify transfer; local inputs are refused; see `docs/api/playback-move.md` |
| `sync_group` | sync groups ("fake multiroom"): list, create, add, remove, offset, dissolve; see `docs/api/sync-groups.md` |
| `device_settings` | sound-adjustment, bluetooth, info (rename), sleep-timer (`docs/api/device-settings.md`) |
| `search_library`, `browse_library` | search; lists and details of artists, albums, tracks, genres, playlists, recent, favorites; kinds `journey` (`id`, `to_id`, `length`) and `artist-tour` (`id`), kind `mixes-with` (with `id`): tracks that mix well with a track; kind `similar` (with `id`): tracks that sound like a track, by their sound embedding ([similar-tracks.md](similar-tracks.md)); kind `related` (with `id`): works related to a track from MusicBrainz ([related-works.md](related-works.md)); kind `concerts` (optional artist `id`, `location`, `limit`): upcoming concerts of library artists from MusicBrainz events, opt-in ([concerts.md](concerts.md)); kind `discover` (`sections`, `limit`, `seed`): the Discover shelves of `GET /api/library/discover` (`docs/api/discover.md`); kind `tracks` also takes the sound filters (`bpm_min`/`bpm_max`, `camelot`, `arousal_*`, `valence_*`, `danceability_min`, `instrumental_min`, `plr_min`, `mood`, `lossless_suspect_max`, `measured_bit_depth_min`, `clipping`) and the lyrics filters `lyrics_language` and `explicit` ([lyrics.md](lyrics.md)), `include=analysis`, `count_only` and `sort=plr\|bpm\|arousal` (`docs/api/sound-query.md`) |
| `set_favorite` | favorite an album, artist or track |
| `play_from_library` | play a track (or a Spotify track by `spotify_track_id`), an album (optionally limited to a `source`), artist or playlist on a device, or `type: tracks` to shuffle the whole library, or `type: similar` for a radio from a track (it, then the 49 that sound most like it), or `type: journey` (`id` to `to_id` through the sound space, Full) or `type: artist_tour` (a tour through an artist), or `type: daily_mix` for today's daily mix (songs you loved and forgot, deep cuts, albums you never played; no `id`) |
| `physical` | the physical collection (CDs, records, tapes): `list`, `lookup` a barcode on MusicBrainz, `add`, `update` (also `ripped`), `remove`, `import` (CSV text: a preview with a token, then the token adds the rows; barcodes are looked up in the background); an item makes its album owned; see `docs/api/physical.md` |
| `tags` | tap to play (NFC tags, QR codes): `get` and `play` a tag by uid (open); with the admin token `list`, `last_scanned`, `add`, `update`, `remove`; see `docs/api/tags.md` |
| `scenes` | scenes (household-wide step lists): `list`, `get`, `activate` (a scene with delays is queued), `create`, `update`, `delete` (a scene also takes `triggers`, `trigger_debounce` and an `alarm`), and a device's sleep timer `sleep`, `sleep_status`, `sleep_cancel`; see `docs/api/scenes.md` |
| `announce` | play a short clip on speakers, after which they go back to what played: `clips` lists them, `play` needs `clip` and `device_ids` (optional `volume`); see `docs/api/announcements.md` |
| `external` | browse an external service (Spotify) for any artist: `search`, `artist`, `album`, and the account's `playlists` / one `playlist` / `saved_albums` / `liked_tracks` / `recent` (read live); `play` an item (artist, album, track or playlist) on a device without importing it; nothing is added to the library; see `docs/api/availability.md` |
| `catalogue` | the MusicBrainz discography of an artist (owned / streamable / known per album, filters), the household wishlist, one entry, and `wish`/`unwish`/`ignore`/`unignore`/`wish_song`/`unwish_song`, `new_releases` (open, no admin token); see `docs/api/availability.md` |
| `manage_playlist` | create (normal or smart), rename, delete (`confirm`), add (tracks, or an album with `source`)/remove/move tracks, set smart rules; ids merged away by `library:merge-duplicates` still work (see `docs/api/library.md`) |
| `album_spotify` | an album as Spotify has it (`tracks`, with the library track holding each), or `fill` the library album from it |
| `library_maintenance` | **ADMIN**: `scan_dlna` and `verify_files` (queued), `enrich`, `merge_duplicates` (`dry_run`, else `confirm`), `sync_genres`, `refresh_smart_playlists`; goes through `POST /api/admin/library/maintenance/{action}`, see `docs/api/admin-library.md` |
| `library_settings` | **ADMIN**: read or change the library settings (leading source, add played tracks, loudness levelling, NTS, history retention); see `docs/api/admin-library.md` |