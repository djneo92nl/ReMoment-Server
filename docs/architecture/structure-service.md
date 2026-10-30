# Structure service (sections of a track, workstream 16.2)

A small separate image, `docker/structure`, that finds the **sections** of ONE track: where the music changes, and which parts sound like each other. It is the librosa novelty method of the spike ([track-sections-spike.md](track-sections-spike.md), variants A and A2, judged good by ear by the owner). It runs **on demand only** (see [audio-analysis.md](audio-analysis.md), "On-demand stages"): never across the library, never in the nightly `library:analyze`.

Why a second image and not the Essentia sidecar: librosa 1.0.0 needs Python >= 3.12 and numpy 2.x, the Essentia sidecar is Python 3.11 with `numpy<2`. The service has no model and no download: librosa (ISC), numba, scipy, scikit-learn.

## Contract

```
GET  /health     → { ok, version, method, sr, chroma, max_duration }
POST /structure  multipart: audio (a mono WAV is what the app sends; anything libsndfile reads works), min_section? (seconds, 2-120), prominence? (0-1), method? ("novelty-v1")
```

```json
{ "version": 1, "duration": 210.37, "tempo": 103.4, "method": "novelty-v1",
  "sections": [ { "start": 0.0, "end": 23.1, "label": "A", "confidence": 0.7 } ],
  "reason": "too_short" }
```

- `label` is a **repetition letter** (the same letter = it sounds like that other part) or `null`; never "verse" or "chorus". `confidence` is the best cosine similarity to another section with the same letter, 0 when the letter is unique (not calibrated).
- `sections: []` with a `reason`, never a guessed single block: `too_short` (under 30 s), `no_structure` (silence, steady broadband noise, no peak above the threshold, or every part clustering into one letter). `reason` is absent when there are sections.
- Errors: `413` the track is longer than `STRUCTURE_MAX_DURATION` (default 1200 s) or the upload larger than `STRUCTURE_MAX_UPLOAD_MB` (400), with a message saying so; `422` an unknown `method`, a bad `min_section`, or a file that is not audio.
- One request at a time (one uvicorn worker plus a lock), so two requests cannot double the RAM.

## Algorithm and parameters

Beat-synchronous MFCC (20, without c0) + `chroma_cqt`, median per beat, z-scored, 4-beat smoothing; affinity recurrence matrix; a 32-beat (16 + 16) checkerboard kernel gives a novelty curve; `find_peaks` with a minimum distance and prominence; cuts within one minimum section length of the start or end dropped. Letters: agglomerative clustering (cosine, average linkage, threshold 0.6) of the segment means.

| Variant | When | Minimum section | Prominence |
|---|---|---|---|
| A | track of `STRUCTURE_LONG_FROM` (300 s) and up | 12 s (`STRUCTURE_MIN_SECTION_LONG`) | 0.2 (`STRUCTURE_PROMINENCE_LONG`) |
| A2 | shorter | 16 s (`STRUCTURE_MIN_SECTION_SHORT`) | 0.35 (`STRUCTURE_PROMINENCE_SHORT`) |

Other env: `STRUCTURE_SR` (22050), `STRUCTURE_CHROMA` (`cqt` or `stft`), `STRUCTURE_KERNEL_BEATS` (16), `STRUCTURE_LABEL_THRESHOLD` (0.6), `STRUCTURE_NOISE_FLATNESS` (0.3), `STRUCTURE_CHUNK_SECONDS` (0 = off), `STRUCTURE_WARMUP` (1: numba is compiled at start, about 12 s once, not on the first request). `min_section` and `prominence` in a request override the duration rule.

**Noise check (not in the spike):** a mean spectral flatness above 0.3 (measured on up to two minutes from the middle) answers `no_structure`. White noise measures 0.56, the nine spike tracks 0.007-0.05. Only steady broadband noise is caught; a quiet pad or a drone is judged by the novelty peaks (none found → `no_structure`). Checked on nine tracks only.

