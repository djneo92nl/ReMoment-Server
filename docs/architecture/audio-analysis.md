# Audio analysis

What the server computes from the audio of its **own files** (`files` / `track_files`, see `file-storage.md`): loudness, ReplayGain, a Chromaprint fingerprint and the MusicBrainz recording it identifies, BPM, key, danceability, mood, instrumentalness and genre. DLNA-only and Spotify-only tracks are never analysed: the app does not see their audio.

Off by default (`ANALYSIS_ENABLED=true` turns it on). It is CPU heavy, so it runs on its own queue, `analysis`, with its own worker; playback history and artwork never wait behind it.

## Tiers

| Tier | Where | What | Needs |
|------|-------|------|-------|
| 0 | the app container | loudness (LUFS, loudness range, true peak) with `ffmpeg`'s `ebur128` filter, which also proves the file decodes; waveform (RMS with per-track contrast, plus raw peaks); quality (spectral cutoff and a transcode verdict, clipping, mono / phase, silence, decoder warnings: see *Quality* below); ReplayGain read from the tags; Chromaprint fingerprint (`fpcalc`); AcoustID lookup | `ffmpeg` and `fpcalc` (`libchromaprint-tools`, in `docker/8.4/Dockerfile`); `ACOUSTID_API_KEY` for the lookup (without one the fingerprint is stored, nothing is looked up) |
| 1 | the `analysis` sidecar (`docker/analysis`, compose profile `analysis`) | Essentia: BPM, key, mode, danceability; with the ML build also mood, arousal/valence, voice vs instrumental, genre | `ANALYSIS_ESSENTIA_URL` |

A Pi 2/3 runs tier 0 only (its app image has the tools; the sidecar is optional and `essentia-tensorflow` is not available on 32-bit ARM). `ffprobe` is not used: getID3 already stores codec, bitrate, sample rate, bit depth and channels on `files` and in the track metadata.

## Configuration (`config/analysis.php`)

| Variable | Default | |
|----------|---------|---|
| `ANALYSIS_ENABLED` | `false` | master switch |
| `ANALYSIS_QUEUE` | `analysis` | queue name; the supervisor program `analysis-queue` works it (`docker/8.4/supervisord*.conf`), `composer dev` listens to `default,analysis` |
| `ANALYSIS_TIMEOUT` | `900` | seconds per file; `DB_QUEUE_RETRY_AFTER` (now 1800) must stay above it |
| `ANALYSIS_NIGHT_HOURS` | `6` | hours of work the nightly `library:analyze` queues: as many files as fit at the measured time per file (`AudioAnalysis::nightlyLimit()`, from the averages `AnalysisActivity` keeps), the rest follows the next night |
| `ANALYSIS_BATCH` / `ANALYSIS_SCAN_BATCH` | `100` / `25` | files queued by the nightly run until a time per file has been measured / after a file scan |
| `ACOUSTID_API_KEY`, `ACOUSTID_MIN_SCORE` | – / `0.9` | free key from acoustid.org; fingerprints leave the server only when it is set |
| `ANALYSIS_ESSENTIA_URL` | – | `http://analysis:8090` (dev compose) or `http://127.0.0.1:8090` (prod, host networking) |
| `ANALYSIS_ESSENTIA_ML` | `false` | the sidecar runs the TensorFlow models; files analysed without them are analysed again |
| `FFMPEG_BINARY`, `FPCALC_BINARY` | `ffmpeg`, `fpcalc` | |
| `contrast_min_span`, `contrast_max_span`, `deviation_*` (config only) | `6`, `30`, window 4 s, max 0.7, PLR 8 to 14 dB | waveform contrast mapping, see *Waveform* |

## How it works (`App\Domain\Analysis`)

