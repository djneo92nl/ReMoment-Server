# Deep analysis of one track (`/api/library/tracks/{id}/deep-analysis`)

Some analyses are too slow or too rare to compute for the whole library, so they run **only when the admin asks for one track**, are stored, and never appear in the nightly `library:analyze` or on `/settings/analysis`. There are two kinds: **`sections`**: where the music changes, as a list of time ranges with repetition letters (workstream 16.2; service and algorithm in [../architecture/structure-service.md](../architecture/structure-service.md)), and **`stems`**: one loudness curve per stem (vocals, drums, bass, other), the share of the track with vocals and the instrumental ranges (workstream 16.4; Demucs in its own service, see [../architecture/stems-service.md](../architecture/stems-service.md)). `mood` over time gets a row in `App\Domain\Analysis\DeepAnalysis::KINDS` when it exists; an unknown kind is a `422`.

All three endpoints need the admin token (`Authorization: Bearer rmt_…`, [admin-auth.md](admin-auth.md)) and answer `401` without it. The track is a **library track id** (an id merged away by `library:merge-duplicates` resolves to the survivor on POST and GET, but not on DELETE, which never follows an alias) and needs an own audio file on this server (the primary present original); a streaming-only track answers `422 no_file`.

## Request

```
POST /api/library/tracks/{id}/deep-analysis        body { "kinds": ["sections"] }   or ["stems"] or both
```

| Status | Body | Meaning |
|---|---|---|
| `202` | `{ "status": "queued", "queued": ["sections"], "estimate": { "sections_seconds": 5, "stems_seconds": 110, "stems_ram_mb": 1500 } }` | The job is on its kind's queue (`analysis` for sections, `stems` for stems), or was already queued/running: asking twice queues once |
| `200` | `{ "status": "already_done", "queued": [], "estimate": {…} }` | Stored for the current file and stage version; nothing happens. Request again after `DELETE` to recompute |
| `409` | `{ "status": "not_available", "queued": [], "estimate": {…} }` | The service of a requested kind is not configured (`STRUCTURE_URL`, `STEMS_URL`) or fails its health check. Nothing was queued, not even the other requested kind |
| `422` | `{ "error": "unknown_kind" \| "no_file", "message": … }` or Laravel validation (`kinds` missing) | |

`estimate` has one `{kind}_seconds` per kind, and `stems_ram_mb` (what the stems service needs while it works, about 1500). `stems_seconds` is `5 s + 0.5 s per second of audio` by default (config `analysis.stems_seconds_*`, measured on 4 threads of an Apple M5 Pro: a 4-minute track is about 2 minutes there, expect 1.5-2.5 s per second of audio on a small PC: calibrate with `STEMS_SECONDS_PER_SECOND`). `estimate.sections_seconds` is an **estimate** from the track length (`2 s + 0.012 s per second of audio` by default: a 5-minute track is about 6 s on a fast machine, several times more on a Pi; config `analysis.structure_seconds_*`). The sections job waits behind whatever the single `analysis` worker is doing, which can be the nightly run (hours), so `queued` may last long; the stems job has its own `stems` queue and worker (one at a time, `nice 15`), so it only waits for other stems requests.

## State

```
GET /api/library/tracks/{id}/deep-analysis
→ { "track_id": 42, "available": true,
    "kinds": { "sections": { "state": "done", "finished_at": "2026-10-30T15:02:11+01:00", "version": "1", "message": null, "stale": false, "available": true },
               "stems": { "state": "none", "finished_at": null, "version": null, "message": null, "stale": false, "available": false } } }
```

`state` per kind: `none` (never asked, removed, or stale), `queued`, `running`, `done` (stored, current file and stage version), `failed` (the last run failed: `message` says why, e.g. "Too long for the structure service: …20 minutes", "The structure service is not reachable"; asking again retries). `finished_at` is when the stored result was made, or when the last run failed. `stale: true` means a result from an older stage version is still stored and served but will be recomputed when requested (`state` is `none`). The top-level `available` is the same as `features.deep_analysis` in `GET /api/info` (any kind can run); each kind's own `available` says whether that kind's service is configured and healthy (`features.deep_analysis_kinds` lists them). A client polls this every few seconds while `queued` or `running`; markers expire by themselves (the queued one after 12 hours, the running one after the job timeout), so a dead worker never leaves a stuck state.

## Remove

```
DELETE /api/library/tracks/{id}/deep-analysis/{kind}   → { "status": "ok", "removed": true }   (false when nothing was stored)
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

The stored stems, in the same two places (`details` and `now_playing`), absent/`null` until requested:

```json
"stems": { "method": "htdemucs", "rate_hz": 2, "vocals": [-31, -29, …], "drums": […], "bass": […], "other": […] },
"vocal_ratio": 0.96,
"instrumental": [ { "start": 202.0, "end": 210.0 } ]
```

- Each lane is **integer dB** (RMS of the stem over one frame, relative to full scale, clamped to -80..0; -80 is silence), one value per `1 / rate_hz` seconds from the start of the file (`rate_hz` is 2: frame `i` covers `i/2` to `(i+1)/2` s). A 4-minute track is 4 x 480 values (about 8 KB of JSON, a 10-minute track 20 KB), so `now_playing` carries them only for a track that was analysed, and a client that polls often can keep the curves from the first read. Draw each lane on its own scale (for example its own loudest 1 % down 40 dB), because the stems have very different levels.
- `vocal_ratio` is the share of the track that is not instrumental (0-1); `instrumental` lists the ranges (seconds) where the vocals lane stays more than 20 dB under the track's own vocal level for at least 8 s (gaps under 3 s are bridged). A track with no vocals at all is one range over the whole length and `vocal_ratio` 0.
- The separation is the model's best guess: where "other" ends and "vocals" begins is fuzzy, so treat the lanes as a loudness picture, not as isolated audio. No stem audio is stored or served.

## `GET /api/info`

`features.deep_analysis` is true while any kind's service answers its health check (cached for a minute) and `features.deep_analysis_kinds` lists the kinds that can run now (`["sections", "stems"]`, present only when not empty), see [server-info.md](server-info.md). Clients offer an action only for a kind in that list.

## MCP

Tool `deep_analysis` (ADMIN): `request` (`track_id`, `kinds`: `sections` and/or `stems`, default `["sections"]`), `status`, `remove`; same answers and errors, see [mcp.md](mcp.md).

## Code

`App\Domain\Analysis\DeepAnalysis` (kinds, states, request), `Jobs\AnalyzeTrackDeep` (the kind's queue: `analysis` for sections, `stems` for stems; unique per file and kind, one try, timeout = the stage's service timeout + 60 s, below the worker's `--timeout` and the queue's `retry_after`), `Stages\StructureStage` (an `OnDemandStage`, stored in `file_analyses.sections`), `Stages\StemsStage` (stored in `file_analyses.stems`), `Api\DeepAnalysisController`. A failed or killed job records the error and clears its markers.
