# Export from the library API (workstream 13)

Download a playlist, album, artist or any list of tracks as zip file(s) for an MP3 player or a car radio. Only tracks with an own file can be exported; the rest are listed as skipped. How the planner, job, zip and naming work: [export.md](../architecture/export.md).

**Admin token only** (`Authorization: Bearer rmt_…`, see [admin-auth.md](admin-auth.md)): the media is open to the household, an export is a heavy job. Control profile: `422 not_available_in_profile`. `GET /api/info` `features.export` is `true` when the profile has the library and own files and ffmpeg can run.

## Request body

```json
{ "type": "album", "id": 12, "profile": "mp3-320", "options": { "layout": "tree" } }
{ "type": "tracks", "track_ids": [4, 9, 2], "profile": "compat" }
```

`type`: `album` | `playlist` | `artist` | `tracks` (`id` for the first three, `track_ids` (1–5000, in order) for `tracks`). Merged ids resolve to the survivor; an unknown id is a `422` validation error. `profile`: `highest`, `mp3-320` (default), `mp3-256`, `mp3-192`, `mp3-128`, `compat`. `options.layout`: `tree` | `flat`.

## Endpoints

```
POST   /api/library/exports/estimate          → 200 ExportEstimate     queues nothing
POST   /api/library/exports                   → 202 { id, status: "queued", estimate }
GET    /api/library/exports?limit=20          → { data: [Export] }     newest first
GET    /api/library/exports/{id}              → Export
DELETE /api/library/exports/{id}              → { status: "ok", id, result: "cancelled" | "removed" }
GET    /api/library/exports/{id}/download/{part}?expires=&signature=   → the zip (no token)
```

`{id}` is the export's uuid. A queued or running export is cancelled (the job notices at the next file and removes what it wrote); a finished or failed one is removed with its files.

**ExportEstimate**: `{ title, selection: {type, id, track_ids?}, totals: { profile, tracks, exportable, copied, transcoded, skipped, estimated_bytes, parts, exceeds_limits, exceeds: ["max_gb"|"dir_max_gb"] }, skipped: [{ track_id, name, reason }], limits: { max_gb, part_gb, dir_max_gb } }`.

**Export**: `{ id, status: queued|running|done|failed|expired, title, selection, profile, options, progress: { files_done, files_total, percent }, totals: { tracks, copied, transcoded, skipped, estimated_bytes, planned_parts }, skipped: [...], parts: [{ part, name, size, files, url }], bytes, error, created_at, started_at, finished_at, expires_at }`. `parts` is filled when `done`.

## Errors

- `422 export_too_large` (over `EXPORT_MAX_GB` / `EXPORT_DIR_MAX_GB`) and `422 nothing_to_export` carry the `estimate`.
- `409 not_available`: ffmpeg is missing. `401` without the admin token. `404` unknown export.

## Signed download

Every `GET /api/library/exports/{id}` makes a fresh `url` per part: a **path** with `expires` and `signature` (Laravel signed URL, `signed:relative`, so it holds behind any proxy host). **Valid 900 seconds**; poll again for a new one. The signature is the authorization, so a browser downloads it without the token header; a missing, tampered or expired signature is `403`. The file is sent as `application/zip` with `Content-Disposition` and answers `Range` requests (resumable). A part that is not finished, expired or removed is `404`. Clients prefix the server address (`base_url` of `GET /api/info`).

MCP: tool `export` (estimate, start, status, list, cancel); see [mcp.md](mcp.md).