- **Stages** (`AnalysisStage`): `loudness`, `waveform`, `quality`, `fingerprint`, `acoustid`, `essentia`, in that order (the lookup uses the stored fingerprint). Each has a `version()` string (`essentia` is `1` or `1+ml`); bumping it re-runs only that stage. `enabled()` says whether this install can run it (switch, binary, key, sidecar).
- **Storage:** `file_analyses`, one row per file (`FileAnalysis`), with typed columns (so smart playlists can range-query them) and `stages` / `errors` JSON. `content_hash` is `files.hash` at analysis time: a changed file starts over. A track reaches its analysis through its primary file (`Track::analysis()`, `Track::sound()`).
- **Job:** `AnalyzeAudioFile` (unique per file, queue `analysis`, `tries` 1) opens the file once with `FileStore::withLocalPath()` (the real path locally, one download from remote storage), runs the stages still needed, and saves after each. A failing stage records its error (`errors.<stage>`: message, attempts, version) and does not stop the others; the backlog retries it until `analysis.max_attempts` (3) at the same version.
- **Backlog:** `AudioAnalysis::pending()` lists present, primary, original files with something left to do, newest first. `php artisan library:analyze [--limit=] [--stage=] [--file=ID [--now]] [--dry-run]` queues it; scheduled nightly at 02:00; `LocalLibraryScanner` queues a small batch after a scan.
- **Essentia stage:** the app decodes to a mono 44.1 kHz WAV with ffmpeg (a branch of the shared decode, see *Reducing CPU load*) and posts it to `POST /analyze` of the sidecar, so the sidecar needs no access to the music and remote files work. Features the sidecar did not compute (no ML models) are left out of the result instead of blanked.

## Waveform (`WaveformStage`, version `2`)

The file is decoded to 4 kHz mono s16 (a branch of the shared decode) and cut into `analysis.waveform_points` equal buckets (400; fewer for a file with fewer samples). Two lists are stored in `file_analyses`:

- **`waveform`** (what the API, player bar, `/receiver` and album page read: integers 0-100, so clients need no change). Per bucket the **RMS**, in dB; optionally minus a share of the **local loudness**; then stretched per track: the **99th percentile** of the track's values is 100, the **5th** is 0, clamped. The floor is never closer than `contrast_min_span` (6 dB) to the ceiling, so a constant tone stays one flat value (100) instead of being blown up into noise, and never further than `contrast_max_span` (30 dB), so one near-silent bucket does not squash the rest. A silent file (ceiling below -90 dB) is all 0. This replaces version 1's peak values scaled to the loudest sample, which on a brickwalled track (about -4.5 LUFS, PLR 4.4 dB) was a flat block. Because it is normalised per track, heights are **not comparable between tracks**.
- **`waveform_peak`**: version 1's values, each bucket's loudest sample relative to the loudest of the file, 0-100. Internal for now (no API exposes it); meant for clipping markers and the section and stem views of workstream 16.
- **Deviation layer.** Subtracting a moving average (`deviation_window_seconds`, 4 s, so about 7 buckets of a 4-minute track) times a strength from the dB curve makes drops and builds stand out even when everything sits near the top. The strength follows the **PLR** (true peak minus integrated LUFS, both from `LoudnessStage`, which runs first): `deviation_max` (0.7) at a PLR of `deviation_plr_low` (8 dB) or less, 0 at `deviation_plr_high` (14 dB) or more, linear between. Without loudness data the layer is skipped. 0.7 rather than 1: a full subtraction would lose that a whole verse is quieter than the chorus.
- **Cost:** about 0.02 s of PHP for a 4-minute file (one bucket unpacked at a time, memory is the 2 MB PCM string); the decode is the same one the other stages share.
- **Re-run:** the version bump makes the backlog pick up every analysed file for this stage only. `php artisan library:analyze --stage=waveform --limit=500` (repeat or let the nightly run do it); `/settings/analysis` counts them as "to redo" until done.

## Quality (`QualityStage`)

The shared decode plus one 30 s excerpt run, no other tool, and no sidecar, so a Pi runs it too:

- **Whole file**, one filter graph of `astats` and `silencedetect` probes (the file is read once and downmixed to stereo): `clipped_samples` (how often the signal sat at its own peak) and `clipping_suspect` (flat tops and at least 50 hits; a limiter touching its ceiling is not clipping), `is_mono` (identical channels) and `phase_issue` (the side signal louder than the mid signal by 6 dB: the channels cancel on a mono speaker), `lead_silence_ms` / `trail_silence_ms` (below -60 dB for 0.3 s), `measured_bit_depth` (lossless files only: a 24-bit file that holds 16-bit samples reads 16) and `decode_warnings` (what the decoder complained about, and a file that decodes more than 2 s shorter than its tags say).
- **Spectral cutoff**: 30 s from the middle of the track, decoded to mono floats (left channel, so out-of-phase material does not cancel) and analysed in PHP (`App\Domain\Analysis\Spectrum`: Hann-windowed 4096-point FFTs of 48 evenly spaced frames, averaged, in 500 Hz bands from 10 kHz up). `spectral_cutoff_hz` is the lower edge of the first band that is 9 dB or more below the one under it while the top band ends 25 dB or more lower: a cliff, not a roll-off. For a lossless file `lossless_suspect` (0–1) turns it into a verdict: 16–17 kHz 0.95, 18 kHz 0.9, 19 kHz 0.8, 19.5 kHz 0.65, 20 kHz 0.45, 20.5 kHz 0.3, above that 0; for sample rates over 48 kHz a cutoff at 22 or 24 kHz means it began as 44.1 or 48 kHz. Lossy files get the cutoff but no verdict. Tracks that are silent in the excerpt cannot be judged.

