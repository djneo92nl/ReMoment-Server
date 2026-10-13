# Audio analysis

What the server computes from the audio of its **own files** (`files` / `track_files`, see `file-storage.md`): loudness, ReplayGain, a Chromaprint fingerprint and the MusicBrainz recording it identifies, BPM, key, danceability, mood, instrumentalness and genre. DLNA-only and Spotify-only tracks are never analysed: the app does not see their audio.

Off by default (`ANALYSIS_ENABLED=true` turns it on). It is CPU heavy, so it runs on its own queue, `analysis`, with its own worker; playback history and artwork never wait behind it.

## Tiers

| Tier | Where | What | Needs |
|------|-------|------|-------|
| 0 | the app container | loudness (LUFS, loudness range, true peak) with `ffmpeg`'s `ebur128` filter, which also proves the file decodes; ReplayGain read from the tags; Chromaprint fingerprint (`fpcalc`); AcoustID lookup | `ffmpeg` and `fpcalc` (`libchromaprint-tools`, in `docker/8.4/Dockerfile`); `ACOUSTID_API_KEY` for the lookup (without one the fingerprint is stored, nothing is looked up) |
| 1 | the `analysis` sidecar (`docker/analysis`, compose profile `analysis`) | Essentia: BPM, key, mode, danceability; with the ML build also mood, arousal/valence, voice vs instrumental, genre | `ANALYSIS_ESSENTIA_URL` |

A Pi 2/3 runs tier 0 only (its app image has the tools; the sidecar is optional and `essentia-tensorflow` is not available on 32-bit ARM). `ffprobe` is not used: getID3 already stores codec, bitrate, sample rate, bit depth and channels on `files` and in the track metadata.

## Configuration (`config/analysis.php`)

| Variable | Default | |
|----------|---------|---|
| `ANALYSIS_ENABLED` | `false` | master switch |
| `ANALYSIS_QUEUE` | `analysis` | queue name; the supervisor program `analysis-queue` works it (`docker/8.4/supervisord*.conf`), `composer dev` listens to `default,analysis` |
| `ANALYSIS_TIMEOUT` | `900` | seconds per file; `DB_QUEUE_RETRY_AFTER` (now 1800) must stay above it |
| `ANALYSIS_BATCH` / `ANALYSIS_SCAN_BATCH` | `100` / `25` | files queued by the nightly `library:analyze` / after a file scan |
| `ACOUSTID_API_KEY`, `ACOUSTID_MIN_SCORE` | – / `0.9` | free key from acoustid.org; fingerprints leave the server only when it is set |
| `ANALYSIS_ESSENTIA_URL` | – | `http://analysis:8090` (dev compose) or `http://127.0.0.1:8090` (prod, host networking) |
| `ANALYSIS_ESSENTIA_ML` | `false` | the sidecar runs the TensorFlow models; files analysed without them are analysed again |
| `FFMPEG_BINARY`, `FPCALC_BINARY` | `ffmpeg`, `fpcalc` | |

## How it works (`App\Domain\Analysis`)

- **Stages** (`AnalysisStage`): `loudness`, `fingerprint`, `acoustid`, `essentia`, in that order (the lookup uses the stored fingerprint). Each has a `version()` string (`essentia` is `1` or `1+ml`); bumping it re-runs only that stage. `enabled()` says whether this install can run it (switch, binary, key, sidecar).
- **Storage:** `file_analyses`, one row per file (`FileAnalysis`), with typed columns (so smart playlists can range-query them) and `stages` / `errors` JSON. `content_hash` is `files.hash` at analysis time: a changed file starts over. A track reaches its analysis through its primary file (`Track::analysis()`, `Track::sound()`).
- **Job:** `AnalyzeAudioFile` (unique per file, queue `analysis`, `tries` 1) opens the file once with `FileStore::withLocalPath()` (the real path locally, one download from remote storage), runs the stages still needed, and saves after each. A failing stage records its error (`errors.<stage>`: message, attempts, version) and does not stop the others; the backlog retries it until `analysis.max_attempts` (3) at the same version.
- **Backlog:** `AudioAnalysis::pending()` lists present, primary, original files with something left to do, newest first. `php artisan library:analyze [--limit=] [--stage=] [--file=ID [--now]] [--dry-run]` queues it; scheduled nightly at 02:00; `LocalLibraryScanner` queues a small batch after a scan.
- **Essentia stage:** the app decodes to a mono 44.1 kHz WAV with ffmpeg and posts it to `POST /analyze` of the sidecar, so the sidecar needs no access to the music and remote files work. Features the sidecar did not compute (no ML models) are left out of the result instead of blanked.

## What the result does for the library

- **MusicBrainz id:** the recording AcoustID names becomes the track's `mbid` metadata with source `acoustid` (score ≥ `acoustid_min_score`). `EnrichTrackMusicBrainz` then looks that recording up directly instead of searching by name (more precise, one request fewer). `Track::recordingMbid()` prefers it for the MusicBrainz link.
- **Duplicates:** `LibraryMerger` (`library:merge-duplicates`) also joins tracks that share an AcoustID recording id and a compatible length, whatever their names, and keeps two tracks with *different* recording ids apart (live vs studio) even when name and length match. Indexing happens before analysis, so this does not change find-or-create in `LibraryIdentity`.
- **Merged tracks keep their files:** `mergeTracks` now moves the loser's `track_files` to the survivor (before, a file lost with its track stopped being served) and points a taken-over `/stream/{id}` URL at the survivor.
- **Genres:** classifier genres (`genre_tags`) only stand in as a track's genres while it has none (`Enrichment::fallbackGenres()`, source `analysis`).
- **Display:** `Track::details()['analysis']` (API: `docs/api/library.md`), the info panel of a track on `/albums/{id}` (BPM · key · loudness, danceability, mood).
- **Smart playlists:** `bpm`, `danceability`, `energy`, `positivity`, `loudness`, `musical_key`, `mode`, `mood`, `instrumental` (`smart-playlists.md`).

## The sidecar (`docker/analysis`)

A small FastAPI service. `GET /health` → `{ok, essentia, ml, models}`; `POST /analyze` (multipart `audio`) → `{bpm, key, mode, key_strength, danceability, [mood_tags, arousal, valence, instrumental, genre_tags]}`. One analysis at a time.

```bash
# light build: BPM, key, danceability
docker compose --profile analysis up -d --build analysis
# with the TensorFlow models (large; downloads them into the models volume on first start)
ANALYSIS_ESSENTIA_ML_BUILD=1 docker compose --profile analysis up -d --build analysis
```

`fetch_models.sh` downloads the models from essentia.upf.edu; the URLs and the model input/output names in `app.py` follow Essentia's model pages and were written without running the container, so check them against https://essentia.upf.edu/models.html when a model is skipped (`/health` lists the models that did load; a missing one only drops its features).

## Health

`/settings/health`, group *Analysis*: `ffmpeg`, `fpcalc`, AcoustID (an invalid probe fingerprint tells a valid key from a rejected one) and the Essentia sidecar (also warns when its ML setting differs from `ANALYSIS_ESSENTIA_ML`). All show *disabled* while analysis is off.

## Tests

`tests/Feature/AudioAnalysisTest.php` fakes `Process` (ffmpeg, fpcalc) and `Http` (AcoustID, sidecar); `tests/Feature/Library/SmartAnalysisFieldsTest.php` covers the rules. `phpunit.xml` blanks the API keys so a developer's `.env` never makes tests call the real services.
