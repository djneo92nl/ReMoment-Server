# Admin audio analysis (`/api/admin/analysis`)

The data and buttons of **Settings › Audio analysis**, behind the admin token (`401 unauthenticated` without one). Web and API call one class, `App\Domain\Analysis\AnalysisAdmin`. Profile feature `analysis` (full profile): other profiles answer `422 not_available_in_profile`. What the stages do and how the backlog works: [audio-analysis](../architecture/audio-analysis.md). MCP: tool `analysis_status`.

```
GET  /api/admin/analysis                       → Status
GET  /api/admin/analysis/problems?stage=       → { limit: 200, data: [Problem] }   newest first
POST /api/admin/analysis/run                   → { status: "ok", queued }          one batch, the size of a nightly run
POST /api/admin/analysis/files/{id}/analyse    → { status: "queued", file_id }     every stage of one file
POST /api/admin/analysis/files/{id}/retry      { stage }   → { status, file_id, stage }
```

## Status

```json
{
  "enabled": true, "files": 8200, "complete": 6100, "backlog": 2100, "with_problems": 14,
  "queued_jobs": 120, "files_last_24h": 310, "last_activity_at": "…", "max_attempts": 3,
  "schedule": { "night_files": 900, "night_hours": 6, "seconds_per_file": 24.1, "nights_to_clear_backlog": 3, "backlog_hours": 14.1 },
  "current": { "stage": "essentia", "file_id": 4410, "path": "Album/01.flac", "started_at": "…" },
  "decode_seconds_per_file": 3.2,
  "sidecar": { "ok": true, "essentia": "2.1", "ml": true, "models": ["…"], "error": null },
  "stages": [ { "key": "loudness", "description": "…", "version": "2", "enabled": true, "reason": null,
                "done": 7000, "pending": 1200, "stale": 40, "failed": 3, "gave_up": 2, "seconds_per_file": 1.9 } ]
}
```

- `stages[]` is in run order. `reason` says why a stage does not run (no ffmpeg, no key, analysis off). `stale` is the part of `pending` that was done before and runs again (the file changed or the stage's version moved on). `failed` will be retried; `gave_up` reached `max_attempts`.
- `current` is `null` when idle; its `stage` can be `decode` (the shared decode of loudness, waveform, quality and Essentia). `sidecar` is `null` when no sidecar URL is set or analysis is off. `seconds_per_file` is `null` until enough runs are measured.
- The progress is one pass over the files, cached for a minute (`run` and `retry` clear the cache).

## Problems

`{ file_id, path, stage, message, attempts, gave_up, at }`. `gave_up` is true once `attempts` reached `max_attempts`; the nightly run skips those until **retry** resets them. `stage` filters (`422` for an unknown stage). At most 200 rows.

## Actions

- **run** queues the files with something left, up to the nightly batch size; `queued: 0` means nothing is waiting. `409 analysis_disabled` when `ANALYSIS_ENABLED` is off.
- **analyse** queues every pending stage of one file (the job de-duplicates, so a second tap while it waits does nothing). `409 analysis_disabled` when off, `404` for an unknown file.
- **retry** needs the file to have an analysis row (`409 not_analysed`; use analyse for a new file). It forgets the stage's error and attempts and queues just that stage; with analysis off it only clears (`status: cleared`).

Unverified on a real install with the sidecars running: the tests fake the queue.
