# Mood strip (workstream 16.3, UI) and the section marker in the stem lanes (16.4)

## `<x-mood-strip :curve :duration :height :seek :only>`

The stored mood curve (`file_analyses.mood_curve`, [../architecture/mood-curve.md](../architecture/mood-curve.md)) as neutral bars: each column is `currentColor` at an opacity that follows the value (faint when calm, strong when intense; `MoodCurve::opacity()`, never invisible), **no hue** (DESIGN.md keeps colour for artwork; the roadmap's "same palette as the mood tags" is neutral too, the tags are grey chips). Two rows, intensity (arousal) above and positivity (valence) below; `only="arousal"` draws only the first. The curve is reduced to at most 300 columns by averaging (`MoodCurve::columns()`); each column has a time and value tooltip. With `seek` a column calls `seekToSeconds(t)` of the surrounding `progressTicker`.

Two places:

- **Player bar** (`livewire/player-bar.blade.php`): a 4 px intensity strip under the waveform (and under the section strip when both exist), from `NowPlayingMood::for()` (cached a minute like the sections), tap to seek when the device can seek. No button.
- **Album page, Track details**, row **Mood**: Livewire `TrackMood` (`livewire/track-mood.blade.php`), a 22 px two-row strip, a caption ("Top row: intensity… A model's estimate per 3 s, not a measurement.") and, when the track has sections, "Most intense: B (1:00 – 2:20). Calmest: A (0:00 – 1:00)." (`MoodCurve::summary()`, only when the sections differ by at least 10 points).

## `TrackMood` states

Same as `TrackSections`: stored → strip (admin: "Remove"); `queued`/`running` → waiting line with the estimate, polling every 3 s only while it lasts; `failed` → message and "Try again"; nothing stored and `DeepAnalysis::available('mood')` and logged in as admin → "Analyse mood (estimate: about N s)"; otherwise "Not analysed". `analyse` and `remove` abort 403 without the admin login (the REST endpoints are open, the web request is the admin's).

## Section marker in the stem lanes (16.4)

`<x-section-strip … :highlight="true">` makes each block tappable (keyboard: Enter) and sets `sel` in the surrounding Alpine scope; `<x-stem-lanes>` owns that scope (`x-data="{ sel: null, bands }"`) and draws a band (`currentColor` at 16 % with hairline edges, like the instrumental band) across **every** lane at the tapped section, plus a line under the lanes with its time range; a second tap clears it. The bands are computed in PHP as percentages of the lanes' length; only the show/hide is Alpine (thin glue, no JS framework). No new Tailwind classes.

## Section matches (16.5)

Row **Sections like**: Livewire `SectionSimilar` (`livewire/section-similar.blade.php`): a `<x-select>` of the track's sections, and the matches as rows (title, artist and matched time range, similarity %) linking to the album page. Same states and admin gate as `TrackMood`, for kind `section_sound`; without stored sections it says "Analyse the sections first." API: [../api/section-similarity.md](../api/section-similarity.md).

## Checked

Tests: `tests/Feature/MoodAndSectionSoundTest.php` (strip markup, states, lane bands, matches). **Not checked in a browser:** the strips in light and dark at 375 px, the player-bar strip height next to the waveform and section strip, tap-to-seek on the mood strip, the lane highlight, the admin buttons (covered by Livewire tests).
