# Track sections spike (workstream 16.2)

Date 2026-10-30. Question: which method finds good section boundaries (and maybe repetition labels) on the owner's real music, at what cost per track, under which licence, so the `structure` stage and the strip can be planned. Throw-away scripts: [spikes/sections/](spikes/sections/) (pinned, described in its README). Comparison pages (waveform plus one coloured strip per method, timestamps, no audio) were generated into `storage/app/spikes/sections/out/` (gitignored, `index.html`).

## Recommendation (short)

1. **Boundaries: a librosa novelty method (variant "A2"), in a small `structure` endpoint of the sidecar.** Beat-synchronous MFCC + chroma, a recurrence matrix, a checkerboard-kernel novelty curve, peak picking with a minimum section length. The owner judged it good by ear on most tracks; the coarser A2 settings were better on short songs.
2. **Cost: about 1.2-3 s per track on an Apple M5 Pro, 0.6-1.5 GB RAM** (RAM grows with duration; 8:08 track = 1.5 GB). Mini-PC numbers are **not measured** (expect several times slower; the cost is still seconds to well under a minute).
3. **Licence is clean:** librosa ISC, numba/scipy/scikit-learn BSD. No model, no weights, no download. Essentia SBic (method B) is useless for this and is not needed.
4. **Labels (A B A C ...) only as an unlabelled-by-default guess:** not judged by the owner in this spike, so ship boundaries first (numbered or neutral colours), letters later behind a confidence value; never "verse/chorus" strings.
5. **A trained structure model (allin1, SongFormer) could not be run here**, so "verse/chorus" semantics stay unevaluated: see "Method C".

## Setup and machine

- Mac: Apple M5 Pro (arm64), 48 GB, macOS 27, Python 3.14.7, venv with librosa 1.0.0 (2026-08-11, ISC, needs Python >= 3.12), numba 0.68.1, numpy 2.5.4, scipy 1.18.1, scikit-learn 1.8.0.
- Method B ran in the existing `remomentserver-analysis-1` container: **x86_64 under Rosetta** on this Mac (cpuinfo "VirtualApple"), Python 3.11, essentia-tensorflow 2.1b6.dev1389, numpy 1.26.4. Its timings are therefore not representative of a real x86 box.
- Tracks: the dev library (70 own files, Billie Eilish, Gunship, Hayley Williams, Linkin Park, Mumford & Sons, The Midnight; no classical, live or true punk), nine tracks confirmed by the owner. Files read straight from `storage/app/music-test/`, nothing copied into the repo. Decoded to 22.05 kHz mono; all numbers are one run after the first (the first librosa run of a process paid about 12 s of numba compilation; a long-lived sidecar pays it once, `cache=True` would avoid it across restarts).

## Methods

| | Method | Version / date | Notes |
|---|---|---|---|
| A / A2 | librosa novelty | librosa 1.0.0 (2026-08-11) | beats from `beat_track`; MFCC(20, without c0) + chroma_cqt, median per beat, z-scored, 4-beat smoothing; affinity recurrence matrix; 32-beat checkerboard kernel; `find_peaks` with minimum distance and prominence; cuts within 12/16 s of start or end dropped. A: min 12 s, prominence 0.2. A2: min 16 s, prominence 0.35. Labels: agglomerative clustering (cosine, average linkage, threshold 0.6) of the segment means; confidence = best cosine similarity to a same-letter segment (not calibrated). Under 30 s: no sections. |
| B | Essentia SBic | essentia-tensorflow 2.1b6.dev1389 (`SBic`, also present: `ChromaCrossSimilarity`, `CrossSimilarityMatrix`, `NoveltyCurve`) | MFCC(13) + HPCP(12), pooled to about 0.46 s frames, z-scored; `cpw` 0.5 (1.0 and 1.5 returned one section for the whole track), windows 40/20 s. Boundaries only. |
| C | allin1 (structure model) | 1.1.0 (2023-10-10) | **not run**, see below |
| - | MSAF | 0.1.80 (2023-06-25), MIT | not run: unmaintained since 2023, would add nothing over A |

## Results (nine tracks)

Columns: sections found, wall time, peak RSS. "B in A" = B boundaries within 3 s of an A boundary.

