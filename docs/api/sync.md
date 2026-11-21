# Sync to offline targets (REST API)

Keeps a **target** filled with a rule-defined part of the library: a USB stick or MP3 player on a ReMoment Node, or the iOS app's offline library. It is the repeating, incremental sibling of [export](export.md) and reuses its profiles, naming, covers and loudness option. Design: [offline-sync.md](../architecture/offline-sync.md). Contract: [openapi.yaml](openapi.yaml) (tag `Sync`).

- **Admin token** on every `/admin/sync/*` route; `features.sync` in `GET /api/info` (present when true: Standard or Full profile with own files, and ffmpeg). Control answers `422 not_available_in_profile`. A target belongs to the household, not to a token: revoking an app's token does not delete its target, and the same install finds it again by `client_ref`.
- **The server decides, the target applies.** The server keeps the rules and a last report, never the file list of a target. The target says what it holds (its manifest), the server answers with a plan.
- Built and tested against fakes; **nothing is verified on a real stick, node or iPhone yet.**

## Targets

```
GET|POST        /api/admin/sync/targets
GET|PUT|DELETE  /api/admin/sync/targets/{target}
POST            /api/admin/sync/targets/{target}/estimate     { rules?, free_bytes? }
POST            /api/admin/sync/targets/{target}/plan         { have, free_bytes?, fs?, rules_version? }
POST            /api/admin/sync/targets/{target}/report       { applied, failed, released, owned_bytes, owned_count, free_bytes, plays? }
POST            /api/admin/sync/targets/{target}/plays        { plays: [{ client_id, track_id, started_at, played_seconds }] }   app targets: offline plays
POST            /api/admin/sync/targets/{target}/run          { confirm? }       node targets: Sync now
DELETE          /api/admin/sync/targets/{target}/run                             cancel
POST            /api/admin/sync/targets/{target}/volume       { action: scan|claim }
GET             /api/admin/sync/files/{target}/{key}/{fingerprint}               the file, admin token
GET             /api/sync/files/{target}/{key}/{fingerprint}?expires=&signature= the file, signed
POST            /api/nodes/sync/plan | /api/nodes/sync/report                    the node's own calls (Bearer node token)
```

`{target}` is a ULID. `POST /targets` takes `{ name, kind: app|node, client_ref?, node_id?, rules? }`: an `app` target needs the `client_ref` the install keeps and is **idempotent** on it (`200` with the existing target); a `node` target needs an approved node that declares the `sync-target` role, one target per node (one volume per node). The kind is fixed at creation. `PUT` takes `name` and/or `rules`; a changed rule set bumps `rules_version`.

A target is `{ id, name, kind, client_ref, server_id, node_id, node, rules, rules_version, owned_bytes, owned_count, last_report, last_report_at, last_run, running }`.

### Rules

```json
{
  "selections": [
    { "type": "playlist", "id": 12 },
    { "type": "favorites" },
    { "type": "album", "id": 301 },
    { "type": "recently_added", "limit": 100 },
    { "type": "most_played", "limit": 200, "days": 365 }
  ],
  "profile": "mp3-192",
  "options": { "loudness": "off", "layout": "tree" },
  "budget": { "bytes": 32000000000, "reserve_percent": 5 },
  "fill": "priority",
  "max_churn_percent": 25
}
```

Validated and filled on save; an unknown selection type, profile or option is `422`. `options.folder_cover` and `options.playlists` (m3u8) are **not built yet** and are refused rather than ignored.

