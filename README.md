```

/$$$$$$$            /$$      /$$                                               /$$    
| $$__  $$          | $$$    /$$$                                              | $$    
| $$  \ $$  /$$$$$$ | $$$$  /$$$$  /$$$$$$  /$$$$$$/$$$$   /$$$$$$  /$$$$$$$  /$$$$$$  
| $$$$$$$/ /$$__  $$| $$ $$/$$ $$ /$$__  $$| $$_  $$_  $$ /$$__  $$| $$__  $$|_  $$_/  
| $$__  $$| $$$$$$$$| $$  $$$| $$| $$  \ $$| $$ \ $$ \ $$| $$$$$$$$| $$  \ $$  | $$    
| $$  \ $$| $$_____/| $$\  $ | $$| $$  | $$| $$ | $$ | $$| $$_____/| $$  | $$  | $$ /$$
| $$  | $$|  $$$$$$$| $$ \/  | $$|  $$$$$$/| $$ | $$ | $$|  $$$$$$$| $$  | $$  |  $$$$/
|__/  |__/ \_______/|__/     |__/ \______/ |__/ |__/ |__/ \_______/|__/  |__/   \___/  
                                                                                                                                                                           
```


# ReMoment

### A modular, network-based media controller layer

ReMoment is a Laravel-based PHP service that acts as a **universal controller** for networked audio and video devices. It is an abstraction layer between user interfaces, automation systems, and a growing ecosystem of network media players, and it doubles as a unified music library across DLNA, Spotify and your own files.

The goal is not to replace native apps, but to provide a **consistent, extensible control surface** for heterogeneous devices that were never designed to work together.

This project is still in development.

---

## What ReMoment Is

