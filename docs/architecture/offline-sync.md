# Sync to offline targets (workstream 18.3): plan

Status (2026-11-21): **S0, S1 and S2 built (server and node), against fakes; nothing is verified on a real stick, node or iPhone.** Built: `App\Domain\Sync` (`SyncRules`, `SyncSelection`, `SyncPlanner`, `SyncService`, `SyncFiles`, `SyncArtifacts`, `Syncs`), tables `sync_targets` and `sync_artifacts`, the REST API ([docs/api/sync.md](../api/sync.md)), the shared artifact cache and `PrepareSyncArtifact`, the node job types `sync.scan`, `sync.claim` and `sync.apply`, the Go engine `node/internal/syncer`, the Sync page under Tools ([sync-page.md](../frontend/sync-page.md)), MCP `sync`. Not built: S3 (folder CLI), S4's `sync.probe`/`sync.eject`/`sync.verify`, S5 (the iOS app and plays coming back), S6, `folder_cover`, `.m3u8` playlists, "Adopt existing files". Roadmap: [v1-roadmap.md](v1-roadmap.md) section 18.3. What is not measured or tried on hardware is marked **unverified**. Owner decisions are in section 13; section 14 lists what was built and where it deviates from the plan.

Goal: keep a USB drive, an MP3 player, a Rockbox iPod or the iOS app's offline library filled with a *rule-defined* part of the library, and keep it right as the library changes, by adding and removing only what is needed. It is the repeating, incremental sibling of the one-shot export ([export.md](export.md), [docs/api/export.md](../api/export.md)), and reuses its decisions: the profiles of `config/export.php`, `ExportPlanner::decide()`, `ExportNames`, the loudness option, the tags-and-cover rules.

## 0. Principles

1. **The server decides, the target applies.** The server computes *the wanted set* and the *diff* against what the target says it holds. A target never evaluates rules.
2. **Pull-based and stateless on the server.** The target sends what it holds (`have`), the server answers with a plan. The server keeps no per-file state of a target (only a last report for the UI). So a drive that was written elsewhere, restored or half-synced still gets a correct plan.
3. **The manifest file on the target is the truth about what we put there.** No database on the target (no SQLite on a FAT32 stick, nothing a Rockbox iPod could choke on). A JSON file.
4. **We delete only what we wrote.** Only entries of the target's own manifest can ever be removed.
5. **No accounts.** A target is a thing the admin has, not a user. Apps use the admin bearer token, nodes their node token, file downloads can be signed. Nothing new is guest-accessible.
6. **Own files only.** Spotify and DLNA tracks have no file ([export.md](export.md) `no_own_file`).

## 1. What a target is

A **target** is a row in `sync_targets`: a name, rules, a profile, a budget, and *how it is reached*. The kind says who executes the plan:

| Kind | Where the files go | Who executes | Phase |
|---|---|---|---|
| `app` | the iOS app's offline library (app sandbox) | the app pulls (section 7) | S1 server, app in its own project |
| `node` | a **removable volume attached to a node** ([node-agent.md](node-agent.md) role `sync-target`): a USB stick, an MP3 player in mass-storage mode, a Rockbox iPod, an SD card, or a mounted network share on the node | the node, as the job `sync.apply` (section 6) | S4 |
| `helper` | a folder or volume on a desktop (Mac, Windows, Linux) | the same Go apply engine as a CLI (`remoment-node sync --target /Volumes/X`), using the admin token | S3 |

Decisions and reasons:

- **A USB drive attached to the server's host is a node on the same machine**, not a new host feature of the app. The app runs in a container and cannot see host USB devices, mounting and ejecting need host privileges, and the one process that already has host power (the Manager) is deliberately small and must not learn about media. The node is already designed as "the thing that does what hardware elsewhere needs", has an unprivileged confined unit, a job queue and progress, and can run beside the server on 127.0.0.1. Cost: a Pi that is the server must also run the node for USB sync (acceptable, one more small process). **Unverified:** the node reading volumes mounted by the OS (`/media/...` via udisks or a fixed mount point) under `ProtectSystem=strict`.
- **A "mounted share" is not its own kind.** A share mounted on a node (SMB/NFS) is a volume to the node; a share mounted on a Mac is a folder to the helper. The `exports` SMB share of the Manager is the opposite direction (the server serves a share) and stays a one-shot export; it is not a sync target.
- **`kind` is fixed at creation; the identity of a target is its marker**, not its address: a `node` target is a volume holding `.remoment-sync/target.json` with the target id (section 3). The same stick plugged into another node, or a helper, is still the same target. An `app` target is identified by a `client_ref` (a random install id the app generates and keeps).
- **A target belongs to the server, not to a token.** Revoking an app's admin token (Settings › Users) stops its access, not its target row; the next sign-in of the same install finds it by `client_ref`. There is no per-user ownership, and several phones are several targets with their own rules.
- **Claiming a drive is an explicit admin action.** A node lists the removable volumes it sees (`sync.probe`: label, filesystem, size, free, marker if any). The admin presses "Use this drive for target X", which runs `sync.claim` and only then writes the marker. A volume without a marker is never written to, and nothing is ever formatted.