## Reducing RAM: measured, mostly not adopted

Apple M5 Pro, Docker Desktop, **native linux/arm64** container (python:3.12-slim), nine spike tracks decoded straight from the files, a fresh process per run with numba already compiled. "Peak" is the process peak RSS including about 285 MB of interpreter, numba and librosa; agreement is boundaries within 3 s of the spike's variant (A from 300 s, else A2), reference-in-new / reference total.

| Setting | 8:08 WeMoveForward | 5:27 Dark All Day | 3:30 Birds of a Feather | Agreement with the spike | Adopted |
|---|---|---|---|---|---|
| 22.05 kHz, CQT (the spike's) | 3.7 s, 918 MB | 2.4 s, 705 MB | 1.5-3.3 s, 604 MB | all nine identical (e.g. 12/12, 17/17, 7/7) | **yes** (default) |
| 22.05 kHz, `chroma_stft` | 2.7 s, 940 MB | 1.7 s, 706 MB | 1.2 s, 571 MB | 2/12, 7/17, 2/7 | no |
| 11.025 kHz, CQT | 1.9 s, 586 MB | 1.5 s, 478 MB | 1.0 s, 400 MB | 7/12, 14/17, 6/7 | no |
| 11.025 kHz, `chroma_stft` | 1.7 s, 587 MB | 1.2 s, 475 MB | 0.9 s, 397 MB | 5/12, 4/17, 2/7 | no |
| 22.05 kHz, CQT, features in 60 s chunks (4 s overlap) | 3.3 s, 671 MB | 2.2 s, 544 MB | 3.1 s, 481 MB | seven tracks equal, Dark All Day 15/17, WeMoveForward 12/12 but 3 extra cuts | opt-in (`STRUCTURE_CHUNK_SECONDS=60`) |

So the cheaper sample rate and `chroma_stft` move the boundaries too much (the criterion was agreement on all nine tracks) and are **not adopted**. Chunking saves about 25 % RAM but moves a few borderline peaks on two long tracks, so it stays off by default. The spike measured 0.6-1.5 GB on the Mac; the same pipeline with float32 audio measures 0.45-0.92 GB peak here (and 330-360 MB resident after a request: numba and librosa stay loaded). The default is therefore the spike's pipeline, and the duration limit protects small machines.

Through the running service (`POST /structure`, a 22.05 kHz mono WAV of the track): Birds of a Feather 3.1 s, Dark All Day 1.9 s, WeMoveForward 3.0 s, From Zero (22 s) `too_short` in 10 ms.

## Run it

```bash
docker compose --profile structure up -d --build structure      # dev: service `structure`, host port STRUCTURE_PORT (8091)
# .env: STRUCTURE_URL=http://structure:8091 (from the app container) or http://127.0.0.1:8091 (host)
```

Production (`docker-compose.prod.yml`): same profile, port 8091 on 127.0.0.1, `STRUCTURE_CPUS` (1.5); Ansible `structure_enabled: true` (default false) adds the profile and `STRUCTURE_URL=http://127.0.0.1:8091` to `.env`. Health: Settings › Health lists "Structure service" next to the Essentia sidecar. Tests of the service (synthetic pieces with known cuts, too short, silence, noise, the 413 limit): `docker run --rm -v "$PWD/docker/structure":/app -w /app remoment-structure:dev sh -c "pip install -r requirements-dev.txt && pytest -q tests"` after `docker build -t remoment-structure:dev docker/structure`.

## Verified and not

Verified: native linux/arm64 (Apple Silicon Docker) install of all pinned wheels (numba, llvmlite, scipy, scikit-learn, soundfile), the tests, the numbers above. **Not verified:** linux/amd64 (the Dockerfile has nothing arch-specific, the pins are the spike's), a mini PC and a Raspberry Pi 4/5 (expect several times slower than the Mac, RAM as above plus the limit), quality on classical, live or punk material, and the noise threshold beyond nine tracks.
