# Stems spike (workstream 16.4)

Date 2026-10-30/31. Machine: Apple M5 Pro (15 CPUs visible to Docker, Docker Desktop VM with 7.7 GB RAM), native linux/arm64 containers, CPU only, **4 threads** per run. Everything ran in throw-away Docker images; no Python on the host, no audio kept (only loudness curves, a few KB per track). Scripts: [spikes/stems/](spikes/stems/README.md); judgeable pages (one per track, no audio) were generated into `storage/app/spikes/stems/out/` (git-ignored).

Question: can we show a lane per stem (vocals, drums, bass, other) under the waveform, answer "where are the vocals", and find instrumental sections, for **one track on request**, cheaply enough for a mini PC and with a licence we can ship in a public AGPL release?

## Verdict in short

- **Works.** On the nine tracks both models give plausible lanes and the **same instrumental ranges** (within about 1 s). The owner judged the vocals lane and the instrumental ranges on the tracks they know (Descansos, Ice In My OJ, Birds of a Feather, The Emptiness Machine, Casualty) **right**, the bass and drums lanes **plausible**, and **Spleeter as good as htdemucs on these curves**.
- **Recommendation: `htdemucs` (Demucs 4.1.0, the default model), processed in 60 s windows** (flat ~1.5 GB RAM, about 0.45x real time on 4 M5 threads). Spleeter is 5x faster but is a dependency liability (TensorFlow 2.12.1, Python <= 3.11, numpy < 2, 3.2 GB image, ~2.5 GB RAM that grows with the track, OOM-killed on 5-8 minute tracks). **Both have an unclear weights licence**: see below; that is the finding to decide on before the public release.
- Peak RAM is controlled by **windowing the track** (our own loop), not by Demucs's `--segment`.

## Candidates and licences (pages checked 2026-10-30)