Checked on generated files (a brown-noise reference encoded and decoded again: MP3 128/192/256/320 kbit/s read 16.5, 19, 19.5 and 20 kHz, AAC 128 kbit/s 17.5 kHz, the original no cutoff) and on a folder of real MP3s (mostly 20 kHz, the cutoff of 320 kbit/s LAME). It is a **hint, never proof**: a recording that is naturally band-limited, or whose own anti-alias filter sits at 20 kHz, looks the same, so clients say "suspected transcode". It runs in a few seconds even on a long track; an earlier design with a bank of band filters inside ffmpeg was over ten times slower on exactly the files with a cutoff and was dropped.

## What the result does for the library

- **MusicBrainz id:** the recording AcoustID names becomes the track's `mbid` metadata with source `acoustid` (score ≥ `acoustid_min_score`). `EnrichTrackMusicBrainz` then looks that recording up directly instead of searching by name (more precise, one request fewer). `Track::recordingMbid()` prefers it for the MusicBrainz link.
- **Duplicates:** `LibraryMerger` (`library:merge-duplicates`) also joins tracks that share an AcoustID recording id and a compatible length, whatever their names, and keeps two tracks with *different* recording ids apart (live vs studio) even when name and length match. Indexing happens before analysis, so this does not change find-or-create in `LibraryIdentity`.
- **Merged tracks keep their files:** `mergeTracks` now moves the loser's `track_files` to the survivor (before, a file lost with its track stopped being served) and points a taken-over `/stream/{id}` URL at the survivor.
- **Genres:** classifier genres (`genre_tags`) only stand in as a track's genres while it has none (`Enrichment::fallbackGenres()`, source `analysis`).
- **Display:** `Track::details()['analysis']` (API: `docs/api/library.md`), the info panel of a track on `/albums/{id}` (BPM · key · loudness, danceability, mood).
- **Smart playlists:** `bpm`, `danceability`, `energy`, `positivity`, `loudness`, `loudness_range`, `plr`, `camelot`, `musical_key`, `mode`, `mood`, `instrumental`, `transcode`, `clipping`, `cutoff` (`smart-playlists.md`).
- **Track info panel** (`/albums/{id}`): the Sound line shows the Camelot code beside the key, and a *Signal* row appears only when something is wrong (suspected transcode and where the spectrum ends, clipping, mono in stereo, out-of-phase channels, decoder warnings).

## The sidecar (`docker/analysis`)

A small FastAPI service. `GET /health` → `{ok, essentia, ml, models}`; `POST /analyze` (multipart `audio`) → `{bpm, key, mode, key_strength, danceability, [mood_tags, arousal, valence, instrumental, genre_tags]}`. One analysis at a time.

```bash
# light build: BPM, key, danceability
docker compose --profile analysis up -d --build analysis
# with the TensorFlow models (large; downloads them into the models volume on first start)
ANALYSIS_ESSENTIA_ML_BUILD=1 docker compose --profile analysis up -d --build analysis
```

`fetch_models.sh` downloads the models from essentia.upf.edu; the URLs and the model input/output names in `app.py` follow Essentia's model pages and were written without running the container, so check them against https://essentia.upf.edu/models.html when a model is skipped (`/health` lists the models that did load; a missing one only drops its features).

## Reducing CPU load

Analysis is already throttled by design: one `analysis-queue` worker (one file at a time), a sidecar that takes one request at a time, and a bounded nightly batch. To lower the load further, or move it to quiet hours:

| Lever | Effect |
|-------|--------|
| `ANALYSIS_NIGHT_HOURS`, `ANALYSIS_SCAN_BATCH` | hours of work queued per nightly run / files after a scan; fewer hours spread a backlog over more nights |
| `Schedule::command('library:analyze')->dailyAt(...)` in `routes/console.php` | move the run to when nobody listens |
| `library:analyze --stage=loudness\|waveform\|quality\|fingerprint\|acoustid\|essentia` | run one stage at a time, e.g. the cheap ones first and `essentia` later |
| Leave `ANALYSIS_ESSENTIA_URL` unset, or `ANALYSIS_ESSENTIA_ML=false` with the light sidecar build | skips the most expensive stage, or only its TensorFlow models |
| Docker `cpus:` / `cpu_shares` on the app and `analysis` services (`compose.yaml`), or `nice` on the supervisor program | caps what analysis may take from device listeners and the web UI; **not configured by default** |
| No `ACOUSTID_API_KEY` | the fingerprint is still stored, no lookup is made (that saves network, not CPU) |

