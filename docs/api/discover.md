# Discover API: `GET /api/library/discover`

The Discover shelves as data (workstream 4): a list of **sections**, each with a title, a description, a `kind` and items that carry a **`reason` in words**. The web page [`/library/discover`](../frontend/discover.md) draws the same list. Open like the rest of the library API (no login, household-wide: there is no per-user state, "you" is the household). Lay sections out by `kind` and a new shelf needs no client release.

```
GET /api/library/discover?sections=&limit=&seed=&scope=&client=   → { data: [ DiscoverSection ] }
```

| Parameter | |
|---|---|
| `sections` | Comma-separated keys to build (default: all). An unknown key is `422`. |
| `limit` | Items per section, 1 to 60 (default 18, `config/discover.php`). |
| `seed` | Another seed gives another `random_albums` pick; the same seed the same pick. Without it the pick is fresh every time. |
| `scope`, `client` | As everywhere in the library API: the scope's sources and what the client hides are left out. |

```json
{ "data": [ {
  "key": "forgotten_favorites",
  "title": "Forgotten favorites",
  "description": "Albums you favorited and have not played for a while.",
  "kind": "album",
  "items": [ {
    "id": 12, "name": "Parachutes", "artist_name": "Coldplay", "year": 2000, "artwork": { "hash": "…", "proxy_120": "…", "proxy_320": "…", "colors": [], "safe_colors": [] }, "favorite": true,
    "play_days": 5, "last_played_at": "2025-11-02T20:14:00+01:00",
    "reason": "Favorite, played on 5 days, last played 1 year ago"
  } ]
} ] }
```

A section with no items is **left out**, so a small or new library answers a short list (nothing loved, nothing played: only `random_albums`).

## Sections

`kind` says what the items are; every item has `reason` (a sentence you can show as it is).

| Key | `kind` | What it is | Item adds |
|---|---|---|---|
| `new_releases` | `catalogue` | New and upcoming albums and EPs of your artists that the library lacks (the `CatalogueItem` of [availability.md](availability.md), the same as `GET /api/library/new-releases`). Left out for a `client` token (the catalogue is not for client devices) and where the profile has no catalogue. | `release_date`; reason "Released 3 Nov 2026" or "Coming 5 Dec" |
| `forgotten_favorites` | `album` | Favorite albums not played for `stale_days` (180) or not at all, the most played first, never played last. | `favorite`, `play_days`, `last_played_at` (null when never) |
| `used_to_play` | `album` | Albums that are not favorites, played on at least `album_min_days` (4) different days, with no play for `stale_days`. | `play_days`, `last_played_at` |
| `on_this_day` | `album` | Albums you played within `on_this_day_window` (3) days of today's date in an earlier year (up to 10 years back), most days first. | `play_days`, `last_played_at` (the latest play in the window); reason "Played on 21 Nov 2025 (1 year ago)" |
| `forgotten_artists` | `artist` | Artists that are favorites or were played on at least `artist_min_days` (6) days, with no play for `stale_days`. | `favorite`, `play_days`, `last_played_at`, `album_count` |
| `forgotten_tracks` | `track` | Tracks that are favorites or were played at least `track_min_plays` (4) times, with no play for `stale_days`. | `favorite`, `plays`, `last_played_at` |
| `not_played_in_a_year` | `album` | Albums tried a few times (fewer than `album_min_days` days) and not played for `long_ago_days` (365). | `play_days`, `last_played_at` |
| `mixes_well` | `track` | **Full.** Tracks that mix well with the analysed own-file track played last within 30 days: the existing [mixes-with](library.md) rules (tempo, also half and double time, and Camelot key), closest first. Left out without such a play. Carries `seed` `{ id, name, artist_name, bpm, camelot }`. | `bpm`, `camelot`, `key_relation`, `tempo_relation`, `tempo_diff_percent` |
| `listening_modes` | `mode` | **Full.** The listening modes with enough analysed tracks, as in `GET /api/library/listening-modes`. Play one with `POST /api/devices/{id}/library/play-listening-mode`. | `key`, `name`, `description`, `icon`, `track_count` |
| `random_albums` | `album` | Albums of the scope at random. | – |

The sections come in this order: `new_releases`, the rediscover shelves, `mixes_well`, `listening_modes`, `random_albums`. A later workstream (similar tracks) adds its own section in the same shape.

## How "loved" and "forgotten" are decided

Only the play history and the favorites, no external calls and no table.

- **Loved**: a favorite (the heart), or listening: an album or artist played on several **different days**, a track played several times. Days, not plays, because an album played through once is a dozen track plays but one evening.
- **Forgotten**: no play since `stale_days`, or no play on record at all (a favorite that was never played here).
- A play that has no library track (a radio song, a text-only play) belongs to no album and counts for nothing.
- Favorites and "used to play" are disjoint (a favorite album is only on its own shelf), so one album is not on two shelves.
- All the numbers are in `config/discover.php` (`DISCOVER_STALE_DAYS`, `DISCOVER_LONG_AGO_DAYS`, `DISCOVER_ITEMS`, `DISCOVER_CACHE_SECONDS`); a client never decides them.

Each rediscover shelf is one aggregate query over `plays`; its selection (ids and the numbers behind the reason) is cached `DISCOVER_CACHE_SECONDS` (300), and the items are loaded fresh with their relations, so the query count does not grow with the number of items. The random picks are not cached.

## Errors and profiles

- Standard and Full. Control: `422 not_available_in_profile`.
- `mixes_well` and `listening_modes` need audio analysis (Full); in Standard they are left out of the list, not an error.
- `422` for an unknown section key, a `limit` outside 1 to 60 or an unknown `scope`.

MCP: `browse_library` with `kind: "discover"` (parameters `sections`, `limit`, `seed`, `scope`); it goes through this endpoint (`docs/api/mcp.md`).
