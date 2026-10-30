# Deep analysis of one track (`/api/library/tracks/{id}/deep-analysis`)

Some analyses are too slow or too rare to compute for the whole library, so they run **only when the admin asks for one track**, are stored, and never appear in the nightly `library:analyze` or on `/settings/analysis`. Today there is one kind, **`sections`**: where the music changes, as a list of time ranges with repetition letters (workstream 16.2; service and algorithm in [../architecture/structure-service.md](../architecture/structure-service.md)). `mood` over time and per-stem lanes (`stems`) get a row in `App\Domain\Analysis\DeepAnalysis::KINDS` when they exist; an unknown kind is a `422`.

All three endpoints need the admin token (`Authorization: Bearer rmt_…`, [admin-auth.md](admin-auth.md)) and answer `401` without it. The track is a **library track id** (an id merged away by `library:merge-duplicates` resolves to the survivor on POST and GET, but not on DELETE, which never follows an alias) and needs an own audio file on this server (the primary present original); a streaming-only track answers `422 no_file`.

## Request

```
POST /api/library/tracks/{id}/deep-analysis        body { "kinds": ["sections"] }
```

| Status | Body | Meaning |
|---|---|---|
| `202` | `{ "status": "queued", "queued": ["sections"], "estimate": { "sections_seconds": 5 } }` | The job is on the `analysis` queue (or was already queued/running: asking twice queues once) |
| `200` | `{ "status": "already_done", "queued": [], "estimate": {…} }` | Stored for the current file and stage version; nothing happens. Request again after `DELETE` to recompute |
| `409` | `{ "status": "not_available", "queued": [], "estimate": {…} }` | `STRUCTURE_URL` is not set or the service fails its health check. Nothing was queued |
| `422` | `{ "error": "unknown_kind" \| "no_file", "message": … }` or Laravel validation (`kinds` missing) | |

`estimate.sections_seconds` is an **estimate** from the track length (`2 s + 0.012 s per second of audio` by default: a 5-minute track is about 6 s on a fast machine, several times more on a Pi; config `analysis.structure_seconds_*`). The job waits behind whatever the single `analysis` worker is doing, which can be the nightly run (hours), so `queued` may last long.

## State

```
GET /api/library/tracks/{id}/deep-analysis
→ { "track_id": 42, "available": true,
    "kinds": { "sections": { "state": "done", "finished_at": "2026-10-30T15:02:11+01:00", "version": "1", "message": null, "stale": false } } }
```

`state` per kind: `none` (never asked, removed, or stale), `queued`, `running`, `done` (stored, current file and stage version), `failed` (the last run failed: `message` says why, e.g. "Too long for the structure service: …20 minutes", "The structure service is not reachable"; asking again retries). `finished_at` is when the stored result was made, or when the last run failed. `stale: true` means a result from an older stage version is still stored and served but will be recomputed when requested (`state` is `none`). `available` is the same as `features.deep_analysis` in `GET /api/info`. A client polls this every few seconds while `queued` or `running`; markers expire by themselves (the queued one after 12 hours, the running one after the job timeout), so a dead worker never leaves a stuck state.

## Remove

```
DELETE /api/library/tracks/{id}/deep-analysis/sections   → { "status": "ok", "removed": true }   (false when nothing was stored)
```

## Where the result is

The stored sections are in the track's `details` (`GET /api/library/albums/{id}` → `tracks[].details`) and in `now_playing` of `GET /api/devices/{id}`; the waveform fields are unchanged:

```json
"sections": [ { "start": 0.0, "end": 23.1, "label": "A", "confidence": 0.71 }, { "start": 23.1, "end": 51.4, "label": "B", "confidence": 0 } ],
"sections_reason": null
```

- Times are seconds from the start of the file. `label` is a **repetition letter** (the same letter = the part sounds like that other part) or `null`, never "verse" or "chorus"; `confidence` is the cosine similarity to the best same-letter section (0 when the letter is unique; not calibrated). Show neutral colours and letters only where confidence supports them.
- `sections` is `null` in `details` (absent in `now_playing`) until sections were requested and stored. A stored `[]` with `sections_reason` `too_short` (under 30 s) or `no_structure` (silence, steady noise, nothing changes) means "looked, found none": the UI says so instead of offering the button again.
- Only the track whose own file was analysed has them; `now_playing` finds the track through the library like the waveform does (cached for a minute).

## `GET /api/info`

`features.deep_analysis` is true while the structure service answers its health check (cached for a minute), see [server-info.md](server-info.md). Clients should offer the action only then.

## MCP

Tool `deep_analysis` (ADMIN): `request` (`track_id`, `kinds`, default `["sections"]`), `status`, `remove`; same answers and errors, see [mcp.md](mcp.md).

## Code

`App\Domain\Analysis\DeepAnalysis` (kinds, states, request), `Jobs\AnalyzeTrackDeep` (queue `analysis`, unique per file and kind, one try, timeout = `STRUCTURE_TIMEOUT` + 60 s, below the worker's `--timeout=900` and the queue's `retry_after`), `Stages\StructureStage` (an `OnDemandStage`, stored in `file_analyses.sections`), `Api\DeepAnalysisController`. A failed or killed job records the error and clears its markers.
