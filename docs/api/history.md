# Playback history

The server's own play log as lists. Household-wide (no accounts, no per-person history), newest first, cursor-paged. Open like the rest of the API and available in every profile, Control included. Typed in `openapi.yaml` as `PlayHistoryEntry` / `PlayHistoryPage`; code in `App\Domain\Stats\PlayHistory`, controller `Api\PlayHistoryController`. The numbers behind them (statistics) are in [stats.md](stats.md).

```
GET /api/library/history              ?device_id=&scope=&from=&to=&q=&limit=&cursor=
GET /api/devices/{id}/history         ?scope=&from=&to=&q=&limit=&cursor=
GET /api/library/albums/{id}/plays    ?device_id=&from=&to=&q=&limit=&cursor=
GET /api/library/artists/{id}/plays   ?device_id=&from=&to=&q=&limit=&cursor=
→ { "data": [ PlayHistoryEntry ], "next_cursor": "…"|null }
```

`limit` is 1 to 100 (default 50). `from` and `to` are days (`Y-m-d`, server timezone, `to` inclusive). `q` matches title, artist, album, station and source name. The cursor is opaque (keyset on `(started_at, id)`, so a play logged while you page does not shift the pages); a bad one is `422`. `scope` and `client` trim the household list and the device list like the library lists do: plays of a library track outside the scope are left out, plays without a library track (radio, sources, text-only) always stay. The album and artist lists show **every** play of that record (a merged id resolves to the survivor). An unknown `device_id` is `422`; an unknown device in the path is `404`.

## Entry

```json
{
  "id": 8123,
  "type": "track",
  "name": "Song", "artist_name": "Band", "album_name": "LP",
  "track_id": 34, "album_id": 12, "artist_id": 5,
  "station": null,
  "device": { "id": 3, "name": "Kitchen" },
  "library": "files",
  "source_type": "dlna",
  "artwork": { "hash": "…", "proxy_120": "…", "proxy_320": "…", "colors": [], "safe_colors": [] },
  "duration": 213,
  "started_at": "2026-11-13T09:00:00+01:00",
  "ended_at": "2026-11-13T09:03:33+01:00",
  "listened_seconds": 213,
  "completed": true,
  "skipped": false
}
```

- `type` is `track` (a library track, or one logged as text), `radio` (a station: `name` is the station, `station` has its id, `artist_name`/`name` of the song are not logged) or `source` (line-in, TV and the like: `name` is the source). A play that matches no library track has `track_id`, `album_id` and `artist_id` `null` and carries what was logged (`name`, `artist_name`, `album_name`, `artwork` from the logged image).
- `started_at` is the `played_at` column. `ended_at` stays `null` while the play runs, and for a play the server never saw end; `listened_seconds` is then `null` too.
- `completed` is "closed and not skipped"; `skipped` is a play closed within 30 s of wall clock. Compare `listened_seconds` with `duration` for "heard to the end".
- `library` is the library the track is available from (`files` before `dlna`), `null` without a library track. `device` is `null` after the device was removed.
- `artwork` is the small library artwork object, `null` while the cover is processed.
- The log skips a repeat of the same artist and title on the same device within 24 hours, so a very quick repeat may be missing. Say "plays logged".