ReMoment runs *next to* media servers, streaming services, and playback devices as a **coordination and intelligence layer**. Media stays where it already lives; ReMoment provides the logic that decides how that media moves, appears, and is controlled. It can also serve your own music files itself (see [Own music server](#own-music-server)).

It exposes one REST API (and MQTT topics) for controlling and querying media players across brands and protocols, normalizing:

* Power and availability
* Source selection and per-source controls (CD changer, FM presets, TV digits)
* Transport control (play, pause, stop, next, previous, seek)
* Volume and mute
* Shuffle, repeat and like
* Up next queue
* Now-playing metadata and artwork
* Device settings (bass/treble/loudness, Bluetooth, network, sleep timer, name)
* Playback history and state transitions

Each supported ecosystem is a driver that translates these generic commands into device-specific protocols. Always check a device's `capabilities` before calling a feature endpoint.

---

## Supported Devices

| Ecosystem | Support |
|-----------|---------|
| **Bang & Olufsen ASE** (Beosound / Beolab / BeoPlay V1 and more) | Full: playback, sources, multiroom, power, sound, Bluetooth, network, WiSA and wired speaker setup, sleep timer, library playback |
| **Bang & Olufsen Mozart** platform | Playback, volume, power (standby only), sound, device info, battery, multiroom, DLNA library playback |
| **BeoLink Converter NL/ML** | Added as a device; FM presets of its tuner, join a converter's playing source |
| **Sonos** | Playback, volume, radio, multiroom, library playback, seek, queue, shuffle, repeat, sources, sound adjustment, battery on portable models |
| **Spotify** | Virtual device (Web API) with seek, queue, shuffle, repeat and like; routes to a mapped speaker via Spotify Connect |

Planned / under investigation: Logitech Squeezebox, Bose SoundTouch, Deezer.

Devices are found with `php artisan device:discover` (SSDP, mDNS, Sonos topology) or added by hand. Drivers are composable; Mozart support already ships as separate Composer packages (see `docs/architecture/plugin-architecture.md`).

---

## Features

### Device control
* Live device dashboard, per-device detail page, source manager (hide, reorder, activate)
* **Multiroom** between ASE, Mozart and Sonos, with named presets and a role badge (host / listener) per device
* **Spotify routing**: while a speaker mapped to a Spotify Connect name plays Spotify, speaker and Spotify behave as one device in web, REST and MQTT
* **Per-device settings** pages and endpoints: sound adjustment, Bluetooth pairing, device name and firmware, network and Wi-Fi, WiSA and wired speaker types, sleep timer
* **Radio**: BeoRadio / TuneIn stations per platform, household-wide favorites
* **Battery** level and charging state for portable devices
* Sticky **player bar** for a device pinned in the browser, with play buttons across the library

### Music library
* One record per artist, album and track across all sources (DLNA, Spotify, own files, plays); `library:merge-duplicates` cleans up existing duplicates
* **Libraries** per source with a leading-source setting, scoped browsing (`local`, `streaming`, per library), genres, favorites, recently played, random album, sorting by name, year, added or most played
* **Playback from the library** on any capable device: tracks, albums, artists and playlists, over DLNA first and Spotify second
* **Playlists**: synced Spotify playlists, local playlists, and **smart playlists** built from rules with a live-preview editor and a daily refresh
* Generated **playlist covers** (2×2 mosaics) and **logos** for sources without artwork

### Own music server
* Index your own audio files from local disk or **S3** storage locations, stream them from this server, and play them on your devices
* Inbox import, organizer (`artist/album`), reorganize and verify commands, managed at `/settings/storage` with live status

### Metadata and enrichment
* Asynchronous, rate-limited enrichment from **MusicBrainz, Cover Art Archive, Wikipedia, Spotify, Last.fm, TheAudioDB, Discogs** and lyrics providers
* Canonical genres, artist biographies and photos, album credits and mood, scheduled daily via `library:enrich`
* **Last.fm**: scrobbling, now-playing updates, artist enrichment and backfill
* Playback history, listening **stats** (top artists, devices, hourly heatmap)

### Artwork
* Every cover is downloaded once and served as baseline JPEG proxies (512 / 320 / 120) with blurred full-screen backgrounds for the 7" landscape, 3.5" portrait and square 480×480 client screens, plus dominant and text-safe colors
* The web UI and API never hand out original image URLs
* Pre-render and backfill commands, and an **SD card export** zip for touch clients

### Clients and integration
* **REST API** for native apps, documented in `docs/api/` with an OpenAPI contract (`docs/api/openapi.yaml`)
* **MCP server** at `/mcp` so an AI agent can control devices and manage the library (admin tools need the admin token), see `docs/api/mcp.md`
* **MQTT** topics per device (data, progress, state, volume, battery, multiroom, modes), retained where it matters
* **Live updates**: the browser subscribes to MQTT over WebSocket and refreshes Livewire components on push, falling back to polling
* **Client devices** (ESP32, Raspberry Pi, Squeezebox Touch, ...) self-register, are approved by an admin and see only their assigned devices
* `GET /api/info` bootstrap endpoint, and the server announces itself over **mDNS** (`_remoment._tcp`) so clients find it
* `/receiver`, a full-screen now-playing display

### Administration
* A **single shared admin login** gates setup and configuration; everything else is guest-accessible, matching the REST API. Apps sign in with the same password for a revocable bearer token (`POST /api/admin/login`). There are no user accounts, by design.
* First-time **setup wizard** at `/setup`
* **System health** page: MQTT broker, scheduler and queue heartbeats, failed jobs, per-device listener state
* Ansible playbook for a Proxmox LXC deployment, plus Docker (Laravel Sail) for development

---

## Web Interface

Available at `http://localhost`, built with Blade, Livewire 3 and Alpine.js, styled with Tailwind CSS:

* `/devices`: live device grid; playing devices get a full now-playing card with controls, seek and volume
* `/devices/{id}`: now playing, up next, sources, lyrics, "add to library", device settings
* `/library`, `/albums`, `/artists`, genres, favorites, playlists and smart playlist editor
* `/multiroom`, `/radio`, `/history`, `/stats`, `/receiver`, `/setup`
* `/settings/*`: DLNA servers, storage, Spotify and Last.fm connections, Spotify Connect mapping, client devices, health

---

## Architecture Overview

ReMoment is not only reactive, but **observant**. Device listeners poll or subscribe to each device and fire events (now playing, progress, state, volume, battery, modes); listeners on those events update a Redis cache, publish to MQTT, store playback history and queue artwork processing.

* **Framework**: Laravel 12 (PHP 8.4), Livewire 3
* **Infrastructure**: Redis (cache, sessions, queue), Mosquitto (MQTT, WebSocket on 9001), Meilisearch
* **Pattern**: `config/devices.php` maps brands and models to driver classes that implement small capability interfaces
* **Clients**: web UIs, embedded devices (ESP32), native apps, automation systems, scripts

Because ReMoment persists enriched playback data, controllers can request **context-aware information** (track details, artwork, history) instead of raw device responses.

Detailed documentation lives in [`docs/`](docs): `api/` for integrators, `architecture/` for server internals, `frontend/` for the UI pages.

---

## Getting Started

```bash
composer setup      # install, env, key, migrate
composer dev        # Laravel, queue, Vite and log viewer
composer test       # run the test suite
php artisan device:discover   # find devices on your network
php artisan library:scan      # import DLNA libraries
```

---

## Native & Restored Device Capabilities

Many networked audio products lose functionality over time as companion apps change or disappear. ReMoment reintroduces and preserves **native-like features** by talking directly to device protocols, such as speaker grouping for **Bang & Olufsen ASE televisions**, reconnecting and managing **WiSA speakers** on Beosound Moment-class devices, Bluetooth and network settings, and FM presets of BeoLink converters. The `ase:map` command crawls a B&O device's REST API to discover what else it offers.

---

## Project Status

ReMoment is under active development and is intentionally opinionated:

* Focused on **local network control**
* Designed to be **headless-first** (API before UI)
* Optimized for **long-lived hardware projects** rather than cloud dependency; a Raspberry Pi 2/3 is the minimum hardware

Expect breaking changes while interfaces are refined and new device families are added.

---

## Naming

**ReMoment** reflects its origins as part of the *Beosound Moment Revival* project, but the scope has expanded into a more general-purpose controller layer.

---

## License & Use

License and contribution guidelines will be defined once the core interfaces stabilize.

## Usage of AI

Some code has been built with the help of AI.
