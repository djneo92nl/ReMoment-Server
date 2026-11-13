# Library health findings

`GET /api/library/health` gives the counts per report ([sound-and-quality.md](sound-and-quality.md)); `GET /api/library/health/{report}` gives the findings of one report. Full profile only (it needs audio analysis; other profiles answer `422 not_available_in_profile`). Report keys: `transcodes`, `damaged`, `clipping`, `duplicates`, `lossy`, `incomplete`, `unlinked`, `missing` (an unknown key is `404`). Typed as `HealthFinding` in `openapi.yaml`; code `App\Domain\Health\LibraryReports::findings()`, details in [../architecture/library-health.md](../architecture/library-health.md).

```
GET /api/library/health/{report}?limit=100&cursor=
→ { "key": "transcodes", "title": "Suspected transcodes", "description": "…", "count": 3,
    "data": [ Finding ], "next_cursor": "…"|null }
```

```json
{
  "report": "transcodes", "kind": "track",
  "title": "Aphex Twin · Xtal",
  "reason": "FLAC, spectrum ends at 16.5 kHz (95% sure)",
  "track_id": 34, "album_id": 12, "artist_id": 5,
  "file": { "path": "Aphex Twin/SAW 85-92/01 Xtal.flac", "extension": "flac", "size": 31457280 },
  "copies": null,
  "physical": [ { "id": 3, "medium": "cd", "ripped": false, "location": "Shelf B2" } ]
}
```

- `kind` is `track` (one track's file: transcodes, damaged, clipping, lossy), `album` (incomplete: `reason` is "7 of 12 tracks", the ids are the album's), `file` (unlinked, missing: only `file`, no ids) or `group` (duplicates: the best copy's track and file, and `copies` lists every copy best first with a `description` such as "MP3 · 192 kbps · spectrum ends at 19.0 kHz").
- `reason` is one line in the server's wording; use it as is. A transcode is only ever *suspected* (never proof); `count` and the order are the report's own (worst first for transcodes, lossy and clipping).
- `physical` lists the physical-collection items ([physical.md](physical.md)) tied to the finding's album, so a lossy or damaged album the household owns on CD is a re-rip candidate (`ripped` false). It is `[]` when there is none, `null` when the finding has no album, and `null` for a client token (`?client=`): the physical collection is closed to client tokens.
- Paging: `limit` 1 to 200 (default 100), the opaque `cursor` pages by offset in the report's order (ties by file id). Findings change when the analysis runs or files change, so a page can shift between requests. `count` is cached for ten minutes, the findings are live.
