# System requirements

What it takes to run each profile of the server. The RAM/CPU figures are **estimates**, not measurements; the Pi limits and the 6 GB for the ML sidecar come from `docs/architecture/audio-analysis.md` and `docs/architecture/proxmox-deployment.md`.

## What runs

One Docker/supervisor stack: the PHP 8.4 app (served by FrankenPHP in classic mode in production, see [frankenphp.md](frankenphp.md); dev still uses `artisan serve`), Redis, Mosquitto (MQTT), the queue workers, `device:listen` (one booted process that forks a child per device), `ffmpeg` and `fpcalc` (in the app image) and, optionally, MariaDB and the sidecars. **Which of these run is the profile** (`PROFILE` in `.env`, [profiles.md](profiles.md)); Meilisearch is unused by the app and is an optional compose profile (`search`, Full only), in neither the dev nor the production default stack.

## Profiles

| Profile | Features | Database | Containers | Hardware |
|---------|----------|----------|------------|----------|
| **Control** | Device control: listeners, REST API, MQTT, live web UI, radio, play history, alerts, backup. No library, no analysis. | SQLite (named volume, WAL) | app, redis, mosquitto | Raspberry Pi 2 and up. A Pi 2 is 32-bit armv7: every image of the stack needs an armv7 build (unverified); if one is missing the floor is a Pi 3 (arm64). |
| **Standard** | Control plus the library: DLNA/Spotify library scan, metadata enrichment, artwork, own-file indexing, catalogue sync | MariaDB | plus mariadb | Raspberry Pi 3 (1 GB) and up, or an x86/arm64 LXC with 2 GB |
| **Full** | Everything: audio analysis (loudness, waveform, quality, Chromaprint/AcoustID, BPM/key/mood), deep analysis (sections, stems), library health reports | MariaDB | plus the analysis sidecar and optionally structure, stems and Meilisearch | x86 or arm64, 2 cores, 4 GB RAM for tier 0 analysis and Essentia; see below |

Sizing inside Full:

| Tier | Features | Hardware |
|------|----------|----------|
| Recommended | Tier 0 analysis with a usable backlog | x86 or arm64, 2 cores, 2–4 GB RAM (e.g. a small Proxmox LXC). A Pi 3 runs it, but analysis is slow. |
| Essentia | Plus BPM, key, danceability (light sidecar build) | 64-bit, 4 GB RAM, 2–4 cores |
| Essentia + ML | Plus mood, arousal/valence, voice vs. instrumental, genre (TensorFlow models) | 64-bit x86 or arm64, 6 GB+ RAM (`proxmox_ct_memory` 6144+), 4+ cores, ~1 GB disk for the models (downloaded on first start into the `remoment-analysis-models` volume). `essentia-tensorflow` does not exist for 32-bit ARM. |
| Stems | Per-stem loudness lanes of one track, on demand | 64-bit, about 1.5 GB RAM extra and minutes of CPU per track ([stems-service.md](stems-service.md)) |

A good single target for everything: a 4-core, 8 GB arm64/x86 mini PC (or an LXC with 6 GB) with `profile: full` and `analysis_essentia_ml: true`.

The RAM and load figures are **estimates, not measurements**; the per-profile budget on a real Pi 2 and Pi 3 is workstream 9b item 4 and has not been measured. See [profiles.md](profiles.md#not-verified).

## Notes

- **CPU is the real cost.** Analysis decodes each file once with `ffmpeg` for all its stages (see [audio-analysis.md](audio-analysis.md#reducing-cpu-load)), runs FFTs in PHP for the spectral check and Essentia on a mono 44.1 kHz WAV. It runs on its own `analysis` queue, nightly, so playback, history and artwork never wait behind it, but a large library takes days on weak hardware.
- **Disk** is mostly the music files (local or S3). Add a few GB for the artwork cache (3 square sizes and 3 blurred backgrounds per cover) and the database. Control keeps its database in a SQLite file on a named volume (WAL; never a bind mount, see [database.md](database.md)); Standard and Full use MariaDB.
- **Network:** same LAN as the devices (SSDP/mDNS discovery, the Avahi advertisement). Outbound internet for the enrichment APIs (MusicBrainz is rate-limited to about 1 request per second); an AcoustID API key for fingerprint lookups.
