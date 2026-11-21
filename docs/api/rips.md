# Ripping a CD on a node: API

A ReMoment Node with the `ripper` role switched on rips the disc in its drive and uploads it into the library (workstream 18.1; design in [node-ripper.md](../architecture/node-ripper.md)). Full profile (feature `rip_upload`); outside it every route here answers `422 not_available_in_profile`. Unverified on hardware.

```
GET  /api/nodes/rippers               open: the nodes that can rip, the disc as of the last check, busy
POST /api/admin/rips  { node_id }                 admin token: check the disc (202), an open check again (200)
POST /api/admin/rips  { node_id, release_mbid }   admin token: rip it as that release (202)
GET  /api/admin/rips[?]                           admin token: recent checks and rips, newest first
GET  /api/admin/rips/{id}                         admin token: one, with candidates, progress, log
POST /api/admin/rips/{id}/cancel                  admin token: a queued one ends at once, a running one stops (cyanrip is killed)
POST /api/nodes/files                             node token (Bearer): the node's own upload, only during its rip
```

MCP: the admin tool `rips` (`nodes`, `check`, `start`, `status`, `list`, `cancel`). Web: the "Rip this disc" card on `/library/collection`.

## Steps

1. `GET /api/nodes/rippers` → `data: [{ id, name, node_online, node_state, detail, drive, engine, disc, busy, rip_id, probe_id }]`. `disc` is `null` until a check, or when the last one is older than `RIPPER_DISC_FRESH_SECONDS` (900); else `{ present, audio, disc_id, tracks, checked_at }`.
2. `POST /api/admin/rips { node_id }` → `202` and a `probe` (`state: probing`). Poll `GET /api/admin/rips/{id}`: `probing` → `looking_up` → `choose_release` with `candidates` (maybe empty, then `lookup: none`; `lookup_error` when MusicBrainz could not be reached), or `no_disc`, or `not_audio`.
3. `POST /api/admin/rips { node_id, release_mbid }` with one candidate's `release_mbid` (or another release with a medium of as many tracks as the disc) → `202` and a `rip` (`state: queued`). Poll: `queued` → `ripping` → `uploading` → `done` (or `failed`, `cancelled`).

## The Rip object

```json
{ "id": "01KB…", "kind": "rip", "node_id": 7, "node_name": "Pi with a drive", "state": "ripping", "error": null,
  "created_at": "…", "finished_at": null,
  "release": { "release_mbid": "…", "artist": "Dark Tranquillity", "title": "Fiction", "year": 2005, "barcode": "0602537531159", "tracks": 11 },
  "disc_id": "…", "progress": { "phase": "ripping", "track": 3, "tracks": 11, "percent": 40.0 }, "percent": 21,
  "files": [], "warnings": [] }
```

A `probe` has `disc` (`present`, `audio`, `disc_id`, `tracks`), `lookup` (`discid`, `toc` or `none`), `lookup_error` and `candidates` (`null` while the lookup runs): `{ release_mbid, release_group_mbid, artist, title, year, label, catalogue_number, country, barcode, disc, discs, track_count, tracks: [{ number, title, artist, length }] }`. A `rip` has `percent` (overall: the ripping phase is the first 90 %, the upload the rest), `files` (ids of the files the node uploaded), `warnings` (e.g. a file without the release tag) and, on `GET …/{id}` only, `log` (the ripper's log, last 32 KiB).

States: `probing`, `looking_up`, `no_disc`, `not_audio`, `choose_release`, `queued`, `ripping`, `uploading`, `done`, `failed`, `cancelled`.

## Errors

`POST /api/admin/rips`: `404 unknown_node`, `422 unsupported` (role not switched on), `503 node_offline`, `409 busy` (another disc is being ripped), `422 not_probed` (no check, or an older one), `422 no_disc`, `422 not_audio`, `422 unknown_release` (MusicBrainz has no such release, or its medium has another track count), `502 lookup_failed`, `422` for an invalid body. `GET`/`cancel`: `404 not_found`.

## Node upload

`POST /api/nodes/files` (multipart `file`, `Authorization: Bearer <node api_token>`) is the same ingest as the admin upload `POST /api/admin/files` of the Ripper app: `201 { file: { id, path, size, hash }, track_id }`. It works only while the node has an accepted or running, not cancelled `ripper.rip` job: otherwise `403 no_rip_job`; `401` for an unknown token or an admin token; `422 invalid` for what is not audio. An identical file that is already in the library is kept instead, so a retry returns `201` again.

## Result, link, alert

When the node reports the result, the files it uploaded are tied to the CD of the physical collection (by the release id and barcode in the tags, and again from the job): the item is `ripped` and linked to the album, see [physical.md](physical.md). A failed, lost or expired rip raises the alert `rip.failed` ([alerts.md](../architecture/alerts.md)); a running rip shows in the task tray.
