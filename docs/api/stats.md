# Listening statistics

`GET /api/library/stats?from=&to=&scope=&limit=` is the JSON version of the web `/stats` page: what was played, when, where. Open like the rest of the API; works in every profile (it reads the play log, not the library). Statistics are **per household and per device**, never per person (there are no accounts). Typed in `openapi.yaml` as `ListeningStats`; code in `App\Domain\Stats\ListeningStats`, controller `Api\ListeningStatsController`.

| Parameter | |
|---|---|
| `from`, `to` | Days as `Y-m-d` in the **server's timezone**, `to` inclusive. Default: the last 30 days including today. At most 731 days (`422`). `to` before `from` is `422`. |
| `limit` | Length of each top list, 1 to 50, default 10 |
| `scope`, `client` | As on the library lists. Plays of a library track outside the scope are left out; plays **without** a library track (radio, unmatched text-only plays) always count. |

```json
{
  "range": { "from": "2026-10-15", "to": "2026-11-13" },
  "totals": { "plays": 1234, "seconds": 456789, "skipped": 120, "artists": 210, "tracks": 980 },
  "by_hour": [0, 0, 1, "… 24 integers"],
  "by_weekday": ["… 7 integers, 0 = Sunday"],
  "by_day": [{ "day": "2026-11-01", "plays": 12, "seconds": 2400 }],
  "by_source": [{ "source_type": "dlna", "plays": 800 }, { "source_type": "radio", "plays": 200 }],
  "by_device": [{ "device_id": 3, "name": "Living room", "plays": 600, "seconds": 120000, "by_hour": ["… 24 integers"] }],
  "top": { "artists": [ArtistItem + plays], "albums": [AlbumItem + plays], "tracks": [SearchTrack + plays] }
}
```

`by_day` lists every day of the range, oldest first, zero-filled, so a chart needs no gap handling. `by_source` has `unknown` for a play without a source type; `by_device` only devices that have plays in the range. `top.*` items are the usual list items (with `artwork`, null while processed) plus `plays`; artists are counted by the track's artist, albums by the track's album.

## What the numbers mean (call it "plays logged")

- **`seconds`** is the sum of `ended_at - played_at` over **finished** plays. `ended_at` stays null until a `NowPlayingEnded` event (`ClosePlaybackHistory`), so a play still running, or one the server never saw end, counts 0.
- **`skipped`** is a play closed within 30 s of wall clock.
- `StorePlaybackHistory` does not log a repeat of the same artist and title on the same device within 24 hours, so an immediate repeat may be missing.
- Plays without a library track (`track_id` null: radio, tracks that were not in the library when played) have no track, album or artist, so they appear in `plays`, `by_*` and `seconds`, but not in `totals.tracks`, `totals.artists` or the top lists.
- Hours and weekdays are in the server's timezone, like the dates.

## `plays` and `last_played_at` on details

`GET /api/library/albums/{id}` and `GET /api/library/artists/{id}` carry `plays` (integer, all libraries, all time, 0 when never played) and `last_played_at` (ISO 8601, `null` when never played). Each `tracks[]` entry of the album has the same two fields. They count the plays of the library record only (the same numbers `sort=plays` orders by, apart from the scope).

`plays.played_at` has an index (migration `2026_11_13_100000_add_played_at_index_to_plays_table`), so date-range queries do not scan the table.
