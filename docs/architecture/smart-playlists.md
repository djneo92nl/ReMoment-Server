# Smart playlists

A smart playlist (`playlists.source = 'smart'`) selects its tracks from the library by **rules** instead of by hand. The selection is written to `playlist_track`, so playing it (web, `POST /api/devices/{id}/library/play-playlist`), the playlist API, artwork covers and "recently played" treat it like any other playlist. It is global like every playlist (no per-user lists).

## Storage

`playlists.rules` (JSON) and `playlists.refreshed_at`:

```json
{
  "match": "all",
  "rules": [
    { "field": "decade", "operator": "is", "value": "1990" },
    { "field": "last_played", "operator": "not_within", "value": 90 }
  ],
  "sort": "random",
  "limit": 50
}
```

`match` is `all` (AND) or `any` (OR). `limit` is 1–500 (default 50). Sorts: `random`, `recently_added`, `most_played`, `least_played`, `last_played`, `newest`, `oldest`, `name`. At most 12 rules.

## Code

- `App\Domain\Library\SmartPlaylist\SmartFields` — the registry of fields. Each is a `SmartField` (key, label, type `select|number|text|bool`, operators, options, and an `apply` closure that adds the conditions to a `tracks` query). **To add a field** (a mood, an energy estimate, …) add one entry to `SmartFields::build()`; the editor and the builder pick it up.
- `SmartPlaylistBuilder` — `normalize()` (drops unknown fields/operators and unusable values; what is stored), `query()` (all matches), `count()`, `tracks()` (sorted and limited), `preview()`, `refresh(Playlist)` (rewrites `playlist_track` in one transaction, idempotent apart from random order).
- `App\Livewire\SmartPlaylistEditor` — the rule editor on `/playlists/{id}` with a live match count and a sample of tracks; saving normalizes the rules, refreshes the playlist and reloads the page.
- `php artisan playlists:refresh-smart [--playlist=ID]`, scheduled daily, so `last_played`, `added` and random lists stay current.

## Fields

| Field | Looks at |
|-------|----------|
| `genre` | canonical genre (`genreables`) of the track, its album or its artist |
| `decade`, `year` | `albums.released_at` (undated albums never match a decade/year; `is not` includes them) |
| `artist`, `title` | name contains / does not contain |
| `country` | `country` metadata of the artist or album (MusicBrainz) |
| `label`, `release_type` | album metadata (MusicBrainz) |
| `tag` | Last.fm `tags` metadata of the track, album or artist |
| `source` | available from `dlna` / `spotify` (`LibrarySources::availableFrom`) |
| `favorite` | the album or the artist is favorited |
| `explicit`, `lossless` | track metadata (`explicit`, `mime_type` of FLAC/WAV/AIFF) |
| `duration` | track length in minutes |
| `plays`, `skips` | counts from `plays` |
| `last_played` | played / not played in the last N days (never played counts as "not") |
| `added` | added to the library in / not in the last N days |

Not available yet (no stored data to select on): mood, BPM, energy.

## Scope

Tracks are limited to what the leading source shows (`LeadingSource::defaultScope()` → `LibrarySources::tracks()`), and radio stubs are left out. A track available from a hidden source is still selected when it is available from another one. Changing the leading source shows in the lists at the next refresh.

## Web

`/playlists` has a "New smart playlist" button next to "New playlist" (`POST /playlists` with `smart=1`, which creates the playlist with empty rules and fills it). Smart playlists can be deleted; the tracks are not edited by hand.
