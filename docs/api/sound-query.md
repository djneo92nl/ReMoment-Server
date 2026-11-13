# Finding tracks by sound, and playing a custom selection

What the audio analysis knows of the own files can be queried, not only through the four fixed listening modes. Own files only: DLNA and Spotify tracks are never analysed, so they never match a sound filter. Needs the analysis data (Full profile with `features.analysis`); without it nothing matches. **Values are raw**: the server does not normalise energy or mood (on a real library arousal may run 0.32 to 0.70 and valence 0.33 to 0.75), so a client that wants a full-range slider rescales within the library itself. Typed as `SoundFilter` and `TrackSoundSummary` in `openapi.yaml`; code `App\Domain\Library\SoundFilter`. Field meanings: [library.md](library.md) (TrackAnalysis), wording: [sound-and-quality.md](sound-and-quality.md).

## `GET /api/library/tracks` with a filter

The existing list (`SearchTrack` rows, cursor paging, `scope`) gains optional filters over the analysis of the track's **primary file**. Every criterion given must hold; a track with no analysis, or without the value a criterion tests, never matches.

| Parameter | Meaning |
|---|---|
| `bpm_min`, `bpm_max` | tempo, inclusive |
| `camelot` | comma list of Camelot codes (`8A,9A`), any of them |
| `arousal_min`, `arousal_max`, `valence_min`, `valence_max` | 0 to 1, raw |
| `danceability_min`, `instrumental_min` | 0 to 1 |
| `plr_min` | peak-to-loudness ratio in dB, at least (a dynamic master) |
| `mood` | comma list of `happy`, `sad`, `aggressive`, `relaxed`, `party`, `acoustic`, `electronic`, any of them |
| `lossless_suspect_max` | 0 to 1, at most; lossy files have no value and never match |
| `measured_bit_depth_min` | bits |
| `clipping` | `1` clipping suspected, `0` not (tracks the stage has not judged never match) |

A bad value (outside its range, unknown mood, not a Camelot code) is `422`.

- `include=analysis` adds `analysis` to every row: `{ bpm, camelot, arousal, valence, danceability, instrumental, plr, loudness }` (raw values, each `null` while its stage has not run), or `null` for a track without an analysed file.
- `count_only=1` answers `{ "total": 212 }` for the same scope and filters and nothing else, so a slider can show "212 tracks" without paging.
- `sort=plr|bpm|arousal` (next to `name|artist|added|plays`): highest first, tracks without the value last, ties by id. `GET /api/library/albums?sort=plr|bpm|arousal` orders albums by the **mean** of the value over their analysed tracks, albums without analysis last. "Most dynamic masters first" is `sort=plr` (higher PLR = more dynamic).

```
GET /api/library/tracks?bpm_min=100&bpm_max=125&arousal_max=0.45&instrumental_min=0.5&include=analysis
→ { "data": [ SearchTrack + { "analysis": { "bpm": 112.0, "camelot": "8A", "arousal": 0.38, "valence": 0.41, "danceability": 0.55, "instrumental": 0.91, "plr": 11.2, "loudness": -13.1 } } ], "next_cursor": "…" }
GET /api/library/tracks?count_only=1&bpm_min=100&bpm_max=125   → { "total": 212 }
```

## Playing a custom selection

`POST /api/devices/{id}/library/play-listening-mode` takes, instead of a preset `mode`, `filter: { … }` with the same keys as above (`camelot` and `mood` as a comma string or a list; at least one criterion). Like a preset mode it queues a **fresh random pick of up to 100** playable tracks of the Local library that meet the filter, once (no refill, not a playlist).

```
POST /api/devices/3/library/play-listening-mode
{ "filter": { "bpm_min": 100, "bpm_max": 125, "arousal_max": 0.45, "mood": ["relaxed"] } }
→ { "status": "ok", "queued": 100 }       (a preset mode also answers "mode": "sleep")
```

Errors as for a preset: `422 not_playable` when fewer than 5 tracks fit on this device, `422 unsupported` when the device has no library playback, `503` unreachable, `502 driver_error`; a validation `422` for a bad filter value, an empty filter, or neither `mode` nor `filter`. Use `count_only` first to show how many tracks the filter finds (the pick is also limited to what the device can play).
