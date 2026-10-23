# Sound, quality and listening modes: what clients show

What the server computed from the audio of its **own files** (see `docs/architecture/audio-analysis.md`), and the listening modes built on it. The shapes are in [library.md](library.md) (`TrackAnalysis`, `GET /api/library/listening-modes`); this page says how to use them. The web app is the reference: when in doubt, copy what it shows.

## Where it is

`GET /api/library/albums/{id}` → `tracks[].details.analysis` (`TrackAnalysis`). Nothing else carries it: lists and playlists do not.

- `analysis` is **`null`** for a track whose audio the server never sees (a DLNA server's track, a Spotify track) and for every track while analysis is switched off. Show nothing, not "unknown".
- Each field is **`null`** while the stage that computes it has not run for that file. Older analyses gain the quality fields over days, because the nightly backlog works through the library; BPM, key, mood and the like need the Essentia sidecar, which a Raspberry Pi does not run. Treat `null` as "no information", never as "no" or zero.
- `decode_warnings` is always a list (empty when there is nothing).

## The fields

| Field | Meaning | Web app |
|-------|---------|---------|
| `bpm`, `key`, `mode`, `camelot`, `loudness` | tempo, key, major/minor, the key on the Camelot wheel (`8A` = A minor, `8B` = C major), integrated loudness in LUFS | *Sound* line: `124 BPM · A minor (8A) · -9.2 LUFS`; each part left out when `null` |
| `loudness_range`, `plr` | EBU R128 loudness range (LU) and peak-to-loudness ratio (dB, `true_peak - loudness`: under about 8 is heavily compressed, 14 and up is dynamic) | not shown on a track; usable as smart-playlist fields |
| `danceability`, `arousal`, `valence`, `instrumental`, `mood` | 0–1 fractions and classifier moods | *Danceability* row (`80% · instrumental`), *Mood* row |
| `lossless_suspect` | 0–1, **lossless files only** (`null` for lossy files): how likely the file is a transcode of a lossy source or an upsampled "hi-res" file | *Signal* row when **0.5 or more**: "Suspected transcode, spectrum ends at 16.5 kHz" |
| `spectral_cutoff_hz` | where the spectrum falls off a cliff; `null` when it does not (stored for lossy files too) | used in the sentence above |
| `clipping` | the signal sat at its own peak with flat tops, many times | *Signal* row: "Clipping" |
| `mono` | both channels identical | *Signal* row: "Mono in stereo" |
| `phase_issue` | the channels are largely out of phase (they cancel on a mono speaker) | *Signal* row: "Channels out of phase" |
| `decode_warnings` | what the decoder complained about, including "decoded 100 s, the tags say 240 s" | *Signal* row: "Decoder warnings: …" |
| `measured_bit_depth` | the bits a lossless file's samples really use | not shown yet |
| `lead_silence_ms`, `trail_silence_ms` | silence at the start and end (below -60 dB for 0.3 s or more) | not shown yet |

The web app shows the *Signal* row **only when something is wrong**, joined with ` · `. A clean file has no row at all.

### Rules to follow

- **Say "suspected", never "fake".** `lossless_suspect` is a hint from the spectrum. A recording that is naturally band-limited, or whose own anti-alias filter sits near 20 kHz, looks the same. Do not show a verdict below 0.5; the values under that (0.3 at 20.5 kHz, 0.45 at 20 kHz) are weak evidence.
- **Cutoff in kHz with one decimal** (`16500` → `16.5 kHz`).
- **Hi-res that is not.** If `details.quality` says the file is 24-bit and `measured_bit_depth` is 16 or less, the file is padded 16-bit audio. The web app does not say so yet; a client may.
- **No raw numbers for the user** unless a detail view asks for them: `clipped_samples` is not in the API at all, only the boolean.

## Camelot and "mixes well"

`camelot` is a plain label. Two tracks mix well when their codes are the same, one step apart on the wheel with the same letter (`12A` and `1A` are neighbours), or the same number with the other letter (relative major and minor). A client may compute that itself; the server will offer it as a query later, so do not store the rule in more places than needed.

## Listening modes

`GET /api/library/listening-modes` → `{ "data": [ { "key", "name", "description", "icon", "track_count" } ] }` and `POST /api/devices/{id}/library/play-listening-mode` with `{ "mode": "<key>" }` (shapes and errors in [library.md](library.md)).

- **Show the row only when `data` is not empty** and the user is browsing the **Local** library (the web app hides it in the Streaming and All scopes). Modes only ever contain own, analysed files, whatever the leading library setting is, so the list is empty until analysis has run.
- **Keys today:** `focus`, `party`, `sleep`, `workout`. New keys can appear; render any key you do not know with its `name` and a generic icon. `icon` is a Font Awesome solid icon name: only the web app can use it as is, other clients map the `key` to their own symbols.
- **Playing** queues a fresh random selection of up to 100 tracks the device can play, once. There is no endless refill, and the selection is not a playlist you can open. `track_count` is what the mode would play (at most 100).
- **Errors:** `422 unsupported` when the device has no library playback, `422 not_playable` when fewer than 5 of the mode's tracks can play on that device (pick another device or hide the button), `503` unreachable, `502 driver_error` as for any library play. Offer a mode only on devices that report `library_playback`.
- The list is cached for 10 minutes on the server; do not poll it.

## Library health

`GET /api/library/health` → `{ "data": [ { "key", "title", "description", "count" } ] }`: how many findings each report has (suspected transcodes, damaged files, clipping, duplicate recordings, lossy files, incomplete albums, files without a track, missing files). A client can show a badge or a summary line from it, for example "3 suspected transcodes". The findings themselves (titles, details, links to albums) are only on the web page, `/settings/library-health`; open it in a browser instead of building a list. Counts are cached for ten minutes and are zero until the analysis has run. Details in `docs/architecture/library-health.md`.

## Smart playlists

Smart playlists are edited on the web. Clients read `rules_summary` and `rules` of `GET /api/library/playlists/{id}`; the new fields appear there as phrases ("Suspected transcode: yes", "Spectral cutoff at most 18 kHz", "Camelot key is 8A · A minor"). Nothing to implement beyond showing the text.

## Who uses what today

| Client | Uses |
|--------|------|
| Web app | everything above: *Sound* and *Signal* rows on the album page's track info, the Listening modes row on the Library page (Local scope), all new smart-playlist fields in the rule editor, and the Library Health page in Settings (counts and findings) |
| iOS | `camelot` beside the key (album track info and the track row), the Listening modes row on the Local library screen. Not yet: the quality fields |
| ESP32 | nothing. The mode list and the play call are small enough for a touch remote |

Update this table when a client adopts more.