## 2. The rules model

A sync rule set is stored as JSON on the target (`rules`, plus an integer `rules_version` that is bumped on every edit; no per-rule rows).

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
  "options": { "loudness": "off", "layout": "tree", "folder_cover": false, "playlists": true },
  "budget": { "bytes": 32000000000, "reserve_percent": 5 },
  "fill": "priority"
}
```

**Selections** (the closed list, an unknown type is `422`):

- `playlist` (includes smart playlists, which are re-selected by their rules at plan time: "unplayed, 120+ BPM" stays fresh, and *every other dynamic wish is "make a smart playlist"*; no second rule language, no genre/listening-mode type of its own), `album`, `artist`, `favorites` (favourite albums and artists expanded to their tracks, the same `favorited_at` as the library), `recently_added` (`limit` and/or `days`), `most_played` (`limit`, `days`, from `plays`).
- Ids resolve through `IdAliases` (a merged album keeps working); an id that no longer exists is skipped with a warning in the plan, not an error.
- Each selection yields tracks in a defined order (album disc/track order, playlist order, `recently_added` newest first, `most_played` by plays, `favorites` by `favorited_at` newest first). That order is the **rank** inside the selection; the selection's position in the list is the **priority** between selections.

**Profile and options** are the export ones, validated against the same lists, so a sync target "mp3-192 with loudness album" is exactly an export with that profile: `highest`, `mp3-*`, `compat`, `aac-*`, `opus-*`; `options.loudness` `off|album|track`. Differences: `layout` is per target, not per selection (default `tree`; `flat` only makes sense for one playlist and is refused for several selections), the cover rule is the export's (transcodes embed a baseline JPEG of at most 500 px, copies are untouched), `folder_cover` adds a `folder.jpg` per album folder (some players want it), `playlists` writes one `.m3u8` per selected playlist (section 3).

**Budget and fill strategy.** `budget.bytes` is the most the target may hold (the admin types "28 GB" or picks "all free space"; `reserve_percent` default 5 is kept free for filesystem slack and the manifest). For a node or helper target the budget is capped by the free space the target reports plus what we already own on it; an app target sends its own `free_bytes` and the user's chosen cap. Estimates use the planner's (`duration × bitrate × 125`, copies at file size); the real sizes of transcoded files are only known once they exist (section 5), so a plan is re-evaluated before it is applied, and the reserve absorbs the error. FAT32 limit: a file above 4 GB is refused for FAT32 targets (`fs` is reported by the node).

`fill` decides what happens when the selections are bigger than the budget:

- `all` (default for a small selection): everything selected or **refuse** (`422 over_budget`, with the estimate). No silent truncation.
- `priority`: selections are taken in list order; within a selection by rank; a **whole album is kept or dropped together** (no half albums) when the selection is of type album/artist/favorites, tracks are independent for playlists, `most_played`, `recently_added`. The first track that does not fit stops that selection (fill is not backtracked to squeeze in small items; the order the admin gave is respected), later selections still try.
- `fill_rest`: like `priority`, then the remaining space is filled with the ranked tracks of a last, implicit selection: favourites then most played then newest, until the budget is used. This is "fill 32 GB, ranked by favourites and play count".

**Churn control (sticky).** A naive re-ranking on every sync would swap hundreds of files as play counts move. So: tracks already on the target stay as long as they are still in a selection *and* fit the budget; new space (from removed items or growth) goes to the best-ranked candidates; when the budget forces something out, the lowest priority/rank *that is already on the target* goes first. `max_churn_percent` (default 25 of the target's owned bytes per sync, 0 = unlimited) bounds how much a single plan may add plus remove outside of explicit removal from the selections; what is over the limit waits for the next sync. **Unverified:** the defaults; they need a feel on a real library.

Result of evaluating the rules: an ordered **wanted list** of entries (section 3's shape, with sizes and fingerprints) that is the input to the diff.

## 3. Identity, the manifest and the diff

### Identity

Every wanted file is identified by a **key** that survives renames and re-tagging and a **fingerprint** that says which *bytes* it should be:

- `key` = `track:{canonical track id}` (aliases resolved, so a merge does not make the track "new"), `playlist:{playlist id}`, `cover:{album id}`.
- `fingerprint` = for a **copied** file the file's content hash (`files.hash`, sha256, already stored); for a **transcoded or remuxed** file `sha1(source files.hash | profile | codec/bitrate/sample rate | gain_db | cover size | PLANNER_VERSION)`. The fingerprint is computable *without transcoding*, which is what makes a plan cheap; the output's own sha256 appears in the plan once the artifact exists (section 5). Changing the profile, the loudness option, or replacing the source file (a better rip) changes the fingerprint and so replaces the file; a re-index of an unchanged file does not.
- A `playlist` fingerprint is the sha1 of its generated content; a `cover` the hash of the processed cover.

### Paths

The server assigns the relative path (`Artist/Album/01 - Title.mp3`, `ExportNames` rules: FAT32-safe characters, 60/100 character limits, case-insensitive duplicates). Two differences from export, both to stop churn:

1. Naming must be a function of **the track alone** (plus collision handling), not of the selection order. `ExportNames` today numbers by selection; the build adds a tree-path function used by both.
2. **Sticky paths.** An entry already in the target's manifest keeps its path unless its own metadata changed the name; new entries resolve collisions against the paths in use (`have` plus wanted), never renumbering existing files. A rename of a track on the server therefore becomes a `move` (fingerprint equal, path different), not a delete plus a download.

Playlists: `Playlists/{name}.m3u8` (UTF-8, `#EXTINF`, relative paths with `../`), listing only entries that exist on the target; a track in several playlists exists once on disk.