A stage with a bumped `version()` re-runs only that stage, so a changed stage does not redo the others.

**One decode for all stages.** The stages that work on the decoded audio (`DecodedStage`: loudness, waveform, quality's whole-file pass, Essentia) do not each run their own ffmpeg. `AnalyzeAudioFile` runs one (`DecodePass`): the file is decoded once, `asplit` into one filter branch per pending stage (`ebur128`; mono 4 kHz s16 for the waveform; the `astats`/`silencedetect` graph; mono 44.1 kHz WAV for Essentia), each branch's audio going to a temp file or dropped, and the stages read the log (`stderr`) and their file afterwards. A stage that is the only one pending (`--stage=…`) uses the same code with a single branch. If the shared run fails (a broken file), the stages run on their own again so each records its own error. What still runs separately: `fpcalc` (first two minutes, its own decoder) and the 30 s excerpt for the spectral cutoff (seeked to, not decoded from the start). Not done: Essentia on a middle excerpt instead of the whole track, at some cost in BPM/key accuracy on long or varying tracks.

## Health

`/settings/health`, group *Analysis*: `ffmpeg`, `fpcalc`, AcoustID (an invalid probe fingerprint tells a valid key from a rejected one) and the Essentia sidecar (also warns when its ML setting differs from `ANALYSIS_ESSENTIA_ML`). All show *disabled* while analysis is off.

## Status page

`/settings/analysis` (admin login, like all of `/settings`; Livewire `AnalysisStatus`, refreshes every 5 s) answers "how far is it":

- **Summary**: own files, fully analysed (every stage this install can run is done), waiting, with problems; jobs in the `analysis` queue, files finished in the last 24 h, the schedule, and, from the measured time per file, how many hours of work and nights the backlog is at `ANALYSIS_NIGHT_HOURS` a night. "Queue a batch now" runs what the nightly command does.
- **Stages**: per stage a progress bar with done / waiting (and how many of those are *to redo* because the file changed or the stage's version moved on) / failed (retried) / gave up (`analysis.max_attempts` reached). A stage this install cannot run (no ffmpeg, fpcalc, `ACOUSTID_API_KEY`, `ANALYSIS_ESSENTIA_URL`, or analysis off) is greyed with the reason, so "not configured" is not mistaken for "failed". All of it comes from `App\Domain\Analysis\AnalysisProgress`, which applies the same rules as `AudioAnalysis::pendingStages()` over `AudioAnalysis::inScope()` (one pass, cached 60 s).
- **Now**: the stage and file the worker is on (`AnalysisActivity`: cache key `analysis:current`, written by `AnalyzeAudioFile` before each stage and on the shared decode, TTL one job timeout) and the average seconds per stage over the last 50 runs (`analysis:durations:{stage}`). A worker that dies leaves a stale entry that expires with the TTL.
- **Essentia sidecar**: reachable or not, ML build or not, the models it loaded (`/health`, cached 30 s), and a warning when it disagrees with `ANALYSIS_ESSENTIA_ML`.
- **Problems**: every stage error (`file_analyses.errors`), filterable by stage, with message and tries; *Retry* forgets the error and queues just that stage, *Analyse file* queues all pending stages of the file.

`file_analyses.analyzed_at` (set by the job each time a stage finishes, successfully or not) feeds the 24-hour rate and "last activity".

Elsewhere: `/settings/storage` shows one dot per stage on every parsed file (`<x-analysis-dots>`: green done, grey waiting, amber failed, red gave up; the title lists them), `/settings/library-health` shows "Analysed N of M own files" above the counts (a 0 may only mean "not looked at yet"), and the track info panel on `/albums/{id}` has an *Analysis* line while a track's own file is not fully analysed. The Sound line also gives loudness range, true peak and PLR.

## Tests

`tests/Feature/AudioAnalysisTest.php` fakes `Process` (ffmpeg, fpcalc) and `Http` (AcoustID, sidecar); `tests/Feature/Library/SmartAnalysisFieldsTest.php` covers the rules. `phpunit.xml` blanks the API keys so a developer's `.env` never makes tests call the real services.
