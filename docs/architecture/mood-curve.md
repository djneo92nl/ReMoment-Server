# Mood over time (workstream 16.3)

The mood of ONE track as a curve: arousal (calm to intense) and valence (negative to positive), one point per window of a few seconds. It is the same DEAM classifier the nightly analysis already runs, but its per-patch answers are kept instead of averaged into one number. **On demand only** (deep-analysis kind `mood`, [../api/deep-analysis.md](../api/deep-analysis.md)): never across the library, never in `library:analyze`.

## Where it runs

The Essentia sidecar's **ML build** (`docker/analysis`, `ESSENTIA_ML=1`), new endpoint `POST /curve` (multipart `audio` = a mono 16 kHz WAV the app decodes with the shared `DecodePass`, form field `window` in seconds, 1-30, default `MOOD_WINDOW_SECONDS` = 3). The answer:

```json
{ "version": 1, "method": "deam-msd-musicnn-2", "duration": 212.4, "window": 3.0,
  "valence": [0.31, 0.33, ...], "arousal": [0.12, 0.40, ...] }
```

Values are 0-1 (DEAM's 1-9 scores rescaled and clamped like `/analyze` does). `503` without the models (`msd-musicnn-1` and `deam-msd-musicnn-2`), `422` for audio with no samples. It takes the sidecar's one-request-at-a-time lock, so it waits behind a running `/analyze`.

**Windowing** (`docker/analysis/curve.py`, pure Python, tested with `python3 -m unittest discover -s docker/analysis/tests`): MusiCNN returns one row per patch of 3 s and Essentia does not say where each patch sits, so the sidecar spreads the row centres evenly from half a patch after the start to half a patch before the end (exact when the patches tile the audio, a small shift otherwise) and averages the rows whose centre falls in each window; a window without a centre uses the nearest row.

## Stage and storage

`App\Domain\Analysis\Stages\MoodStage` (an `OnDemandStage` on the `analysis` queue, version `1`, kind `mood`). It needs the sidecar's `deam` model: `DeepAnalysis::available('mood')` also checks that `/health` lists it in `models` (`NeedsSidecarModel`), so a sidecar without ML does not offer the kind (`features.deep_analysis_kinds`, `409 not_available`). Stored in `file_analyses.mood_curve` (migration `2026_11_23_100000`) as `{ method, service_version, window, arousal: [0-100], valence: [0-100] }`: integers, about 80 points per list for a 4-minute track (a few hundred bytes). A malformed answer (unequal lists, non-numbers, more than 4000 points) fails the job with a message and stores nothing.

Estimate (config `analysis.mood_seconds_*`): 4 s + 0.05 s per second of audio. **Not measured**: the number is a guess to calibrate on real hardware.

## What clients get

`mood_curve` on the track detail (`details`) and in `now_playing` of `GET /api/devices/{id}`, see `MoodCurve` in `openapi.yaml`. A client draws it as a strip under the waveform; the web UI does it as neutral bars (opacity follows the value, no hue: [../frontend/mood-strip.md](../frontend/mood-strip.md)). Per section the web UI shows the mean of each section ("Most intense: B"), computed from the stored curve and sections (`App\Domain\Analysis\MoodCurve::perSection()`), not stored.

## Verified and not

Verified: the windowing maths (unit tests), the stage, API, UI and states against a faked sidecar. **Not verified:** a run of `/curve` against the real models (the endpoint is written from Essentia's documentation like the rest of `docker/analysis/app.py`: check `curl -F audio=@track.wav localhost:8000/curve` returns lists of the expected length, about duration / window), the patch-centre assumption above, how well DEAM arousal tracks what a listener hears per section (the model is trained on 45 s excerpts), and the time per track on a mini PC or Pi.
