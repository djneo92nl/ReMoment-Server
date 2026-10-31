# Stems service (per-stem lanes of a track, workstream 16.4)

A separate image, `docker/stems`, that separates ONE track into **vocals, drums, bass and other** with Demucs (`htdemucs`) and returns only **loudness curves**, the **vocal ratio** and the **instrumental ranges**: no audio leaves the container and no stem audio is stored anywhere. It runs **on demand only** (admin, one track, a button), on its own queue and worker: never across the library, never in the nightly `library:analyze` (see [audio-analysis.md](audio-analysis.md), "On-demand stages"). Karaoke or stem playback is out of scope (it would need the audio).

The choice of model, the measurements on nine tracks, the owner's verdicts and the licence findings are in [track-stems-spike.md](track-stems-spike.md). The UI is in [../frontend/stem-lanes.md](../frontend/stem-lanes.md); the REST contract in [../api/deep-analysis.md](../api/deep-analysis.md).

## Contract

```
GET  /health  → { ok, version, method, rate_hz, max_duration, loading, error }
POST /stems   multipart: audio (any file ffmpeg reads; the app sends the original file)
```

```json
{ "version": 1, "duration": 141.2, "method": "htdemucs", "rate_hz": 2,
  "stems": { "vocals": [-31, -29, …], "drums": […], "bass": […], "other": […] },
  "vocal_ratio": 0.929,
  "instrumental": [ { "start": 13.0, "end": 23.0 } ] }
```

- **Curves**: integer dB, the RMS of the stem's mono mix over one frame, relative to full scale, clamped to -80..0 (-80 = silence), one value per 0.5 s (`rate_hz` 2; frame `i` starts at `i / 2` s). A 4-minute track is 4 x 480 values, about 8 KB of JSON (5 KB for a 2-minute track as measured).
- **`vocal_ratio`** 0-1 and **`instrumental`** `[{start, end}]` in seconds come from the vocals curve alone: median-smoothed over 2.5 s, a frame is quiet when it is more than **20 dB** under the track's own 90th percentile, quiet runs less than 3 s apart join, a run of at least **8 s** is an instrumental range. A track whose vocals never reach -50 dB has no vocals: one range over the whole length, ratio 0. The constants are in `docker/stems/separate.py` (`DROP`, `MIN_LEN`, `MERGE`, `VOCAL_FLOOR`); the spike's thresholds, judged right by the owner on five tracks.
- **Health**: `ok` is true once the model is loaded. A service that cannot fetch its weights (offline, full volume) answers `ok: false` with `error`; one still loading says `loading: true`. The app treats `ok: false` as "not available" (the Settings › Health probe "Stems service" says which: ok / loading (warning) / error with the reason).
- **Errors**: `413` longer than `STEMS_MAX_DURATION` (default 1200 s = 20 minutes) or an upload over `STEMS_MAX_UPLOAD_MB` (600), `422` not audio, `503` the model is not available. One request at a time (one uvicorn worker plus a lock), so two requests cannot double the RAM.

## Model and settings