| Track (length) | A sections | A time / RAM | A2 sections | B sections | B time / RAM (Rosetta) | B in A |
|---|---|---|---|---|---|---|
| Billie Eilish, Birds of a Feather (3:30) | 11 | 1.5 s / 840 MB | 8 | 6 | 1.2 s / 311 MB | 2/5 |
| Linkin Park, The Emptiness Machine (3:10) | 7 | 1.4 s / 774 MB | 4 | 4 | 1.1 s / 315 MB | 0/3 |
| Linkin Park, Casualty (2:21) | 9 | 1.3 s / 637 MB | 4 | 2 | 0.8 s / 295 MB | 0/1 |
| Gunship, Dark All Day (5:27) | 18 | 2.2 s / 1253 MB | 14 | 3 | 1.7 s / 314 MB | 1/2 |
| The Midnight, WeMoveForward (8:08) | 13 | 2.6 s / 1501 MB | 9 | 3 | 2.6 s / 347 MB | 1/2 |
| Mumford & Sons, Conversation With My Son (5:30) | 12 | 2.1 s / 1150 MB | 10 | 4 | 1.9 s / 314 MB | 1/3 |
| Hayley Williams, Descansos (2:02) | 5 | 1.3 s / 622 MB | 5 | 3 | 0.7 s / 293 MB | 0/2 |
| Hayley Williams, Ice In My OJ (2:12) | 4 | 1.2 s / 611 MB | 2 | 2 | 0.7 s / 295 MB | 0/1 |
| Linkin Park, From Zero (Intro) (0:22) | 0 | 0.2 s / 184 MB | 0 | 0 | 0.1 s / 276 MB | - |

Boundaries per track and method are in the generated pages (mm:ss lists); they are not reproduced here because they change with the parameters. Agreement between A and B is low (B reports few, often different boundaries), so "agreement between methods" does not validate either one; the by-ear verdict does.

Repetition letters (A): Birds of a Feather ABCDDAAEFGG, Emptiness Machine ABCDEAE, Dark All Day AAABCDDDCDDAAEDDAA, WeMoveForward AAABBBBBAAABB. On the long synthwave tracks the letters mostly separate "same texture" rather than "same part", and WeMoveForward collapses to two letters. **Not judged by the owner, so no claim about their quality.** No semantic labels exist in A or B; I make none.

## Owner's verdicts (by ear, 2026-10-30)

- **A: good for most tracks**; **B: useless** (few boundaries, not where the music changes).
- **A over-segments the short songs** (Birds of a Feather, Emptiness Machine, Casualty, Descansos, Ice In My OJ).
- **A2 is better than A on the short songs** (asked for the five short tracks; the owner answered for the group, not per track). Long tracks (Dark All Day, WeMoveForward, Conversation With My Son) were not singled out, so A stays the better-known good setting there.
- Letters were not rated. No per-track written notes were given; the verdicts above are the owner's chat answers, not a track-by-track table.
- Caveat: Ice In My OJ in A2 has only 2 sections (one cut at 0:57 of 2:12), which suggests prominence 0.35 may start to drop real boundaries; the owner did not call that out.

## Method C: trained structure model (not run)

- **allin1** (MIT per the GitHub README; the PyPI metadata has no licence field; **weights licence not stated** in the README; trained on the Harmonix Set annotations, eight-model ensemble `harmonix-all`). Labels: start, end, intro, outro, break, bridge, inst, solo, verse, chorus. Depends on PyTorch, **NATTEN**, **madmom (from GitHub)** and Demucs (it demixes first, which is the slow part). Its only speed figure is GPU: 10 songs (33 min) in 73 s on an RTX 4090; **no CPU figure**, model download size not stated.
- **Tried here and failed**: (1) on the Mac, `natten==0.17.3` did not compile (libc++ error "`is_arithmetic` cannot be specialized"), with torch 2.4.1 on Python 3.11; (2) in a Linux python:3.11 container the apt and torch downloads ran at about 115 KB/s, so the whole set (compilers, torch, NATTEN, madmom, demucs and weights) would have taken over an hour, and I stopped. Rosetta containers also stalled in `apt`. **This says nothing about allin1's quality**, only that it is not a quick install on a Mac, and its dependency chain (NATTEN, madmom from git, torch, Demucs) is exactly the heavy kind the sidecar should not carry for a first release.
- **SongFormer** (ASLP-lab, 2025-26; paper arXiv 2510.02797): code CC-BY-4.0, **weights licence not stated**, Python 3.10, MuQ and MusicFM as submodules plus downloaded checkpoints, "2-4 s per song" on an L40 GPU, **CPU support not mentioned**. Labels intro, verse, chorus, bridge, inst, outro, silence, pre-chorus. Not tried.
- Unverified for both: CPU time per track on a mini PC, RAM, weights terms. A judgement of semantic labels needs one of them; revisit when a Linux box with fast network and 8+ GB is at hand (a 30-minute follow-up: allin1 on one machine, same nine tracks, same pages).

## Licence facts (pages checked 2026-10-30)

| Item | Licence | Source |
|---|---|---|
| librosa 1.0.0 | ISC | pypi.org/pypi/librosa/json |
| numba, scipy, scikit-learn | BSD-type (from memory of the packages, not re-fetched) | - |
| MSAF 0.1.80 | MIT | pypi.org/pypi/msaf/json |
| allin1 1.1.0 | code MIT (github.com/mir-aidj/all-in-one README); PyPI licence field empty; **weights: not stated** | GitHub, PyPI |
| SongFormer | code CC-BY-4.0; **weights: not stated** | github.com/ASLP-lab/SongFormer |
| Essentia (already in the sidecar) | AGPL-3.0 per my memory, not re-checked here; already an accepted dependency of the sidecar | - |

