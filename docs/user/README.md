# ReMoment user documentation

ReMoment Server is a self-hosted web service that controls networked audio and video devices (Bang & Olufsen, Sonos, generic UPnP/DLNA players, Philips Android TVs, Spotify) through one web UI and one REST API. It can also serve your own music files, keep a play history, and publish what plays to MQTT for wall panels and home automation.

It is meant for a **home network**. The REST API and MQTT have no authentication by design; see [reverse-proxy.md](reverse-proxy.md) before exposing it to anything else.

> Status: this is the first version of the user docs. Install methods marked "not available yet" are planned for 1.0 and not built.

## Profiles

`PROFILE` in `.env` decides what runs. You pick one at install time; Control can move to Standard or Full later (`php artisan profile:upgrade`), but a higher profile cannot go back to Control.

| Profile | What it does | Database | Suits |
|---|---|---|---|
| **Control** | Controls devices: playback, volume, sources, multiroom, radio, play history, alerts, backup, REST API, MQTT, web UI. No music library. | SQLite | Raspberry Pi 2 or 3, wall panels (ESP32) and a few devices. A Pi 2 is unverified: it needs 32-bit images (see [install-raspberry-pi.md](install-raspberry-pi.md)). |
| **Standard** | Control plus a music library: DLNA and own-file indexing, metadata and artwork, Spotify browsing, wishlist. | MariaDB | Raspberry Pi 3 (1 GB) and up, or an x86/arm64 machine or LXC with 2 GB. |
| **Full** | Standard plus audio analysis (loudness, BPM, key, mood), deep analysis of a track, library health reports, rip upload. | MariaDB | x86 or arm64, 2 cores and 4 GB RAM or more; 6 GB+ for the mood models. |

The RAM and CPU figures are estimates; nothing has been measured on a Pi yet. Details: [system requirements](../architecture/system-requirements.md), [profiles](../architecture/profiles.md).

## Pages

1. Install (pick one)
   - [Docker Compose](install-docker.md): technical.
   - [Ansible on Proxmox](install-ansible.md): technical.
   - [Raspberry Pi](install-raspberry-pi.md): plain language; the Pi image is not built yet.
2. [First run](first-run.md): the setup wizard and the admin login.
3. [Adding devices](adding-devices.md) and the [hardware table](hardware.md) (placeholder).
4. [Troubleshooting](troubleshooting.md).
5. [Reverse proxy and remote access](reverse-proxy.md).
6. [Upgrading and backups](upgrading-and-backups.md).

Developer documentation (architecture, API contracts) is in [docs/](../../docs/architecture/) and [docs/api/](../api/client-devices.md).