`htdemucs` (Demucs 4.1.0, the maintained fork `adefossez/demucs`, MIT code), PyTorch 2.5.1 CPU, no shifts, segment 7.8 s (the model's own), overlap 0.25. The track is decoded by ffmpeg to 44.1 kHz stereo **as a stream** and separated in **60 s windows with 2 s padding on each side** (the padding is dropped): this keeps the peak RAM flat at about **1.5 GB for any track length**, where separating the whole track at once grows by about 3.5 MB per second of audio (an 8-minute track was OOM-killed under 3 GB). Demucs's own `--segment` does not reduce RAM (measured). Threads: `STEMS_THREADS` (default `min(4, cpus)`, the production compose file sets 2 with `cpus: 2`).

The **weights (84 MB) are not in the image or the repo**: the `demucs` package downloads them from Hugging Face (`adefossez/HTDemucs`) at first use into `/models` (a named volume, `HF_HOME`), and the service preloads them at start (`STEMS_PRELOAD=0` turns that off). Offline, the first start has no model and reports it in `/health`.

## Measured (Apple M5 Pro, native linux/arm64, 4 threads, CPU)

| Run | Result |
|---|---|
| Through the running service, Descansos 2:01 under a 2 GB cap | 56 s, about 1.4 GB, vocal ratio 0.506, ranges 0:00-0:32 and 1:34-2:01 |
| Through the app (`AnalyzeTrackDeep`), Casualty 2:21 | 65 s, ratio 0.929, range 0:13-0:23 |
| Spike, nine tracks 0:22-8:08 | about **0.45x real time** (0.43-0.48), peak 1.2-1.8 GB whole, flat 1.5 GB windowed |

Image 1.64 GB (python:3.12-slim, ffmpeg, torch 2.5.1 CPU wheel 91 MB, demucs). A mini PC is **not measured**: expect 3-5x slower than the Mac (a 4-minute track in 6-10 minutes) at the same RAM. The app's estimate is `5 s + 0.5 s per second of audio` (`STEMS_SECONDS_FIXED`, `STEMS_SECONDS_PER_SECOND`); set the factor to what your machine measures.

## In the app

- **Stage**: `App\Domain\Analysis\Stages\StemsStage` (an `OnDemandStage`, key `stems`, version `1`), result in `file_analyses.stems` as `{ method, service_version, rate_hz, vocal_ratio, instrumental, lanes: { vocals, drums, bass, other } }` (one migration; the schema review 14.3 consolidates it). It posts the **original file** to `/stems` (the service decodes with its own ffmpeg) and validates the answer: all four lanes present and of one length, values clamped to -80..0.
- **Kind**: `DeepAnalysis::KINDS['stems']`; every kind's stage now says where its result is stored (`column()`), which service (`serviceUrl()`), which queue (`queue()`), its job timeout and its estimate, so `DeepAnalysis`, the job and the endpoints hold no per-kind code. `not_available` (409) when `STEMS_URL` is unset or `/health` is not `ok`; a request for `sections` and `stems` together queues nothing when either is unavailable.
- **Queue**: its own `stems` queue (`STEMS_QUEUE`) with one supervised worker `stems-queue` (`docker/8.4/supervisord.conf` and `.prod.conf`): `nice -n 15`, `--tries=1 --timeout=3300 --memory=128 --max-time=3600 --max-jobs=50`, `stopwaitsecs=3360`. The job's timeout is `STEMS_TIMEOUT` (3000 s: the 20-minute limit at about 2.5 s per second on a small PC) + 60, under the worker's `--timeout` and the Redis `retry_after` (3700). `Queues::all()`/`heartbeat()` include it, so the health page shows it (a heartbeat job per minute; a running Demucs job shows `busy`, not `stuck`, up to 3400 s) and the alert model's `queue.stuck` covers it.
- **API**: `stems`, `vocal_ratio`, `instrumental` in track `details` and in `now_playing` (`NowPlayingStems`, cached a minute); `features.deep_analysis_kinds` lists what can run; the request estimate carries `stems_seconds` and `stems_ram_mb`; MCP `deep_analysis` takes `kinds: ["stems"]`.

## Run it

```bash
docker compose --profile stems up -d --build stems     # dev: service `stems`, host port STEMS_PORT (8092), volume sail-stems-models
# .env: STEMS_URL=http://stems:8092 (from the app container) or http://127.0.0.1:8092 (host)
```

Production (`docker-compose.prod.yml`): same profile, 127.0.0.1:8092, `STEMS_CPUS` (2), `STEMS_THREADS` (2), `STEMS_MEM_LIMIT` (3g), volume `remoment-stems-models`; Ansible `stems_enabled: true` (default **false**) adds the profile and `STEMS_URL=http://127.0.0.1:8092` to `.env`. Tests of the service (pipeline and maths on a synthetic mixture of known tones with the separator replaced by a band split, plus the 413/422/503 paths; one optional test with the real model): `docker run --rm -v "$PWD/docker/stems":/app -w /app remoment-stems:dev sh -c "pip install -r requirements-dev.txt && pytest -q tests"` after `docker build -t remoment-stems:dev docker/stems` (add `-e STEMS_REAL_MODEL=1 -v <weights>:/models` for the real-model test).

## Licence (read before a public release)

Code: Demucs is **MIT**; everything pinned here is permissive. **The htdemucs weights have no stated licence**: the maintained fork's README says "The license of the weights is not clear: MusDB contains some data that were only usable for research purpose", and the Hugging Face repo carries no licence tag. They are downloaded at first use and never committed or baked into the image, and the feature is off by default; that keeps the AGPL repository free of them but does not clarify the terms for someone running it commercially. Open-Unmix's default weights (`umxl`) are CC BY-NC-SA 4.0 and were excluded. Details and pages: [track-stems-spike.md](track-stems-spike.md).

## Verified and not

Verified: the arm64 build and run, the pinned install (`demucs --no-deps`, because `sphn` has no aarch64 wheel and is unused), the tests, the numbers above, the whole path app → service → stored → album page (Casualty in the dev library). **Not verified:** linux/amd64 (the Dockerfile installs torch from the PyTorch CPU index there, because PyPI's amd64 wheel is the CUDA build; nothing was run), a mini PC or Pi, quality beyond the five tracks the owner judged, the supervised worker under real load (the dev container's supervisor config was not rebuilt), and the Ansible switch (written, not run).