- **Selections** are in priority order; within one, tracks have a rank (album disc/track order, playlist order, newest first, most played first, newest favourite first). A track belongs to the first selection that has it. `playlist` includes smart playlists (re-selected at plan time when older than an hour), `artist` is its albums, `favorites` the favourite albums and artists. Ids resolve through the id aliases; an id that no longer exists is skipped with a `selection_not_found` warning, not an error. Only tracks with an own file count (`no_own_file` warning).
- **Profile and options** are the export ones: `highest`, `mp3-*`, `compat`, `aac-*`, `opus-*`, loudness `off|album|track` (baked into transcoded files only, copies stay bit-exact; the album mean is taken over the whole album in the library, so changing the selection never moves a level). `layout: flat` (`Artist - Title.ext`) only for exactly one playlist.
- **Budget and fill.** `budget.bytes` is the most the target may hold (null: free space only); with `free_bytes` in a plan it is held to *owned bytes + free space*, then `reserve_percent` is kept free. `fill`: `all` (default without a budget: everything or `422 over_budget` with the estimate), `priority` (default with a budget: selections in order, a whole album kept or dropped together for album/artist/favorites, tracks independent for the rest; the first unit that does not fit stops its selection, later selections still try), `fill_rest` (priority, then the rest of the budget with favourites, most played, newest).
- **Sticky.** Tracks already on the target stay as long as they are still selected and fit; new space goes to the best ranked new tracks; when the budget forces something out the lowest priority/rank that is already there goes first. **Churn limit:** when only the library moved (the plan's `rules_version` is the current one) a plan adds and drops at most `max_churn_percent` of the owned bytes (0 = unlimited); the first new unit always goes through so nothing starves; the rest is `deferred` to the next sync. The defaults are unverified on a real library.

## Estimate

`POST …/estimate` (stored rules, or `rules` in the body; nothing is stored or prepared): `{ tracks, bytes, wanted, wanted_bytes, copied, transcoded, skipped, over_budget, budget_bytes, deferred, profile, fill, format, warnings }`.

## The plan

`POST …/plan` is **stateless**. Body: `have` (the manifest entries `{ key, fingerprint, path, size, mtime? }`, at most 200,000, a duplicate key is `422`), optional `free_bytes`, `fs` (`vfat` skips files over 4 GB), `rules_version` (the one in the target's manifest).

Answer:

```json
{
  "target_id": "…", "rules_version": 7,
  "add":     [{ "key": "track:4711", "path": "Artist/Album/01 - Title.mp3", "fingerprint": "…", "size": 8123456, "sha256": "…", "state": "ready", "url": "/api/sync/files/…?expires=…&signature=…" }],
  "replace": [{ "…": "as add, plus from_path, from_size" }],
  "move":    [{ "key": "…", "path": "new/path.mp3", "from_path": "old/path.mp3" }],
  "keep": 30211,
  "remove":  [{ "key": "…", "path": "…", "size": 123 }],
  "path_conflicts": [], "totals": { "bytes_to_add": 0, "bytes_freed": 0, "net_bytes": 0, "wanted": 0, "wanted_bytes": 0, "copied": 0, "transcoded": 0, "ready": 0, "preparing": 0, "failed": 0 },
  "warnings": [], "deferred": { "count": 0, "bytes": 0 }, "confirm_required": false, "remove_first": false, "budget_bytes": null
}
```

- **key** `track:{canonical id}` survives renames and merges. **fingerprint** says which bytes: the file's content hash for a copy, a sha1 of the source content, the effective encode (codec, bitrate, rate, channels, gain), the cover limit and the planner version for a transcode or remux: computable without transcoding, shared by every target with the same effective encode.
- **path** is a function of the track alone (`ExportNames::treeParts`: FAT32-safe characters, 60/100 character limits), and **sticky**: a file the target already holds keeps its path unless its own name changed (a `(2)` collision suffix counts as the same name), new ones resolve collisions against every path in use. A rename on the server is a `move`, not a download.
- **move** is a rename, **remove** only ever lists entries of the `have` that was sent: only something the server wrote can be removed. `keep` is a count.
- **state**: `ready` (a copy is always ready; `url` is signed, relative, 6 hours, bound to target, key and fingerprint), `preparing` (a transcode is being made: ask again, same `have`; `url` is null), `failed` (the artifact could not be made, with `error`; retried by a later plan after ten minutes). A plan starts at most `SYNC_PREPARE_AHEAD` (50) artifacts at once.
- **confirm_required**: the plan removes over 30 % of the owned entries or over 500 files, or the selection list is empty: an unattended executor applies nothing destructive and reports `needs_confirmation`. **remove_first**: the additions do not fit the free space, remove before adding.
- Refusals: `422 over_budget` (fill `all`), `nothing_to_sync` (the rules select nothing and the target holds nothing), `encoder_unavailable`, each with the `estimate`; `404` unknown target. With `SYNC_CACHE_GB=0` transcodes are left out with an `artifact_cache_off` warning and copies still sync.

## Files

`GET /api/sync/files/{target}/{key}/{fingerprint}?expires=&signature=` (signature is the authorization, `403` without a valid one) and the same path under `/admin/` with the admin token. A copy is served from the original, a transcode from the artifact cache; both answer **Range** (`206`), so a download resumes; `X-Sync-Sha256` carries the hash when known. The request is checked against the rules *now*: `404 stale` when the fingerprint is no longer the right one (plan again), `409 preparing` while the artifact is being made (ask again shortly, never a failure), `502 failed`.

## Report

`POST …/report` (admin) or `POST /api/nodes/sync/report` (node): `{ rules_version, applied: [key], failed: [{key, error}], released: [key], owned_bytes, owned_count, free_bytes }`. Counts and the owned totals are kept as `last_report`, never the lists. `released` are files the user changed on the target: they are no longer ours.

## Offline plays (app targets)

`POST …/plays` (or `plays` in the report, same rules and the answer then carries `plays`): up to 500 per call, each `{ client_id, track_id, started_at, played_seconds }`. `track_id` is the number in the sync key `track:{id}` (a merged id resolves), `started_at` an ISO 8601 time **with offset** on the phone's clock, `played_seconds` 0 to 86400, `client_id` the app's own id of the play (echoed back in `rejected`).

```json
{ "accepted": 3, "duplicates": 1, "rejected": [{ "client_id": "…", "error": "unknown_track" }], "scrobbled": 2, "too_old": 0 }
```

- **Idempotent.** A play with the same track and start on the same target is a duplicate (`duplicates`), so the app queues plays, sends them, and sends them again until an answer arrives; nothing is stored twice. There is no per-play id on the server: the identity is target + track + start second.
- **History.** A play is written with `source_type: offline`, `source_name` the target's name, `played_at` the original start and `ended_at` start + `played_seconds`; under 30 s counts as a skip, as for a live play. The plays belong to a hidden player device made for the target on its first play (meta `sync_target`), not to a visible device. An old play still reaches the history.
- **Scrobbling** uses the original time: a play younger than 14 days (`SyncPlays::SCROBBLE_MAX_AGE_DAYS`) and not a skip is queued as `ScrobbleToLastfm` with `played_at` as the timestamp, and the job applies the usual rule (at least half the track, 4 minutes at most, a track over 30 s). Older ones are counted in `too_old`: stored, not scrobbled. Last.fm's two-week limit is from its API documentation, **not verified against the live service.**
- Rejected: `bad_started_at` (not a date, or more than 10 minutes ahead of the server: a wrong clock), `unknown_track`. Other errors: `422` for a bad shape or more than 500 plays, `422 not_an_app_target` for a node target, `401` without the admin token.
- Plays are not an MCP tool: it is the app's call.

## The app's contract (what the iOS app does, S5)

The app is not in this repository; this is the whole contract it needs, server half built and tested against fakes, **nothing verified on a phone.**

1. **Find the target:** `POST /api/admin/sync/targets { name, kind: "app", client_ref }`, idempotent on `client_ref` (keep it in the keychain); rules are edited in the app or on the web.
2. **Plan:** read the local manifest, `POST …/plan { have: [{ key, fingerprint, path, size }], free_bytes, rules_version }`; `409`-like `preparing` entries are asked again with the same `have`.
3. **Download** every `add`/`replace` from its signed `url` (relative to the server, valid 6 hours, `Range` resumes, no header needed); `403`/`404 stale` means plan again. Check `size` and `sha256` when given. `move` and `remove` are local renames and deletes of keys in the app's own manifest only.
4. **Report:** `POST …/report { rules_version, applied, failed, released, owned_bytes, owned_count, free_bytes }`.
5. **Plays:** queue every offline play locally with a UUID `client_id`, send them with the report or `POST …/plays` when online, drop a queued play once the answer arrived (accepted or duplicate; a `rejected` one will not succeed by retrying).

## Running a node target

`POST …/run { confirm? }` queues the node job `sync.apply` (`409 target_busy`: one job per target; `422 not_a_node_target`, `node_unavailable` when the node is gone or its `sync-target` role is off). `DELETE …/run` cancels: the node finishes the current file, writes its manifest and stops. `POST …/volume { action: scan }` reads the drive (marker, filesystem, size, free space, what the manifest holds) and writes nothing; `claim` writes the marker `.remoment-sync/target.json` on a volume without one ("Use this drive"), a volume with a marker is refused and nothing is ever formatted. The answer of a scan or claim is the result of the node job (`GET /api/admin/nodes/{id}/jobs`).

The run shows in the task tray (`sync:{target}`: files done of total), its outcome is `last_run` on the target (`state`, `result_state` `done|needs_confirmation`, `summary`: applied, failed, released, removed, moved, bytes_written, warnings, failures), and a failed, lost or expired run raises the alert `sync.failed` (warning, key = target id), resolved by the next success.

## The node's calls

`POST /api/nodes/sync/plan { target_id, have, free_bytes, fs, rules_version }` and `…/report` use the node's own Bearer token. A node can only plan and report for the target bound to it (`403` for another target, `401` for an unknown token). Files are fetched from the signed URLs, no token. The node job params carry only `target_id` (and `confirm`); the server adds its `server_id` when the node fetches the job (REST only). The volume is the node's own config, never a param.

## The helper CLI

`remoment-node sync --server http://remoment.local --token rmt_… --target-id <ULID> --path /Volumes/STICK` runs the node's apply engine by hand on a folder or a mounted drive, from a Mac or PC, signed in with an admin token (an app sign-in) instead of a node registration. `--claim` writes the marker (the server id comes from `GET …/targets/{id}`), `--scan` reads the folder and writes nothing, `--confirm` lets mass deletion through. Exit code 0 done, 3 needs confirmation, 4 low space, 130 cancelled (Ctrl-C finishes the file and writes the manifest), 1 refused or failed. Also `REMOMENT_SERVER` and `REMOMENT_TOKEN`. It plans and reports through the admin routes of the target, so the Sync page shows its last report.

## Rockbox iPod and other players (S6)

A Rockbox iPod, an MP3 player in mass-storage mode or an SD card is an ordinary folder target: the **recipe** is a node target (or the CLI) with `profile: mp3-192` (or `highest` for a lossless-capable player), `options.layout: tree` (`flat` for one playlist), a `budget` below the card and `fill: priority`. No new code: Rockbox reads the folders by its own database scan. A stock iPod (iTunesDB through libgpod) is **not supported** and stays "later or never"; the Rockbox recipe is **not verified on a device.**

## MCP

Tool `sync` (admin): `list`, `get`, `create`, `update`, `delete`, `estimate`, `run`, `cancel`, `scan`, `claim`; the plan is the executor's call, not a tool. See [mcp.md](mcp.md).

## Configuration

`config/sync.php`: `SYNC_CACHE_GB` (default 8, 0 = off; the artifact cache, least recently used first, never an artifact used in the last `SYNC_PROTECT_MINUTES`, 60), `SYNC_CACHE_DISK` (default the export disk; point the disk's root at the library disk to keep the cache off the SD card), `SYNC_PREPARE_AHEAD` (50), `SYNC_URL_HOURS` (6), `SYNC_CONFIRM_REMOVE_PERCENT` (30), `SYNC_CONFIRM_REMOVE_FILES` (500), `SYNC_QUEUE` (`heavy`), `SYNC_ARTIFACT_TIMEOUT` (900 s). `php artisan sync:prune` (hourly, gated with `file_storage`) keeps the cache under its cap, drops rows stuck while preparing and orphan files.
