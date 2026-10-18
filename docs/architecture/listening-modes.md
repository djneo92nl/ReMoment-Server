# Listening modes

Ready-made selections of the own, analysed files for a situation: **Focus**, **Party**, **Sleep**, **Workout**. A mode is a smart playlist definition (`{match, rules, sort, limit}`, see `smart-playlists.md`) kept in code, so it uses the same fields and builder and needs no table. It is not a device `modes` object (shuffle/repeat/liked) and not a `PlaylistMode`.

## Code

- `App\Domain\Library\ListeningModes`: `all()` (key, name, description, icon, definition), `find()`, `keys()`, `available()` (the modes with at least `MIN_TRACKS` = 5 matching tracks and their counts, cached 10 minutes) and `tracks($key, $device?)` (a query: random order, at most `LIMIT` = 100, narrowed to what the device can play with `LibraryPlayback::playableTracks`). **To add a mode** add one entry to `all()`.
- Thresholds (energy, danceability and positivity are 0–100 %, loudness in LUFS):

| Mode | Rules |
|------|-------|
| Focus | instrumental, energy at most 60 |
| Party | danceability at least 80, energy at least 50, positivity at least 55, 100–140 BPM |
| Sleep | mood relaxed, energy at most 50, danceability at most 70, at most 125 BPM |
| Workout | 125–170 BPM, energy at least 50, danceability at least 70, loudness at least -9 |

They are starting points, checked against 50 analysed tracks of one library (Party 7 matches, Sleep 10, Workout 9, Focus 1 because few tracks there are instrumental). Energy, positivity, mood and instrumental need the ML build of the Essentia sidecar; without it a mode simply never matches and is not offered.

## Where it shows

- Library page (`/library`): a *Listening modes* row of buttons that play on the player pinned in this browser (`POST /library/listening-modes/{mode}`). Only in the Local scope, because only own files are analysed, and left out while no mode has enough tracks. Modes always read the Local scope, whatever leads the library.
- API: `GET /api/library/listening-modes` and `POST /api/devices/{id}/library/play-listening-mode` (`docs/api/library.md`).

A mode picks a fresh random selection every time it is played; it is not stored as a playlist. A selection is queued once: there is no endless refill yet.

## Camelot and dynamics

`App\Domain\Analysis\Camelot` turns `file_analyses.key` + `mode` into the Camelot wheel code (`8A` = A minor, `8B` = C major) and lists the codes that mix well with one (same, one step either way, the relative key). It is shown as `camelot` in `Track::sound()` and is the smart field `camelot`. The smart fields `plr` (true peak minus integrated loudness) and `loudness_range` read columns that already exist; nothing needs a re-analysis.

## Tests

`tests/Unit/CamelotTest.php`, `tests/Feature/Library/ListeningModesTest.php` (fields, each mode, the 5-track floor, both endpoints, the Library page).
