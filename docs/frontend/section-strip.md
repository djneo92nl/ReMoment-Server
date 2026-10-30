# Sections strip (workstream 16.2, UI)

The stored sections of a track (`file_analyses.sections`, see [../api/deep-analysis.md](../api/deep-analysis.md)) drawn as a row of neutral blocks along the track's length. Two places, because the app has no separate "track view": where the waveform already is.

- **Player bar** (`livewire/player-bar.blade.php`): a 6 px strip under the waveform of the playing track, from `NowPlayingSections::for()` (cached a minute, so a result shows up within a minute of the job finishing). Display and seek only; no button. A click on a block calls `seekToSeconds(start)` of `progressTicker` (`public/js/remoment-live.js`), which uses the same `seek` Livewire action as the waveform; a click elsewhere on the waveform still seeks by fraction. Shown only when the device has `seek`.
- **Album page**, "Track details" panel of a track with an own file and a waveform: Livewire `TrackSections` (`app/Livewire/TrackSections.php`), an 18 px strip with letters, plus the state and the admin's request.

## `<x-section-strip :sections :duration :height :labels :seek>`

Blocks are `currentColor` at opacities: the same repetition letter shares a shade, no letter is a faint block. Never "verse"/"chorus". A letter is printed only with `labels` and `confidence >= 0.5`. Each block has a time-range tooltip.

## `TrackSections` states

| Stored | Shows |
|---|---|
| sections | strip with letters (admin: "Remove" to recompute) |
| `[]` + `no_structure` / `too_short` | "No structure found" / "Too short to find sections" (admin: "Analyse again") |
| nothing, `queued` / `running` | waiting / analysing line with the estimate (marked as an estimate); polls every 3 s only while it lasts |
| nothing, `failed` | the message and "Try again" (admin) |
| nothing, admin, `DeepAnalysis::anyAvailable()` | "Analyse sections (estimate: about N s)" |
| nothing, otherwise | "Not analysed" |

The request goes through `DeepAnalysis` directly (the REST endpoints take the bearer token only); `analyse` and `remove` abort 403 without the admin login. Guests only see what is stored.