### Manifest on the target

```
<volume root>/.remoment-sync/target.json     { "version": 1, "target_id": "01J…", "server_id": "…", "created_at": "…" }
<volume root>/.remoment-sync/manifest.json   { "version": 1, "target_id": "…", "rules_version": 7, "updated_at": "…",
                                               "entries": [ { "key": "track:4711", "fingerprint": "…", "path": "Artist/Album/01 - Title.mp3",
                                                              "size": 8123456, "mtime": 1763200000 } ] }
<volume root>/.remoment-sync/tmp/            partial downloads (`*.part`)
```

- `server_id` is a random id of this server installation (a Setting created on first use). A volume claimed by another server is refused, so two servers never fight over one stick.
- The manifest is written **atomically** (`manifest.json.tmp`, flush, rename) after every batch (default 25 files or 60 s), so a pulled cable loses at most one batch of bookkeeping. A file on disk that is not in the manifest after a crash is an orphan *of ours* only if it sits in `.remoment-sync/tmp/`; anything else is treated as the user's (section 8).
- For an app target the manifest is the app's local store in a file of the same shape, so one diff implementation serves all (the app may keep a richer database next to it; what it reports as `have` is the same entry shape).
- `size` and `mtime` are recorded to detect later changes by the user (section 8). No hash of target files is recomputed during a sync (reading a 32 GB stick to hash it would take longer than the sync); a **verify** action (`sync.verify`, later) re-hashes on demand.

### The diff

`SyncDiff::diff(wanted, have)` (built in S0): both lists keyed by `key`.

| Case | Operation |
|---|---|
| in wanted, not in have | `add` |
| in both, fingerprints differ | `replace` (download new, then swap, then drop old) |
| in both, fingerprints equal, paths differ | `move` (a rename on the target, no download) |
| in both, equal fingerprint and path | `keep` |
| in have, not in wanted | `remove` |

