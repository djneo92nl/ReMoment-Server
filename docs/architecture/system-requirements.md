# System requirements

What it takes to run the full server, with all library scanning and analysis tools. The RAM/CPU figures are **estimates**, not measurements; the Pi limits and the 6 GB for the ML sidecar come from `docs/architecture/audio-analysis.md` and `docs/architecture/proxmox-deployment.md`.

## What runs

One Docker/supervisor stack: the PHP 8.4 app (today served by `php artisan serve --no-reload`, PHP's built-in server; replacing it with a production web server is planned, see [v1-roadmap.md](v1-roadmap.md) 9a), Redis, Mosquitto (MQTT), the queue workers (`default`, and `analysis` when enabled), `device:listen` (one booted process that forks a child per device), `ffmpeg` and `fpcalc` (in the app image) and, optionally, the Essentia sidecar (compose profile `analysis`). Meilisearch is in the dev compose file only and is not needed in production.

## Tiers

| Tier | Features | Hardware |
|------|----------|----------|
| Minimum | Device control, DLNA/Spotify library scan, metadata enrichment, artwork, file indexing, tier 0 analysis (loudness, waveform, quality, Chromaprint/AcoustID) | Raspberry Pi 3 (1 GB) or Pi 2. Analysis works but is slow. A Pi 2 is 32-bit armv7: no Essentia sidecar. |
| Recommended | The same with a usable analysis backlog | x86 or arm64, 2 cores, 2–4 GB RAM (e.g. a small Proxmox LXC) |
| Essentia | Plus BPM, key, danceability (light sidecar build) | 64-bit, 4 GB RAM, 2–4 cores |
| Essentia + ML | Plus mood, arousal/valence, voice vs. instrumental, genre (TensorFlow models) | 64-bit x86 or arm64, 6 GB+ RAM (`proxmox_ct_memory` 6144+), 4+ cores, ~1 GB disk for the models (downloaded on first start into the `remoment-analysis-models` volume). `essentia-tensorflow` does not exist for 32-bit ARM. |

A good single target for everything: a 4-core, 8 GB arm64/x86 mini PC (or an LXC with 6 GB) with `analysis_essentia_ml: true`.

## Notes

- **CPU is the real cost.** Analysis decodes each file once with `ffmpeg` for all its stages (see [audio-analysis.md](audio-analysis.md#reducing-cpu-load)), runs FFTs in PHP for the spectral check and Essentia on a mono 44.1 kHz WAV. It runs on its own `analysis` queue, nightly, so playback, history and artwork never wait behind it, but a large library takes days on weak hardware.
- **Disk** is mostly the music files (local or S3). Add a few GB for the artwork cache (3 square sizes and 3 blurred backgrounds per cover) and the database. SQLite is for tests; use MySQL/MariaDB/Postgres in production.
- **Network:** same LAN as the devices (SSDP/mDNS discovery, the Avahi advertisement). Outbound internet for the enrichment APIs (MusicBrainz is rate-limited to about 1 request per second); an AcoustID API key for fingerprint lookups.
