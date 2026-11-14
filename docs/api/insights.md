# Listening insights

`GET /api/library/insights?days=&scope=&client=` answers four questions from the play log joined with the audio analysis of the own files: which artists get skipped, how many tracks per week are new against repeats, what the mood and tempo of each part of the day is, and what is played late at night. It is the JSON behind the "Insights" cards at the bottom of `/stats`. Household-wide like everything else: no per-person data. Typed as `ListeningInsights` in `openapi.yaml`; code `App\Domain\Stats\ListeningInsights`, controller `Api\ListeningInsightsController`, MCP tool `listening_insights`.

| Parameter | |
|---|---|
| `days` | Window of the last N days **ending today** (inclusive), 1 to 365, default 90 (`422` outside). |
| `scope`, `client` | As on the library lists; plays of a library track outside the scope are left out. |

**Profiles.** Standard and Full (the plays blocks read library tracks); Control answers `422 not_available_in_profile` (the `/stats` cards are not shown there). `parts_of_day` and `late.moods` need analysis data, so they come back with zero plays and `null` BPM/arousal outside the Full profile (`analysis: false`). With nothing analysed (Full, no run yet) they have `analysed_plays: 0`, `null` values and empty `moods`. No block ever errors on empty data.

**Timezone.** Hours, dates and week starts are those stored in `plays.played_at`, i.e. the application timezone (`config('app.timezone')`, reported as `timezone`). A play at 23:30 belongs to the evening, one at 00:30 to the night, whatever the viewer's clock says.

**Cost.** One aggregate query per block on the `plays.played_at` index (`weekly` has a correlated `NOT EXISTS` on `plays.track_id`), result cached five minutes per (`days`, day, scope, analysis on/off) in the application cache; a play added a minute ago shows up a little late.

```json
{
  "range": { "from": "2026-08-16", "to": "2026-11-13", "days": 90 },
  "timezone": "UTC",
  "analysis": true,
  "skips": {
    "rule": { "fraction": 0.5, "min_plays": 3 },
    "plays": 640, "skipped": 90, "rate": 0.141,
    "artists": [{ "id": 12, "name": "Band", "plays": 9, "skipped": 6, "rate": 0.667 }]
  },
  "weekly": [{ "week": "2026-11-09", "new": 14, "repeat": 120 }],
  "parts_of_day": [
    { "part": "night", "hours": { "from": 0, "until": 5 }, "plays": 30, "analysed_plays": 28, "bpm": 96.5, "arousal": 0.31,
      "tempos": { "slow": 12, "steady": 10, "fast": 6 }, "moods": [{ "name": "relaxed", "plays": 20 }] }
  ],
  "late": {
    "hours": { "from": 22, "until": 5 }, "plays": 80,
    "artists": [{ "id": 12, "name": "Band", "plays": 15 }],
    "tracks": [{ "id": 55, "name": "Song", "artist": "Band", "plays": 6 }],
    "moods": [{ "name": "relaxed", "plays": 40 }]
  }
}
```

## Definitions

- **Skip.** One rule, `ListeningInsights::SKIP_FRACTION` (0.5): a **finished** play (`ended_at` after `played_at`) of a track with a **known length** (`plays.duration`, else `tracks.duration`) that lasted **less than half** of that length. Plays with no end (still running, never closed) or no known length are not measured and not in `skips.plays`. Duration is wall clock, so a pause makes a play look longer and the rate is a lower bound. This is not the `skipped` flag of `/api/library/stats` (a play closed within 30 s, whatever the length); the two answer different questions.
- **Artists in `skips.artists`** need `SKIP_MIN_PLAYS` (3) measured plays and at least one skip, are ordered by rate, then plays, then id, and cut at 10. `rate` is rounded to 3 decimals.
- **New against repeat.** A play is *new* when it is the first logged play of its track **ever** (not only in the window), *repeat* otherwise. Plays without a library track (radio, text-only) are not counted. `weekly` lists every week touching the window from Monday, oldest first, zero-filled; the first and last may be partial. `StorePlaybackHistory` skips an immediate repeat of the same artist and title on a device within 24 h, so repeats are under-counted a little.
- **Parts of the day:** night 00:00-04:59, morning 05:00-11:59, afternoon 12:00-17:59, evening 18:00-23:59 (`hours.until` exclusive). Always all four, in that order.
- **Mood and tempo** use the primary own file's analysis, raw values (never normalised). A play counts for **each** mood tag of its track, so mood counts can exceed plays. `bpm` and `arousal` are means over the plays that have the value; `tempos` buckets are below 90, 90 to 125, 125 and up (the library overview's). Moods need the Essentia ML build, tempo the Essentia sidecar.
- **Late** is 22:00 to 04:59 (`LATE_FROM`, `LATE_UNTIL`). `late.plays` counts all plays in those hours including radio; the artist/track lists are library tracks only.
