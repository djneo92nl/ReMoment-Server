# Stem lanes (workstream 16.4, UI)

The stored stems of a track (`file_analyses.stems`, see [../api/deep-analysis.md](../api/deep-analysis.md), [../architecture/stems-service.md](../architecture/stems-service.md)) drawn as one lane per stem under the sections. One place: the album page's "Track details" panel of a track with an own file and a waveform, in the row **Stems** under **Sections**. The player bar is not changed.

- **Livewire `TrackStems`** (`app/Livewire/TrackStems.php`, `livewire/track-stems.blade.php`), lazy like `TrackSections`, same shape.
- **`<x-stem-lanes :stems :instrumental :duration :sections :vocal-ratio>`**: a label column (3.25 rem) and one lane per stem (Vocals, Drums, Bass, Other), `rounded-xl` on the raised neutral surface, the loudness curve as a filled SVG area in `currentColor` at 60 % (neutral ink, no stem colours: DESIGN.md). When sections exist the **section strip** sits on top in the same column, so boundaries line up in time with the lanes. Instrumental ranges are one shaded band (inline `currentColor` at 20 % with hairline edges) across **every** lane, with a tooltip, and listed in the line under the lanes with the vocal share ("Vocals in 93 % of the track. Instrumental (shaded): 0:13 – 0:23."). The lanes span the length of the curves (frames / `rate_hz`), the strip the track's duration; they differ by under a second.
- **Scale**: each lane runs from its own loudest 1 % down 40 dB (`App\Domain\Analysis\StemLanes::heights()`, columns reduced to at most 400 by the loudest frame, a silent lane stays flat) because the stems sit at very different levels; the caption says "Each lane is drawn on its own scale". The path is built in PHP, the view only draws.
- **No new Tailwind classes** beyond what the compiled build already has: the shaded band uses inline styles because `bg-sky-500/15` is not in `public/build` (a new utility needs `npm run build`).

## `TrackStems` states

| Stored | Shows |
|---|---|
| stems | the lanes (admin: "Remove" to recompute) |
| nothing, `queued` / `running` | waiting / separating line with the estimate (marked as an estimate); polls every 3 s only while it lasts |
| nothing, admin, `failed` | the message and "Try again" |
| nothing, admin, `DeepAnalysis::available('stems')` | "Analyse stems (estimate: about N s, needs about 1.5 GB RAM)" (minutes from 2 minutes up) |
| nothing, admin, service not set up or unhealthy | a hint: set `STEMS_URL` and start the stems service (`docker compose --profile stems up -d`) |
| nothing, guest | "Not analysed" |

The request goes through `DeepAnalysis` directly; `analyse` and `remove` abort 403 without the admin login. Guests only see what is stored.

## Checked

Tests: `tests/Feature/TrackStemsUiTest.php`, `tests/Unit/StemLanesTest.php`. In the in-app browser (light and dark, 375 px wide: no horizontal scroll) with a real result (Casualty, dev library): lanes, shaded range and caption render. **Not checked in a browser:** the admin's button and queued/running/failed states (they need the admin login; covered by Livewire tests), the lanes with sections above them (no sections in that library), long tracks.