The Hugging Face model page for allin1 returned 401, so the weights terms are open.

## Fit with the sidecar and arm64

- The sidecar image is Python 3.11, pins `numpy<2`, and ships Essentia (`essentia` or `essentia-tensorflow`, **x86 only**, roadmap 12.10). librosa 1.0.0 needs Python >= 3.12 and numpy 2.x. **The A method does not use Essentia at all**, so it does not need the Essentia wheel; but it cannot be pip-installed next to the current pins without either (a) librosa 0.11 on Python 3.11 with numpy < 2 (*not tested; the script should be re-run to confirm identical output*), or (b) a **second small image** (`docker/structure`, python 3.12+, librosa, FastAPI) behind its own URL. (b) is cleaner: it runs on arm64 too (numba and llvmlite have aarch64 wheels, *verified on macOS arm64 only, Linux aarch64 unverified*), so a Pi 4/5 or a Mac mini can host it, and it has no ML download.
- Beat grid comes from librosa here; the BPM stage's value can seed it (not needed).
- RAM: 0.6-1.5 GB at 22.05 kHz with `chroma_cqt` is on the high side for 4 GB. Likely fixes (*unmeasured*): load at 11.025 kHz, `chroma_stft` instead of CQT, or process in the beat-sync domain only. Set a per-request limit on duration and return "not available here" when it would not fit.

## Proposed contract

`POST /structure` (multipart `audio`, optional `min_section` seconds) in the sidecar (or the sibling image):

```json
{ "version": 1, "duration": 210.4, "tempo": 103.4, "method": "novelty-v1",
  "sections": [ { "start": 0.0, "end": 23.1, "label": "A", "confidence": 0.71 }, ... ] }
```

- `label` is a repetition letter or `null` (never "verse"/"chorus"); `confidence` is the cosine similarity to the best same-letter segment, 0 when the letter is unique. Stored as `file_analyses.sections` (JSON, seconds) by a `structure` stage with its own version; `[]` plus a `reason` (`too_short`, `no_structure`) for a track under 30 s or one with no peak above the threshold (ambient, drone). Return no sections rather than a guessed single block; the strip then shows nothing.
- Parameters by duration: minimum section 12 s and prominence 0.2 for tracks of 5 minutes and up, 16 s and 0.35 below that (what the owner preferred); to be re-tuned on more material.

## Build plan (small PRs)

1. **Sidecar:** `docker/structure` (or `POST /structure` in `docker/analysis` once the Python/numpy question is settled): the A2/A algorithm from `spikes/sections/m_librosa.py`, with a fixture test (synthetic piece with known cuts) and the contract above. Compose profile, arm64 build.
2. **Stage:** `structure` stage and `file_analyses.sections`, versioned; exposes `sections` in the track detail and OpenAPI (field before client use, per 16 shared decisions).
3. **On demand:** `AnalyzeTrackDeep` job (kind `sections`), the `POST/GET /api/library/tracks/{id}/deep-analysis` endpoints, `features.deep_analysis` only when a sidecar answers, admin only, estimate text ("about N seconds").
4. **UI:** the strip above the waveform on the track view, tap to seek, numbered or neutral colours, labels only when confidence is shown.
5. **Later:** repetition letters in the UI; optionally the semantic model (allin1 / SongFormer) behind the same endpoint once the follow-up below says it is worth its footprint.

## Open questions for the owner

- Is A2 for all tracks under about 5 minutes and A above acceptable, or should the minimum section length follow the track length (for example duration / 14)? Rate the five short songs individually once; Ice In My OJ (2 sections in A2) looks too coarse.
- Do you want semantic labels (verse/chorus) badly enough to carry PyTorch, Demucs and NATTEN in a sidecar, with unstated weights terms? The honest default is: neutral strips now, decide after the allin1 follow-up on a Linux box.
- One extra image (`docker/structure`) next to the Essentia sidecar, or one Python 3.11 image with librosa 0.11 after a retest?
- RAM ceiling: is 1.5 GB for an 8-minute track acceptable on your target LXC, or should I optimise (11 kHz, `chroma_stft`) first?

## What I could not verify

CPU time and RAM on a mini PC or Pi (only the Mac and a Rosetta container); semantic labelling quality (no model could be run); allin1 and SongFormer weights terms and download sizes; the quality of repetition letters (not rated); Linux aarch64 wheels for numba/llvmlite; librosa 0.11 on Python 3.11 with numpy < 2; Essentia's current licence page; anything on classical, live or punk material (none in the dev library).
