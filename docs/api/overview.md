# Library overview and analysis coverage

`GET /api/library/overview?scope=&client=` returns what the web Library page shows as its "glance" and "sound buckets", plus how far audio analysis is, so a client can say "78 of 78 own tracks analysed" and explain a zero. Open like the rest of the library API; Standard and Full only (Control has no library: `422 not_available_in_profile`). Typed as `LibraryOverview` in `openapi.yaml`; code `App\Domain\Library\LibraryOverview::forApi()`, route `LibraryController::overview`.

```json
{
  "totals": { "artists": 210, "albums": 380, "tracks": 4100, "hours": 291.4 },
  "decades": [ { "decade": 1970, "albums": 40 } ],
  "coverage": [
    { "key": "covers", "label": "Albums with a cover", "have": 370, "of": 380 },
    { "key": "genres", "label": "Artists with genres", "have": 200, "of": 210 },
    { "key": "release_details", "label": "Albums with release details", "have": 300, "of": 380 },
    { "key": "analysed", "label": "Own files analysed", "have": 78, "of": 78 }
  ],
  "sound": {
    "moods":  [ { "name": "relaxed", "albums": 30 } ],
    "tempos": [ { "key": "slow", "from": 0, "to": 90, "albums": 12 }, { "key": "steady", "from": 90, "to": 125, "albums": 40 }, { "key": "fast", "from": 125, "to": null, "albums": 22 } ]
  },
  "formats": [ { "codec": "flac", "bit_depth": 16, "sample_rate": 44100, "tracks": 3000 } ],
  "analysis": {
    "enabled": true, "files": 78, "complete": 60, "with_problems": 2, "backlog": 16,
    "stages": [ { "key": "loudness", "enabled": true, "reason": null, "done": 78, "pending": 0, "failed": 0, "gave_up": 0, "total": 78 } ]
  }
}
```

- `totals`, `decades`, `coverage` are the scope's numbers (own files, DLNA, or both, as `scope` says); cached for a few minutes per scope (`LibraryCache`), so a scan shows up a little late.
- `coverage.analysed` appears only when the library has own files, and counts files with a loudness measurement (the web page's number). The per-stage truth is in `analysis`.
- `sound` counts **albums** with at least one analysed track in the bucket, from own files only (DLNA and Spotify are never analysed), and is empty until analysis has run. Moods come from the Essentia ML build; tempos need the Essentia sidecar. Values are never normalised.
- `formats` is the mix of the own files of the scope's tracks (`codec` falls back to the file extension), at most 20 rows, most tracks first.
- `analysis` is `null` unless the profile is Full. `enabled` is `ANALYSIS_ENABLED`; with it `false` every stage says `reason: "analysis is off"`. `files` is the number of own files that can be analysed. Per stage `done + pending + failed + gave_up = total`; `pending` includes files changed since, or a newer stage version; `failed` is retried, `gave_up` is not. See [../architecture/audio-analysis.md](../architecture/audio-analysis.md) and `/settings/analysis`.