Additions: `bytes_to_add` and `bytes_freed` (from the entries' sizes) give the net change the budget check needs; `path_conflicts` lists a destination path that another staying entry still occupies (the planner must avoid it; the apply refuses to overwrite). Duplicate keys in either input are an error. The diff is pure: no database, no clock, no filesystem.

The **plan** the API returns is the diff plus the facts the executor needs: per `add`/`replace` the download reference (section 5), size, fingerprint and sha256 when known; warnings (skipped ids, `no_own_file`, tracks over the FAT32 limit); `confirm_required` (section 8); totals; `rules_version`.

## 4. REST API (server)

All admin-token (`Route::middleware('admin.token')`), `features.sync` in `GET /api/info` (only when true: Standard/Full profile with `library` and `file_storage`, ffmpeg available, like `features.export`; Control `422 not_available_in_profile`). New routes must be in `openapi.yaml` in the same change (the contract test's known-missing list may only shrink).

```
GET|POST        /api/admin/sync/targets                          list / create { name, kind: app|node|helper, client_ref?, node_id?, rules? }
GET|PUT|DELETE  /api/admin/sync/targets/{id}                     detail incl. rules, last report, owned bytes / PUT name, rules (bumps rules_version)
POST            /api/admin/sync/targets/{id}/estimate            rules (or the stored ones) → { tracks, bytes, copied, transcoded, skipped, over_budget, warnings }, nothing stored
POST            /api/admin/sync/targets/{id}/plan                { have: [Entry], free_bytes?, capacity? } → SyncPlan (stateless, not stored)
POST            /api/admin/sync/targets/{id}/report              { rules_version, applied: [key], failed: [{key, error}], released: [key], plays?: [...] } → { status }
GET             /api/admin/sync/files/{target}/{key}/{fingerprint}   the file: Bearer, Range → 200/206; `409 preparing` while an artifact is being made
GET             /api/sync/files/{target}/{key}/{fingerprint}?expires=&signature=   the same, signed, no token
```

- **`Entry`** = `{ key, fingerprint, path, size, mtime? }` (the manifest entry). **Plan operations**: `add`, `replace`, `move`, `keep` (count only, not listed, it can be 30,000 entries), `remove` (key and path from the `have` the client sent). `add`/`replace` carry `{ size, sha256|null, state: ready|preparing, url (signed), headers? }`.
- **Why `have` is posted, not fetched.** The server cannot see the target. For a 30,000-track stick the `have` is about 3 MB of JSON, acceptable on a LAN; the plan response lists only changes. If that proves too big, `have` can be sent as a key→fingerprint map first (**unverified** need, not built).
- **Server state kept per target**: `rules`, `rules_version`, `last_report` (applied/failed counts, owned bytes, time, `free_bytes`). Never the file list; a report mismatch is not an error.
- **Preparing.** A plan that includes transcodes enqueues their artifacts (section 5) and marks entries `preparing`; the executor downloads the `ready` ones first and asks again (`POST plan` again, same `have`) for the rest. `409 preparing` on a file URL tells it to wait, never to fail.
- Errors follow the export ones: `422 over_budget`/`encoder_unavailable`/`nothing_to_sync` with the estimate, `404` unknown target, `409 target_busy` (a node job for this target already runs: one apply per target).
- MCP: a tool `sync` (targets, estimate, plan summary, last report), extending `docs/api/mcp.md`; applying stays with the executors.
- **Web:** Settings › Files gets a **Sync** tab (`Livewire\SyncTargets`): targets, rules editor (the selection picker of the export dialog, budget, profile, fill), the estimate, last result, and for node targets "Use this drive", "Sync now", "Safe to remove". Admin login only, same gates as export.

## 5. Delivering the files

- **Copies are served straight from the original** with `Range` (`response()->file` already answers ranges as the export download and `/stream` do), no cache, no copy on disk. Most of a library that is already MP3 at or below the ceiling needs no artifact.
- **Transcodes and remuxes are artifacts**: made once by the same ffmpeg commands as export (`ExportRunner` per-file step, factored so the sync job calls it, not copied), stored under `sync-artifacts/{fingerprint}.{ext}` on the private disk, with a row in `sync_artifacts` (`fingerprint`, `file_id`, `size`, `sha256`, `state`, `last_used_at`). A fingerprint is shared by every target with the same profile, so two phones on `mp3-192` transcode each track once. This reverses export's "transcodes are not shared" (an export is a one-shot with a zip; sync has stable fingerprints that make sharing safe). Cache pruned oldest-`last_used_at` first to `SYNC_CACHE_GB` (default 8, 0 = off, like the proxies), never an artifact a plan made in the last hour.
- The job `PrepareSyncArtifact` runs on the `heavy` queue, one at a time (Pi rule), `nice`, only for entries of a requested plan, and **at most `SYNC_PREPARE_AHEAD` (default 50) per target at once** so a 5,000-track first sync does not fill the cache before the first download starts. The first sync of a transcode-heavy library on a Pi is therefore *slow by design* (a Pi 3 transcodes about a minute per album, **unmeasured**); the UI shows "preparing N of M".
- **Signed file URLs.** The export part URL lives 15 minutes; a sync download may be queued behind others, so file URLs are valid **6 hours** (`SYNC_URL_HOURS`) and a re-plan mints fresh ones. The signature is the authorization (Laravel `signed:relative`), the URL carries target, key and fingerprint, so it cannot be turned into another file. The token route is the alternative where headers are possible (a background `URLSession` keeps request headers: **unverified** on iOS).
- Ranged requests are required for resume; the artifact is immutable (fingerprint-named), so a resumed range always continues the same bytes. An artifact whose source changed gets a new fingerprint and the old URL answers `404` (the executor re-plans).
- Disk guard like export: refuse to prepare when free space on the cache disk is below the artifact estimate plus `EXPORT_FREE_MARGIN_MB`.

## 6. How a node executes it

New role handling in the node (`sync-target` role exists in `NodeRoles`; phase S4). The node plan's rules hold: REST authoritative, MQTT doorbell only, fixed job types, typed params, no shell, no free paths.

**Job types** (added to the closed list; each with a server validator and a node handler):

| Type | Params | Does |
|---|---|---|
| `sync.probe` | none | lists removable volumes the node may use: `{ volume_id, label, fs, size, free, mounted, marker: {target_id, server_id}|null }`; result only, no writes |
| `sync.claim` | `volume_id`, `target_id` | writes `.remoment-sync/target.json` on a volume without a marker (refuses if one exists) |
| `sync.apply` | `target_id` | the sync below |
| `sync.eject` | `target_id` | flush and unmount (below) |
| `sync.verify` (later) | `target_id` | re-hash the owned files against the manifest, report mismatches |

`volume_id` comes from the node's own probe list (filesystem UUID), never a path from the job; the node maps it to its mount point. The node needs no path parameter and writes only under that volume's `.remoment-sync/` and the paths the plan gives, after the safety check below.

**`sync.apply` flow:**

1. Locate the volume by marker `target_id`; read `manifest.json` (missing: see section 8); read `free`.
2. `POST` the plan with `have` = the manifest entries (node route `POST /api/nodes/sync/plan`, Bearer node token; the node may only plan for the target its `sync-target` role is bound to, a different target is `403`). If entries are `preparing`, do the ready ones and re-plan at intervals.
3. **Order:** `move`s first (cheap renames); `remove`s *before* adds only when space is short (the plan says so with `remove_first: true`), otherwise after the adds succeeded (so a failure never leaves the target emptier than before); then `replace` and `add` in plan order.
4. Per add/replace: download to `.remoment-sync/tmp/{fingerprint}.part` (resume with `Range` from the part's size), check size and the sha256 when given, `fsync`, rename to the final path, create folders as needed, add or update the manifest entry in memory. One download at a time (Pi 2 USB and CPU); a failed file is retried 3 times with backoff, then reported `failed` and skipped, the sync goes on.
5. After every batch: write the manifest atomically. Progress per job (`files_done/files_total`, `bytes_done/bytes_total`, current file) through the existing progress route; `lease` renewal as for every job. The job is cancellable (the node reads cancel on progress and finishes the current file, writes the manifest, stops).
6. End: `POST /api/admin/...`-equivalent report via the node route (`applied`, `failed`, `released`, free space), result state `succeeded` (`failed` entries make it `succeeded` with warnings, not `failed`; `failed` is for "could not do anything": volume gone, manifest unreadable, wrong server).

**Resume and crash safety.** There is no journal: the manifest says what is done, the next plan is computed from it, and a `.part` file is resumed or removed. A job interrupted by a restart is `failed: interrupted` like every node job; "Sync now" runs it again and continues where the manifest says. Removing a file and removing its manifest entry are ordered *file first, entry after* so a crash leaves an entry whose file is gone (the next plan re-adds it if still wanted, or drops the entry), never a file we forgot we own.

**Safe eject.** The UI shows "Safe to remove" only when the last apply for that target ended with the manifest written, no job is running and `sync.eject` succeeded: the node `sync`s, then unmounts (`umount` or `udisksctl unmount` through a narrow helper). **Unverified:** an unprivileged node unmounting a volume the desktop-less Raspberry Pi OS Lite mounted (needs a polkit rule, a udev/`systemd.mount` mount the node's user owns, or a sudoers line limited to one helper script). If that cannot be done cleanly the fallback is: the node mounts the volume itself read-write into its own state directory with `uid=remoment` (FAT/exFAT) for the job and unmounts it at the end, which needs `CAP_SYS_ADMIN` or a helper. Decide with a real Pi (open question 6).

**Where it runs on a Mac or PC (`helper`).** The apply engine is a Go package with no node-specific code (`node/internal/syncer`: plan fetch, download, manifest, move/remove rules). The node's job handler and a CLI `remoment-node sync --server URL --token rmt_… --target-id … --path /Volumes/STICK` both call it. The CLI exists so a Mac user can fill a stick without a node box, and so the engine is testable against a temp directory long before a Pi is involved.

## 7. How the iOS app pulls it

The app is a separate project that reads the REST API with the admin bearer token. It is a `kind: app` target.

1. **Create/find the target:** `POST /api/admin/sync/targets { name: "Remko's iPhone", kind: "app", client_ref }` (idempotent on `client_ref`), rules edited in the app or on the web.
2. **Sync run** (foreground, or a `BGProcessingTask` while charging and on Wi-Fi: **unverified** how often iOS grants it): read the local manifest, `POST …/plan { have, free_bytes }`, get the changes.
3. **Downloads** as background `URLSession` download tasks, one per add/replace, from the signed URLs (resume data and `Range` supported by the server), a limited number at a time; the signed URL lifetime (6 h) covers a queued backlog, and the app re-plans when a URL expires (`403`/`404`).
4. **Apply** in the app's own store: move the finished file into the library directory (excluded from iCloud backup), update the manifest and its database, `move` and `remove` operations are local renames/deletes, `remove` last (and only for keys in its own manifest). Cover art for the offline UI comes from the `cover` entries or the existing artwork URLs, the app's choice.
5. **Report** `POST …/report` with applied/failed/released, so the web page shows "last synced 2 h ago, 1,840 tracks, 23.4 GB".
6. **Plays back** (S5): offline plays are queued locally and sent in the report as `plays: [{ client_id (uuid), track_id, started_at, played_seconds }]`, idempotent per `client_id`; the server writes them into history with `source_type: offline` and scrobbles them with their real time. **Unverified:** Last.fm's limit on how old a scrobble may be (the API documents a maximum age; check before promising that a week-old offline play is scrobbled).

**What the OpenAPI contract needs:** a `sync` tag; operationIds `listSyncTargets`, `createSyncTarget`, `getSyncTarget`, `updateSyncTarget`, `deleteSyncTarget`, `estimateSyncTarget`, `planSyncTarget`, `reportSyncTarget`, `downloadSyncFile` (+ the signed variant); schemas `SyncTarget`, `SyncRules` (selection `oneOf` by `type`), `SyncEntry`, `SyncPlan`, `SyncPlanOperation`; `206 Partial Content` and `Accept-Ranges`/`Content-Range` on the file route; `409 preparing`; `features.sync` in the `info` schema. Not scene operations: sync is not a scene step (the allow-list in `config/scene-operations.php` stays as is).

## 8. Conflicts and deletion rules

1. **Never delete a file the server did not put there.** A delete is only issued for a `have` entry; a file with no manifest entry is invisible to sync, even in a folder we created. Removing the last file of a folder we created removes the empty folder (only empty folders, only ones whose every former file was ours: tracked by the paths in the manifest); a folder with a stranger inside stays.
2. **Never overwrite a file we do not own.** An add whose path exists and is not in the manifest gets a numbered name (`01 - Title (2).mp3`) and a warning; the manifest records the real path. `path_conflicts` from the diff never reach the executor.
3. **User changed or removed our file.** At plan time the executor compares each manifest entry's `size` and `mtime` with the file. Different: the user edited or replaced it, so the entry is **released**: dropped from the manifest, the file left alone and reported. It is added again (under a numbered name, rule 2) only if the track's fingerprint later changes; otherwise the file stays the user's. Missing file: the entry is dropped and the file is added again at the next plan if still wanted (people delete a track on a player, then it comes back; the alternative, remembering deletions, is a second list to keep; **open question 5**).
4. **Lost or corrupt manifest** (a stick reformatted, `.remoment-sync` deleted, JSON unreadable): the target is treated as **unknown**: the plan has `adds` only, existing files at the planned paths are not overwritten (rule 2, so a re-sync onto a stick that still holds our old files produces `(2)` duplicates), and **no deletes**. The UI offers "Adopt existing files": compares size and tag-derived key to claim matches (a later phase; until then the safe path is to clear the stick by hand).
5. **Mass deletion needs a yes.** A plan removing more than `SYNC_CONFIRM_REMOVE_PERCENT` (30) of the owned entries or more than 500 files carries `confirm_required: true`; an unattended executor (a node job started by schedule, a background app run) then applies nothing destructive and reports `needs_confirmation`; a person pressing "Sync now" (or `confirm: true` in the job) goes ahead. An empty selection list never runs unattended.
6. **A different server's marker** (`server_id` mismatch) or a different `target_id` than the job's: refuse, write nothing.
7. **Library changes:** a track whose file is gone from the library, or that lost its own file, is `remove`d; a track merged into another resolves through the alias to the same key (no churn); a better rip replaces (new fingerprint); a changed tag renames (move). Files on the server are never touched by a sync.

## 9. Pi budget

- Server on a Pi 2/3: one transcode at a time on the existing `heavy` worker, under `nice`, prepare-ahead cap, cache cap `SYNC_CACHE_GB`. The plan itself is database work: the wanted set for a 30,000-track library is a few queries, packed in PHP in chunks; the 3 MB `have` is read from the request, not stored. **Unverified:** plan time on a Pi 3 with a large library and a Pi 2's memory with a 30,000-entry `have` (budget: under 5 s and under 64 MB; measure with the hardware-check scripts).
- Serving copies is a straight file read, no CPU. Serving artifacts reads the cache.
- **SD-card wear:** the artifact cache is the one new write load on the server. It is capped, only written for transcodes, and can sit on the external library disk (`SYNC_CACHE_DIR`). Prefer targets whose profile makes most files copies (`mp3-320` over a lossless-free library, `highest`).
- Node: one download at a time, writes go to the USB volume, not the SD card; its only SD writes are the state directory and logs. Idle cost stays as in N1.
- App: no server cost beyond the above.

## 10. Security model

- **No accounts, no new auth.** Admin routes: the admin bearer token (revocable, hashed). Node routes: the node's own token, scoped to one target (a node cannot plan or read for another target, cannot reach admin routes). File URLs: signed (the export mechanism), short-lived, bound to target+key+fingerprint, serving only files the library already treats as open to the household.
- A **forged MQTT doorbell** can only make a node ask for its jobs ([node-agent.md](node-agent.md) section 3); nothing in a sync is carried over MQTT.
- **Path safety:** the node and helper normalise every plan path (no `..`, no absolute paths, no drive letters, no characters FAT32 forbids, length limits) and refuse a plan entry that fails it; writes stay under the volume root, and deletes only for entries of the local manifest. A hostile or buggy server cannot make an executor write outside the volume or delete a stranger's file (rules 1 and 2 are enforced **in the executor**, not trusted from the server).
- **Claim step** (section 1): no silent writes to any stick that is plugged in.
- Everything a target reports (`last_report`, labels, filenames) is data: escaped in the UI, never used in a command.
- Sync is gated like export: admin only, `library` + `file_storage` features, Standard/Full.

## 11. Phased build order with done-criteria

| Phase | Content | Done when |
|---|---|---|
| **S0 SyncDiff (built 2026-11-15)** | `App\Domain\Sync\SyncDiff`: pure add/replace/move/keep/remove diff by key with fingerprints and paths, byte totals, path conflicts, duplicate-key refusal | unit tests pass (`tests/Unit/SyncDiffTest.php`); no other code depends on it yet |
| **S1 server core: the contract (built 2026-11-21)** | `sync_targets` (+`sync_artifacts` stub), `SyncSelection` (the six selection types), `SyncPlanner` (wanted list, budget, fill, sticky, fingerprints, sticky paths; tree-path function factored out of `ExportNames`), target CRUD, `estimate`, `plan`, `report`, copy download (token and signed, ranged), transcodes served **only as ready artifacts made synchronously for the plan** (small first versions), `features.sync`, OpenAPI, MCP `sync`, docs/api/sync.md, tests with fake libraries | a fake client posting `have` gets correct add/remove/move plans for each selection type and fill mode; a second plan with the applied manifest is empty; a rules edit yields exactly the diff expected; copies download with `Range`; over-budget refuses; the contract test passes. **This is the smallest useful server increment** because it is the one interface every executor needs |
| **S2 artifacts and preparing (built 2026-11-21, except folder cover and m3u8 playlists)** | `PrepareSyncArtifact` job, preparing state, prepare-ahead cap, cache cap and pruning, loudness and folder cover, playlists as `.m3u8` | a first sync of a lossless library on a test box prepares in the background with bounded cache; a profile change replaces files; cache prune never removes an artifact in use; Pi 3 cost per album measured and written here |
| **S3 apply engine and folder target** | Go `syncer` package + `remoment-node sync` CLI for a folder or volume; all rules of section 8 enforced in the executor; tests against a fake server and a temp directory | sync to a folder, kill it mid-file, re-run: it resumes, the manifest is right, nothing outside the manifest is touched; files edited or removed by hand are handled per section 8; a stranger file at a planned path is not overwritten |
| **S4 node `sync-target` role** | `sync.probe/claim/apply/eject/verify`, node routes, Sync tab on the web, "Use this drive", progress, safe eject | with a real stick on a real Pi: claim, sync 2 GB, pull the cable at random, re-run, eject safely; an unmarked stick is never written; idle and apply CPU/RSS on a Pi 2 measured and written here |
| **S5 iOS and sync-back** | app work in its own project against S1/S2; offline plays in the report; history source `offline`; scrobble with the real time | the phone syncs a playlist over Wi-Fi in the background, plays offline, plays appear in history once (idempotent), a rules change removes and adds the right files |
| **S6 iPod** | Rockbox iPod = a folder target with `mp3-*`/`highest` and a playlist layout (works from S4 with no new code, only a documented recipe); a stock iPod needs `libgpod`/iTunesDB writing | the Rockbox recipe verified on one device; stock iPod stays "later or never" |

**Smallest useful end-to-end:** S1 + S2 (without the transcode cap tuning) + the app (S5's download part). That matches the roadmap order ("Sync v1: manifest API, iOS offline library, folder and USB target"): the phone needs no extra hardware, and S3's CLI gives the folder/USB case a second consumer of the same contract the next week. If the app project is not ready when S1 is, S1 + S3 gives a usable "fill my stick from a Mac" with no Pi and no phone, and every rule is testable against a temp directory.

**Built so far:** S0, S1 and S2 with the node's apply engine (section 14); the rest of the table is open.

## 12. Differences from export, in one place

| | Export | Sync |
|---|---|---|
| Run | once, a zip of one selection | repeatedly, incremental |
| Selection | one album/artist/playlist/list | several, with priority, budget and fill |
| Output | zip parts, deleted after 24 h | files on a target; artifacts cached by fingerprint |
| Identity | none | key + fingerprint + manifest on the target |
| Names | per selection (playlists flat by default) | per target, sticky, a function of the track |
| Transcodes | not shared | shared by fingerprint |
| Delivery | signed part URL, 15 min | per-file signed URL, 6 h, or token; ranged |
| Deletion | n/a | only entries of the target's manifest |

## 13. Owner decisions

**Answered 2026-11-20:** (1) first phase S1 + S2 + the iOS app; (2) a USB drive on the server host is a node on the same machine; (3) a capped artifact cache on the library disk shared between targets; (4) fill strategy defaults as proposed (`all` for small selections, `priority` otherwise, `fill_rest` explicit, 25 % churn); (5) a track deleted by hand is re-added at the next sync, an edited file is left alone and unmanaged; (6) the node is write-only in 1.0, no privileged mount helper, the UI says to wait until the job is done and then remove the drive; (7) mass-deletion confirmation as proposed, and no scheduled sync in the first version; (8) offline plays are scrobbled with their original time (within Last.fm's limit); (9) stock iPod via libgpod later or never, Rockbox iPod by folder recipe only; (10) one stick belongs to one server.

The original questions follow for reference.

1. **Smallest useful phase:** S1 + S2 + the iOS app first (recommended; no hardware), or S1 + S3 (a Mac/desktop CLI for folders and USB sticks, testable without a phone)?
2. **USB on the server host = a node on the same machine** (recommended, section 1), or do you want the app container itself to write to a mounted path (simplest to build, needs the host's USB mounted and bound into the container, and never ejects safely)?
3. **Sharing transcoded artifacts between targets** with a capped cache on the server disk (recommended), or transcode per target on the fly with no cache (less disk, more CPU, no ranged resume)? On a Pi server with an SD card, is a cache on the library disk acceptable?
4. **Fill strategy defaults:** `all` for small selections, `priority` otherwise, `fill_rest` as an explicit choice, and the sticky/churn defaults (25 %). Right, or do you want "re-rank everything every sync" for simplicity?
5. **A track the user deleted by hand on a target:** re-add it at the next sync (recommended, simple) or remember the deletion (a "not wanted" list per target)? And a file the user edited: leave it alone and stop managing it (recommended)?
6. **Safe eject on a Pi:** are you willing to add a small privileged mount helper (polkit rule or sudoers line) to the node's package, or should the node only write and the UI say "wait until the job is done, then remove"? Needs a real Pi to settle.
7. **Mass-deletion confirmation** (30 % or 500 files, only for unattended runs): fine, and should a scheduled/automatic sync exist at all in the first version (recommended: no, only "Sync now" and the app's own background run)?
8. **Offline plays into history and Last.fm** (S5): scrobble them with their original time even when old (subject to Last.fm's limit), or show them in history only?
9. **Stock iPod via libgpod** stays "later or never", Rockbox iPod via a folder recipe only. Agree?
10. **Several servers:** is "one stick belongs to one server" (the `server_id` guard) acceptable?

## 14. As built (2026-11-21)

What S1 and S2 turned into, and where it differs from the sections above. Everything is tested with fakes (a temp directory for the volume, a fake server for the engine, a temp library with synthetic files and one-second tones for ffmpeg); **unverified: a real USB stick (FAT32, exFAT), the node reading a volume under `ProtectSystem=strict`, plan time and memory on a Pi with a large library, transcode cost per album on a Pi, the churn and fill defaults on a real library, iOS.**

- **Tables.** `sync_targets` (ULID id, `kind` app|node, `client_ref`, `node_id`, `rules` json, `rules_version`, `last_report`, `owned_bytes/count`, `last_run`, `active_job_id`) and `sync_artifacts` (`fingerprint`, `file_id`, `state`, `extension`, `size`, `sha256`, `last_used_at`). One ordinary migration, for the schema squash.
- **Routes.** The plan lives at `POST /api/admin/sync/targets/{target}/plan` as in section 4 (the stateless call is per target, because the rules are the target's); the node calls `POST /api/nodes/sync/plan` and `…/report`, bound to its own target. Extra routes the plan did not list: `…/run` (Sync now, cancel), `…/volume` (scan, claim), the admin-token variant of the file route.
- **Artifacts** are prepared by `PrepareSyncArtifact` (heavy queue, one at a time, `ExportTranscoder` = the per-file encode factored out of `ExportRunner`, so export and sync make the same bytes). The plan starts them (prepare-ahead cap 50) and marks entries `preparing` until ready. A copied MP3 in a profile with `id3v23` (`compat`) is a remux artifact, as in export. The fingerprint uses the **effective encode** (codec, bitrate, rate, channels, gain), not the profile name, so `mp3-128` and another profile that makes the same bytes share one file.
- **Names.** `ExportNames::treeParts` is the function of the track alone; a track without a track number has no number prefix in sync (export numbers by position). Flat layout is `Artist - Title.ext`, only for one playlist.
- **Churn** applies only when the manifest's `rules_version` equals the target's current one (nothing but the library moved) and the target already holds something; a rules edit takes effect in full, and the first new unit always passes so a unit larger than the allowance cannot starve.
- **Marker and claim.** `sync.claim` exists (the plan listed it under S4): without it no stick could ever get its marker. `sync.probe`, `sync.eject` and `sync.verify` do not: the volume is the mount point in the node's config (`"sync": {"volume_path": "/media/usb/STICK"}`, or `REMOMENT_NODE_SYNC_PATH`), the node is write-only and the page says to remove the stick once the job is done. `sync.scan` reports `mounted, fs, size, free, marker, manifest`.
- **The engine** (`node/internal/syncer`) enforces section 8 itself, whatever the server says: paths are checked (`SafePath`: relative, no `..`, no drive letter, no FAT32-forbidden characters, length limits, never inside `.remoment-sync`); only manifest entries are moved, replaced or removed (a hostile plan naming a stranger's path is ignored, the path used is the manifest's); an add onto an existing path gets a numbered name (compared case-insensitively); a file the user changed (size or mtime off by more than 2 s) is **released** and stays theirs until the server's fingerprint for that key changes; a deleted file is added again; an unreadable manifest means an empty `have`, no deletions and a kept copy of the broken file; a marker of another server or target refuses the run before anything is read or planned; mass deletion (`confirm_required`) is skipped without `confirm` and reported as `needs_confirmation` while additions still go through; removals run last, only when nothing is waiting for an artifact (or first, with `remove_first`); the manifest is written atomically after every 25 files or 60 s; downloads go to `.remoment-sync/tmp/{fingerprint}.part`, resume with `Range`, are checked for size and sha256 and renamed into place; a low-space check stops the run; a cancel finishes the current file and writes the manifest; progress every 5 s renews the job lease.
- **Orchestration** is `Domain\Sync\Syncs`: one job per target (`target_busy`), progress into the task tray (`sync:{id}`), the outcome in `last_run`, the alert `sync.failed`, and `alerts:check` reconciles runs whose job was lost, expired or cancelled.
- **Not in the server's hands:** scheduled syncs (none in v1), the iOS side (S5), plays coming back.