| | code | weights | state |
|---|---|---|---|
| **Demucs** `facebookresearch/demucs` | MIT ([README](https://github.com/facebookresearch/demucs)) | **unclear** (below) | repo **archived 2025-01-01**, "not maintained anymore" |
| **Demucs, maintained fork** `adefossez/demucs` | MIT | **unclear** | **v4.1.0, 2026-07-11** (PyPI `demucs` 4.1.0, same date): packaging modernised, weights moved to Hugging Face. The author says he is "not actively working on Demucs anymore": slow replies, no new features |
| **Spleeter** `deezer/spleeter` | MIT | **not stated**; the README says not to contact the maintainers about weights licensing, and to get authorisation for copyrighted material | PyPI 2.4.2 (2025-04-03); last GitHub release v2.3.0 (2021-09-03); pins `tensorflow==2.12.1`, `numpy<2`, Python `<3.12` |
| **Open-Unmix** `sigsep/open-unmix-pytorch` | MIT | **`umxl` (the default) is CC BY-NC-SA 4.0, non-commercial: excluded**. `umxhq`/`umx`/`umxse`: no licence stated on the page (Zenodo records, not read) | PyPI 1.3.0 (2024-04-16); not run |

The weights facts, with the pages:
- **htdemucs** (the weights we would ship): the [adefossez/demucs README](https://github.com/adefossez/demucs#license) says "The license of the weights is not clear: MusDB contains some data that were only usable for research purpose", and that the author cannot grant a licence (Meta custom data plus the original MUSDB data). The [Hugging Face repo `adefossez/HTDemucs`](https://huggingface.co/adefossez/HTDemucs) has **no licence tag and no licence text** (tags: audio, music, music-source-separation, demucs); one 84 MB safetensors file. The `htdemucs_6s` variant "likely means CC-NC-BY" per the same README (not used).
- **Spleeter**: the [README](https://github.com/deezer/spleeter) states only "The code of Spleeter is MIT-licensed", nothing about the weights; the [Models wiki page](https://github.com/deezer/spleeter/wiki/3.-Models) says nothing either. The training data is not described. (Our run downloaded `4stems.tar.gz`, 146 MB, from the GitHub release v1.4.0.)
- **Consequence for AGPL**: shipping *code* is clean for both (MIT). The weights are downloaded by the service at first use (Demucs from Hugging Face by the `demucs` package; Spleeter from GitHub releases), **never committed or baked into the repo**. That keeps the AGPL repo free of the weights, but it does not make the terms clear for a user who runs it commercially. Surfacing this is what the roadmap asked for; a lawyer, or the owner's call, settles it. Mitigation worth building anyway: the weights are fetched on first use into a volume, the feature is off by default, and the docs say plainly "weights licence unclear, personal use".

## Environment and sizes

| | Demucs image | Spleeter image |
|---|---|---|
| base | python:3.12-slim | python:3.11-slim |
| key versions | torch 2.5.1 (CPU, aarch64 wheel **91 MB**), demucs 4.1.0, numpy 2.5.4, einops 0.8.2, julius 0.2.8, safetensors 0.8.0 | tensorflow 2.12.1, spleeter 2.4.2, numpy 1.24.3, pandas 1.5.3, norbert 0.2.1 |
| image size | **1.62 GB** (with ffmpeg) | **3.23 GB** |
| weights | htdemucs **84 MB** (htdemucs_ft: 4 x 84 MB) | 4stems **146 MB** (+ 1.6 MB meta) |
| first-run download | 33 s for the weights (3.6 MB/s link speed measured; no download was near the 500 MB mark: torch 91 MB, TF wheel not measured but the whole build took 49 s) | |

Findings while building:
- `demucs 4.1.0` depends on `sphn`, which **has no aarch64 wheel for Python 3.12 and failed to build** (needs Rust). It is only used for audio I/O, which we do through ffmpeg, so the image installs `demucs --no-deps` plus `einops huggingface-hub julius pyyaml safetensors tqdm`. On amd64 a wheel may exist (unverified). The build is pinned in the spike Dockerfile; the service must pin the same.
- `torch` 2.15 (the newest on PyPI, 2026-10-28) is a 483 MB aarch64 wheel; **2.5.1 is 91 MB** and works with demucs 4.1.0 (`torch>=2.1`). Pin an old, small one.
- **arm64 and amd64**: Demucs/PyTorch CPU runs natively on arm64 (measured). Spleeter's TensorFlow 2.12.1 also installed and ran on arm64 (measured). **amd64 is not measured** (no x86 machine): PyTorch CPU wheels exist for it, TF 2.12.1 amd64 is a 585 MB wheel and needs AVX, which some old mini PCs lack.

## Measurements

Per track, wall time = decode + model load + separation. "Whole" = the track decoded and separated in one go (Demucs `split=True`, segment 7.8 s, overlap 0.25, no shifts); "60 s" = my windowed loop (60 s windows plus 2 s padding each side, padding dropped, decoded from the ffmpeg pipe). Peak RAM = `ru_maxrss` of the Python process, inside a container capped with `--memory` (and no swap).

| track | length | htdemucs wall | htdemucs peak RAM | htdemucs_ft wall / RAM | Spleeter 4stems wall / RAM |
|---|---|---|---|---|---|
| From Zero (Intro) | 0:22 | 11.1 s | 1.21 GB | | 3.6 s / 1.63 GB |
| Descansos | 2:01 | 53.2 s whole; 50.6 s 60 s windows | 1.45 GB; 1.50 GB | 189 s / 2.26 GB | 12.9 s / 2.34 GB |
| Ice In My OJ | 2:12 | 59.1 s | 1.48 GB | | 13.0 s / 2.33 GB |
| Casualty | 2:21 | 67.9 s | 1.50 GB | | 13.4 s / 2.15 GB |
| The Emptiness Machine | 3:10 | 87.3 s | 1.66 GB | | 18.4 s / 2.46 GB (6 GB cap; **OOM at 3 GB**) |
| Birds of a Feather | 3:30 | 122 s (89 s separation + 33 s first-run weights download) | 1.79 GB | 367 s (60 s windows) / 2.14 GB | 20.3 s / 2.36 GB |
| Dark All Day | 5:27 | 158 s | 2.27 GB | | 28.4 s / 2.53 GB (6 GB cap; OOM at 3 GB) |
| Conversation With My Son | 5:30 | 142 s whole (2.39 GB); **146 s in 60 s windows, 1.54 GB, fits a 2 GB cap** | | | **OOM-killed at 6 GB** |
| WeMoveForward | 8:08 | **OOM-killed at 3 GB whole**; **208 s in 60 s windows, 1.52 GB** | | | **OOM-killed at 6 GB** |

(Spleeter ran in 30 s windows as well; run whole, a 2-minute track was OOM-killed at 7 GB. The speed and RAM of the failed Spleeter runs are not known; the Docker VM has 7.7 GB shared with other containers, so a 6 GB cap is optimistic.)

- **Speed**: htdemucs about **0.45x real time** (0.43-0.48 across tracks) on 4 M5 threads, cold start (model load 0.5 s once the weights are cached). htdemucs_ft is 1.55-1.75x real time (3.5x slower than htdemucs; its vocal ratio differs by 7 points on Descansos and Birds of a Feather and the ranges are the same; not worth it). Spleeter about **0.09-0.10x real time**.
- **RAM**: whole-track Demucs grows with the length (1.2 GB at 22 s, 2.4 GB at 5.5 min, OOM at 8 min under 3 GB: about 3.5 MB per second of audio on top of 1.2 GB). **Windowing makes it flat at about 1.5 GB for any length** at the same speed (the windows overlap only 2 x 2 s). Demucs's own `--segment` does not help: segment 4 on Birds of a Feather took 159 s instead of 89 s and the peak was the same (1.75 vs 1.79 GB). Spleeter is 2.1-2.5 GB and grows.
- **Mini PC**: **not measured**. Expect a 4-core x86 mini PC (N100 class) to be about 3-5x slower than 4 M5 threads, so **1.5-2.5x real time**: a 4-minute track in 6-10 minutes, same 1.5 GB of RAM plus the OS; a Pi 4/5 is not a target (the roadmap already says Full profile only). Treat the estimate as seconds = duration x factor, with the factor calibrated per machine (see the proposal below).
- **The dB curves** are the RMS of the mono mix of each stem per 0.5 s. Stem audio is never written.

## Curves, vocal ratio and instrumental ranges

Detection (`spikes/stems/instrumental.py`, pure Python so the service can use it as is): smooth the vocals curve (median over 5 frames = 2.5 s), `ref` = the track's own 90th percentile, a frame is "quiet" when it is more than **20 dB** below `ref`; a run of quiet frames of at least **8 s** is an instrumental range; if `ref` itself is under -50 dB there are no vocals at all (the whole track is instrumental). `vocal_ratio` = 1 - instrumental seconds / duration.

| track | htdemucs vocal ratio | htdemucs instrumental ranges | Spleeter ratio / ranges |
|---|---|---|---|
| From Zero (Intro) | 1.00 | none | 1.00 / none |
| Descansos | 0.51 | 0:00-0:32, 1:34-2:01 | 0.49 / 0:00-0:33, 1:33-2:01 (ft: 0.58, 1:42-2:01) |
| Ice In My OJ | 1.00 | none | 1.00 / none |
| Casualty | 0.93 | 0:13-0:23 | 0.92 / 0:13-0:24 |
| The Emptiness Machine | 0.95 | 0:58-1:07 | 0.95 / 0:58-1:08 |
| Birds of a Feather | 0.96 | 3:22-3:30 | 1.00 / none (htdemucs_ft: 3:05-3:30) |
| Dark All Day | 0.85 | 0:00-0:18, 3:28-3:40, 5:08-5:27 | 0.90 / 0:00-0:17, 5:12-5:27 |
| Conversation With My Son | 0.74 | 0:00-0:12, 1:13-1:24, 2:46-3:28, 5:10-5:29 | not run |
| WeMoveForward | 0.33 | 9 ranges (0:00-0:28, 0:30-1:14, 1:15-2:01, 2:31-2:54, 3:26-3:41, 3:56-4:40, 5:12-6:20, 6:24-6:58, 7:43-8:07) | not run |

The two models agree where both ran, except short end sections (Birds of a Feather's outro: only htdemucs finds it; Dark All Day's 3:28-3:40: only htdemucs). Things the owner did not judge, so unverified: WeMoveForward (synthwave, mostly instrumental; the gaps of 1-2 s inside "0:00-0:28, 0:30-1:14" show that a short vocal fragment splits one long passage: merge ranges less than about 3 s apart in the build), Dark All Day, Conversation With My Son, From Zero (a 22 s intro that counts as fully vocal).

## Owner's verdicts (2026-10-31)

Known tracks: Descansos, Ice In My OJ, Birds of a Feather, The Emptiness Machine, Casualty. Vocals lane and "where the vocals are": **right**. Instrumental ranges: **right**. Bass and drums lanes: **plausible**. Spleeter versus htdemucs: **as good**.

## Recommendation and proposed contract

**Model**: `htdemucs` (default). Why not Spleeter although it looks as good and is 5x faster: its pins (TensorFlow 2.12.1, numpy < 2, Python <= 3.11) and 3.2 GB image make it a poor base for a service that must stay buildable, its RAM grows with the track length, and its last release is 2025 for a 2019 model. It stays the fallback if a mini PC turns out too slow (then the service gets an `ENGINE` switch; not built unless needed). Not htdemucs_ft: 3.5x slower, no visible gain.

**Settings**: segment 7.8 s (the model's own), overlap 0.25, shifts 0, **60 s windows with 2 s padding**, 4 threads (`STEMS_THREADS`, default `min(4, cpus)`), mono mix of each stem, 0.5 s frames, RAM about 1.5 GB (document 2 GB as the limit; verified under a 2 GB cap).

**Defaults**: opt-in profile `stems` (off), on demand per track, one request at a time, duration limit 20 minutes (413; at 0.45-2.5x real time that is up to 50 minutes of CPU), weights fetched at first use into a volume (never in the image or the repo).

**`POST /stems`** (multipart `audio`) returns:

```json
{ "version": 1, "duration": 210.4, "method": "htdemucs", "rate_hz": 2,
  "stems": { "vocals": [-31, -29, ...], "drums": [...], "bass": [...], "other": [...] },
  "vocal_ratio": 0.96,
  "instrumental": [ { "start": 202.0, "end": 210.0 } ] }
```

Curve values are **integer dB (RMS of the stem's mono mix per frame, relative to full scale), clamped to -80..0**, one per 0.5 s (`rate_hz: 2`): a 4-minute track is 4 x 480 values, about 8 KB of JSON, a 10-minute track about 20 KB. Silence is -80. Instrumental ranges are in seconds, ranges closer than 3 s merged. Stored as `file_analyses.stems` (this JSON) with a version and timestamp like `sections`. `GET /health` as the structure service.

**Estimate** for the button: seconds = duration x factor, factor 0.5 measured on 4 M5 threads (config `analysis.stems_seconds_per_second`, to be calibrated on the owner's machine), RAM note "about 1.5 GB".

## What is unverified

- amd64 and any mini PC (speed factor, TensorFlow/AVX, whether `sphn` has an amd64 wheel); a Pi.
- The weights licences (above): nothing was resolved, only documented.
- Quality on tracks the owner did not judge (WeMoveForward, Dark All Day, Conversation With My Son, From Zero) and on live or mono-heavy material.
- Spleeter's speed and RAM on tracks of 5 minutes and longer (OOM in the Docker VM).
- One thread count only (4); the speed with 2 threads or under load.
