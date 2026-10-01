# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ReMoment Server is a Laravel 12 application that acts as a universal controller and abstraction layer for networked audio/video devices (Bang & Olufsen ASE, Sonos, Spotify). It normalizes control interfaces across brands, captures playback history, publishes events to MQTT for IoT integration, and exposes a REST API for client devices.

## Auth Model

There is exactly **one shared admin login** (seeded by `DatabaseSeeder` as `admin@admin.com`) — there is no self-registration route, no multiple accounts, and no per-user profiles or roles. This one login gates only admin setup/config actions: `/settings/*`, the Spotify/Last.fm OAuth connect flows, and client device approval. Every other page — `/devices`, `/receiver`, `/history`, `/library`, `/stats`, playback controls, etc. — is intentionally guest-accessible with no login required, matching the REST API (also unauthenticated). **Never add user registration, multiple accounts, profiles, or role-based access** — this is a deliberate, permanent constraint, not an oversight to "fix."

## Documentation

Detailed documentation lives in `docs/`. CLAUDE.md holds enough context to understand the project; the docs hold enough to implement or integrate without reading the source.

```
docs/
  api/
    client-devices.md       Client device registration flow, all endpoints, firmware guide
    server-info.md          GET /api/info bootstrap endpoint (API/MQTT/artwork addresses for clients)
  architecture/
    client-devices.md       DB schema, model, controller, admin UI internals
    device-drivers.md       Driver contracts, existing drivers, guide for adding a new brand
    device-discovery.md     Discovery commands, listener startup, device_meta keys
    discovery-for-clients.md mDNS advertisement (_remoment._tcp via host Avahi) so clients find the server
    lastfm.md                Scrobbling, now-playing, auth flow, artist enrichment, backfill
    live-updates.md          MQTT-over-WebSocket push to Livewire + /receiver, topics, fallback polling
    plugin-architecture.md  Design doc: extracting drivers into composable packages
    setup-wizard.md          First-time setup wizard: steps, Setting flags, auto-redirect middleware
  frontend/
    receiver.md             /receiver view: JS globals, DOM structure, browser targets
```

When adding a significant feature, add a matching doc in the relevant subfolder (`api/` for new REST endpoints, `architecture/` for server-side systems, `frontend/` for complex UI pages).

## Common Commands

```bash
# Initial setup
composer setup

# Start full dev environment (Laravel, queue, Vite, log viewer)
composer dev

# Run tests
composer test

# Run a single test file or method
php artisan test --filter=TestClassName
php artisan test tests/Path/To/TestFile.php

# Code style
./vendor/bin/pint

# Device discovery (SSDP)
php artisan device:discovery

# Sync B&O source/JID data for all ASE devices
php artisan devices:sync-sources

# Scan a DLNA server's library (triggered via UI or manually)
php artisan dlna:scan {server_id}
```

## REST API Reference

Base URL: `/api` — no authentication required.

### Server Info

**`GET /api/info`** — bootstrap info for client devices: `{ name, version, api_version, base_url, api_base_url, artwork_base_url, mqtt: { host, port, topic_prefix } }`. Addresses are as the client reached the server; `mqtt.host` is `MQTT_PUBLIC_HOST` or the request host (never the Docker-internal `MQTT_HOST`). See `docs/api/server-info.md`.

### Device List & Detail

**`GET /api/devices`** — list all devices.

Response: `{ "data": [ DeviceListResource, … ] }`

**`GET /api/devices/{id}`** — full device detail including current playback.

Response: `{ "data": DeviceDetailResource }`

**DeviceListResource shape:**
```json
{
  "id": 1,
  "device_name": "Living Room",
  "device_brand_name": "Bang & Olufsen",
  "device_product_type": "Beolab 28",
  "device_driver_name": "ASE Music Player",
  "ip_address": "192.168.1.10",
  "state": "playing",
  "last_seen": "2026-07-05T12:00:00",
  "capabilities": ["media_controls", "volume_control", "source_control", "source_activation", "multi_room"],
  "mqtt_topic": "remoment/player/1"
}
```

`state` values: `playing` | `standby` | `paused` | `unreachable`

`capabilities` values: `media_controls` | `volume_control` | `radio_control` | `source_control` | `source_activation` | `multi_room` | `library_playback` | `seek` | `queue`

Always check `capabilities` before calling a feature endpoint — calling an unsupported feature returns `422`.

**DeviceDetailResource** — all fields from DeviceListResource plus `now_playing`:

```json
{
  "now_playing": {
    "track": {
      "id": "spotify:track:abc",
      "name": "Song Title",
      "source": "spotify",
      "duration": 213,
      "artist": { "name": "Artist Name", "images": [], "source": "spotify" },
      "images": ["https://…"],
      "meta": [{ "spotifyId": "spotify:track:abc" }]
    },
    "album": {
      "name": "Album Name",
      "images": ["https://…"],
      "released_at": "2023",
      "artist": { "name": "Artist Name" }
    },
    "radio": { "name": "Station Name", "genre": "Pop", "images": [] },
    "source": { "name": "Spotify", "category": "music", "sourceType": "MUSIC", "connector": "opt1", "jid": "…" },
    "state": "playing",
    "position": 42,
    "platform": "media",
    "type": "music",
    "endTime": "2026-07-05T12:03:33",
    "artwork": {
      "kind": "album",
      "proxy_512": "http://remoment.local/storage/artwork/abc123/512.jpg",
      "proxy_320": "http://remoment.local/storage/artwork/abc123/320.jpg",
      "proxy_120": "http://remoment.local/storage/artwork/abc123/120.jpg",
      "proxy_bg": "http://remoment.local/storage/artwork/abc123/bg_1024x600.jpg",
      "colors": ["#1a2b3c", "#4d5e6f", "#7a8b9c", "#0d1e2f", "#3c4d5e"],
      "safe_colors": ["#5d7fa3", "#4d5e6f", "#7a8b9c", "#4f86c4", "#3c4d5e"]
    }
  }
}
```

`now_playing` is `null` when the device is in standby or unreachable. `artwork.kind` says what the image is: `album` (track/album cover), `radio` (the station's image from the stream or from its `image_url` in `/radio`, else a generated radio logo) or `source` (a generated logo for the source — Spotify, line-in, Bluetooth, TV, AirPlay/Cast, CD, or a music note — when nothing playing has an image). Logos have the same keys and go through the same pipeline as covers, so clients need no special case. A real image's `artwork` is absent on the first play of a new URL (processed asynchronously), present on all subsequent plays; logos are always present. Artwork URLs are prefixed with `APP_URL` (root-relative `/storage/…` when it is empty); clients should take the path from `/storage/` on and prefix `artwork_base_url` from `GET /api/info`. `proxy_512`/`proxy_320`/`proxy_120` are square covers, `proxy_bg` a blurred, darkened 1024×600 background for the 7" client; all are **baseline** JPEGs (ESP32 TJpgDec can't decode progressive). `safe_colors` are `colors` lightened to ≥65% lightness for text/accents on dark backgrounds. An entry cached before a key existed may lack it until regenerated. `radio` and `source` keys are absent when not applicable — use whichever is present to identify playback type.

### Media Controls

All control endpoints require the device to have the relevant capability. They return `503` if the device is unreachable and `422` if the capability is unsupported.

```
POST /api/devices/{id}/play
POST /api/devices/{id}/pause
POST /api/devices/{id}/stop
POST /api/devices/{id}/next
POST /api/devices/{id}/previous
```

Success response: `{ "status": "ok", "action": "play" }`

Error responses:
```json
{ "error": "unreachable", "message": "Device is not reachable." }          // 503
{ "error": "unsupported", "message": "This device does not support …." }   // 422
{ "error": "driver_error", "message": "The device did not respond: …" }    // 502
```

### Volume

```
GET  /api/devices/{id}/volume          → { "volume": 45 }
PUT  /api/devices/{id}/volume          body: { "volume": 45 }   → { "volume": 45 }
```

Volume is an integer 0–100.

### Mute

Part of `volume_control`.

```
GET  /api/devices/{id}/mute            → { "muted": false }
PUT  /api/devices/{id}/mute            body: { "muted": true }  → { "muted": true }
```

### Seek

Requires `seek` (Sonos, Spotify, Mozart).

```
PUT  /api/devices/{id}/seek            body: { "position": 90 }  → { "status": "ok", "position": 90 }
```

`position` is an absolute offset in seconds within the current track.

### Up Next (queue)

Requires `queue` (Sonos, Spotify).

```
GET  /api/devices/{id}/queue?limit=20  → { "up_next": [ QueueItem, … ] }
```

`limit` is 1–100 (default 20). Returns only the tracks after the current one. The list is empty when the device isn't playing from a queue (radio, line-in).

**QueueItem shape:** `{ "name": "…", "artist": "…"|null, "album": "…"|null, "image": "https://…"|null, "duration": 200|null, "uri": "…"|null }`

Validation errors return Laravel's standard `422` `{ "message", "errors" }`. Unknown devices and routes return `404`.

### Sources

```
GET  /api/devices/{id}/sources         → { "sources": [ AvailableSource, … ] }
POST /api/devices/{id}/sources/activate  body: { "source_id": "MUSIC" }   → { "status": "ok", "source_id": "MUSIC" }
```

`GET` fetches live data from the device and refreshes the `device_sources` table; hidden/sort preferences are preserved by `source_id`.

**AvailableSource shape:**
```json
{
  "source_id": "MUSIC",
  "friendly_name": "Music",
  "source_type": "MUSIC",
  "category": "music",
  "in_use": true,
  "borrowed": false,
  "provider_jid": "provider@jid.example",
  "provider_name": "Spotify"
}
```

### Radio

```
POST /api/devices/{id}/radio/{station_id}   → { "status": "ok", "station": "Radio 1" }
```

`station_id` is the `RadioStation` model ID. The device must have `radio_control` capability and a compatible radio platform.

### Multiroom

```
GET    /api/devices/{id}/multiroom                                → joinable + listening devices
POST   /api/devices/{id}/multiroom/join  body: { "host_device_id": 2 }  → { "status": "ok", "joined": "Kitchen" }
DELETE /api/devices/{id}/multiroom/leave                          → { "status": "ok" }
```

**Multiroom GET response:**
```json
{
  "joinable": [
    { "id": 2, "device_name": "Kitchen", "state": "playing" }
  ],
  "listeners": [
    { "id": 3, "device_name": "Bedroom", "state": "playing" }
  ]
}
```

`joinable` — devices currently playing that this device could join.
`listeners` — devices currently joined to this device's session.

For Sonos, `joinable` is always empty (returns `[]`); the UI falls back to showing all Sonos devices.

### DLNA Library Playback

```
POST /api/devices/{id}/library/play   body: { "track_id": 42 }   → { "status": "ok", "track": "Song Title" }
```

`track_id` must exist in the `tracks` table and have a DLNA URL in `metadata` (key `dlna_url`). The device must have `library_playback` capability.

Error: `{ "error": "no_dlna_url", "message": "This track has no DLNA stream URL." }` → `422`

### Client Device API

Four endpoints used by firmware and software clients to self-register, poll for admin approval, and retrieve assigned devices.

```
POST  /api/clients/register                           → registration_token + pairing_code
GET   /api/clients/status/{registration_token}        → pending | approved + api_token + devices
GET   /api/clients/{api_token}/devices                → assigned device list
PUT   /api/clients/{api_token}/heartbeat              → updates IP, firmware, last_seen_at
```

See `docs/api/client-devices.md` for full request/response shapes, the registration flow, firmware implementation notes, and an Arduino sketch outline.

---

## MQTT Reference

The Mosquitto broker runs in Docker on port 1883. Each device's MQTT base topic is `remoment/player/{device_id}` and is also returned in the API as `mqtt_topic`.

### Topics

| Topic | Trigger | Payload |
|-------|---------|---------|
| `remoment/player/{id}/data` | New track starts | `{ "track": "Name", "artist": "Name", "artwork": { "kind": "album", "proxy_512": "…", "proxy_320": "…", "proxy_120": "…", "proxy_bg": "…", "colors": ["#…"], "safe_colors": ["#…"] } }` (same `artwork` object as the REST API; ~600 bytes, so MQTT clients need a buffer above PubSubClient's 256-byte default) |
| `remoment/player/{id}/progress` | Every second while playing | Progress as a percentage of the track, 0–100 (integer string) |
| `remoment/player/{id}/state` | State transition (retained) | `{ "state": "playing" }` — `playing` / `paused` / `standby` / `unreachable` |
| `remoment/player/{id}/volume` | Volume level changes (retained) | `{ "volume": 45 }` |

`artwork` is absent in the MQTT payload if a real image is not yet processed; `kind` is `album` | `radio` | `source` as in the REST API. Published by the `PublishNowPlayingToMqtt`, `PublishProgressToMqtt`, `PublishStateToMqtt` and `PublishVolumeToMqtt` listeners. The server only publishes and never subscribes. Browsers subscribe over the WebSocket listener on port 9001 to drive live UI refreshes; see `docs/architecture/live-updates.md`.

---

## Architecture

### Event-Driven Core

Defined in `app/Providers/AppServiceProvider.php`:

- `NowPlayingUpdated` → `UpdateDeviceCache`, `StorePlaybackHistory` (queued), `PublishNowPlayingToMqtt`, `DispatchArtworkProcessing`
- `ProgressUpdated` → `UpdateDeviceCache`, `PublishProgressToMqtt`
- `NowPlayingEnded` → `UpdateDeviceCache`, `ClosePlaybackHistory`
- `VolumeUpdated` → `UpdateDeviceCache`, `PublishVolumeToMqtt`
- `DeviceStateChanged` → `PublishStateToMqtt`. Fired by `DeviceCache::updateState()` only when the cached state actually changes.

Listeners are registered only here: event auto-discovery is disabled in `bootstrap/app.php`, because it registered every listener twice.

Device listeners (`app/Integrations/*/Services/DeviceListener.php`) poll devices and fire these events. The queue must be running for `StorePlaybackHistory` and `ProcessArtwork` to process.

### Integration Driver Pattern

`config/devices.php` maps device brands/models to driver classes. The `Device` Eloquent model (`app/Models/Device.php`) dynamically instantiates the correct driver via the service container:

```php
app()->make($this->device_driver, ['device' => $this]);
```

All drivers implement interfaces from `app/Integrations/Contracts/`:
- `MusicPlayerDriverInterface` – current playback state
- `MediaControlsInterface` – play/pause/next/previous/stop
- `VolumeControlInterface` – volume and mute
- `SourcesInterface` / `SourceActivationInterface` – list and activate sources (ASE only)
- `MultiRoomInterface` – join/leave multiroom sessions (ASE + Sonos)
- `LibraryPlaybackInterface` – play a local DLNA track (ASE + Sonos)
- `SeekInterface` – jump within the current track (Sonos, Spotify, Mozart)
- `QueueInterface` – list up-next tracks (Sonos, Spotify)

`App\Domain\Device\Capabilities::forDriver()` maps these contracts to the API capability strings from the driver class name, without instantiating the driver.

### Integration Drivers

**Bang & Olufsen ASE** (`app/Integrations/BangOlufsen/Ase/`)
- `MusicPlayerDriver` — all audio capabilities
- `VideoPlayerDriver` — HDMI/video plus library playback
- Communicates via REST to `{ip}:8080/BeoZone/Zone/…`
- Capabilities: all interfaces including source activation, multiroom (JID-based), library playback

**Sonos** (`app/Integrations/Sonos/`)
- Communicates via UPnP SOAP to device IP
- Library: `duncan3dc/sonos` v3
- Capabilities: media controls, volume, radio, multiroom, library playback, seek, queue

**Spotify** (`app/Integrations/Spotify/`)
- Virtual device — polls Spotify Web API every 3 seconds
- Capabilities: media controls, seek, queue (cloud-controlled)
- Can route playback to a mapped local device via `spotify_connect_name` device meta key

### Domain vs. Model Layer

- `app/Domain/` – Non-Eloquent value objects used in events and cache: `NowPlaying`, `TrackData`, `ArtistData`, `AlbumData`, `Radio`, `Source`
- `app/Models/` – Eloquent models for persistence: `Device`, `DeviceSource`, `DeviceMeta`, `DlnaServer`, `MultiroomPreset`, `Play`, `Client`, media models (`Track`, `Album`, `Artist`, `Metadata`)

Domain objects represent live state; Eloquent models represent stored history.

### Device State Caching

`app/Domain/Device/DeviceCache.php` manages Redis keys:

| Key | Value | TTL |
|-----|-------|-----|
| `device:{id}:state` | `State` enum string | 3600s |
| `device:{id}:now_playing` | Serialized `NowPlaying` object | 3600s |
| `device:{id}:last_seen` | Timestamp | 3600s |
| `spotify_routed_to` | device ID integer | 30s |
| `listener_running_{id}` | boolean flag | 10s |
| `mqtt_published_volume_{id}` | last volume published to MQTT (dedupe) | 3600s |
| `health:scheduler` / `health:queue` | ISO timestamp heartbeats for `/settings/health` | 86400s |

`State` enum values: `playing` | `standby` | `paused` | `unreachable`

### Artwork Caching & Processing

When a new track starts playing, `DispatchArtworkProcessing` dispatches the `ProcessArtwork` queued job. The job:
1. Downloads the original image URL from the music service
2. Resizes to square JPEG proxies (`SQUARE_SIZES`: 512, 320, 120 → `{size}.jpg`) and renders full-screen client backgrounds (`BACKGROUNDS`: `proxy_bg` → `bg_1024x600.jpg`; the cover scaled to fill, shrunk to 1/32 and scaled back up with blur passes, then overlaid with 60% black), stored under `storage/app/public/artwork/{md5(url)}/`. Add a background size by adding an entry to `ProcessArtwork::BACKGROUNDS` (and its key to `ArtworkCache::REQUIRED_KEYS`)
3. Extracts 5 dominant colors via ColorThief
4. Stores proxy URLs + hex colors in Redis (`artwork:{md5}`, 30-day TTL) via `ArtworkCache`
5. Updates any matching `albums.colors` column in the database

All JPEGs are encoded baseline (`JpegEncoder(progressive: false)`; Intervention's GD encoder calls `imageinterlace(false)`), regardless of the source image, because the ESP32's TJpgDec decoder can't read progressive JPEGs.

`app/Domain/Artwork/ArtworkCache.php` provides static helpers for reading/writing the cache. `ArtworkCache::has()` is true only for **complete** entries (all `REQUIRED_KEYS`): an entry cached before a size was added is still served by `get()`, but is regenerated on next play, and `php artisan library:backfill-artwork` (scheduled daily) re-queues outdated entries for album covers as well as covers without colors. Artwork is absent on first play of a new URL (async), present on all subsequent plays.

**Logos for playback without an image** (`app/Domain/Artwork/`): `NowPlayingArtwork` picks the image for a `NowPlaying` (cover → radio station `image_url` from `/radio` → generated logo) and adds `kind`; it is used by both `PublishNowPlayingToMqtt` and `DeviceDetailResource`. `SourceLogo` maps the source's `sourceType`/`name`/`connector`/`category` to a logo key (`spotify`, `radio`, `line_in`, `bluetooth`, `tv`, `cast`, `cd`, default `music`) and addresses it by a pseudo URL `remoment:logo/v{VERSION}/{key}`, so its hash is the same on every server. `LogoRenderer` draws the glyph (off-white line icon on a #181818 square, GD, 4× supersampled, deterministic); `ProcessArtwork` renders it instead of downloading. Missing logo entries are rendered synchronously (~0.2s, a handful of logos). Bump `SourceLogo::VERSION` when a glyph changes so clients' hash-keyed caches pick it up.

Run `php artisan storage:link` once on new environments to create the `public/storage` symlink.

### Playback History

`StorePlaybackHistory` (queued) persists each play to the `plays` table via the `Play` Eloquent model. `ClosePlaybackHistory` sets `ended_at` when playback stops. Plays can be browsed at `/history`.

### DLNA Library

DLNA servers are discovered on the network and their tracks imported into the shared media library.

**Models:**
- `DlnaServer` — `friendly_name`, `ip`, `port`, `control_url`, `last_scanned_at`
- Tracks are stored as `Track` Eloquent models with `source = 'dlna'` and an `external_id` of `{server_id}:{dlna_object_id}`
- The stream URL is stored as a `Metadata` row: `key = 'dlna_url'`, `source = 'dlna:{server_id}'`, `type = 'url'`

**Scanner:** `DlnaLibraryScanner` recursively browses the DLNA content tree via `DlnaContentDirectoryClient` (SOAP/UPnP), creating `Artist`, `Album`, `Track`, and `Metadata` records. Triggered via the Settings UI or `php artisan dlna:scan {server_id}`.

**Playback:** `LibraryPlaybackInterface::playLibraryTrack(Track $track)` fetches the DLNA URL from metadata and streams it to the device. On Sonos this uses a `SonosTrack` wrapper; on ASE it calls `playDlnaTrack()`.

### Spotify Connect → Local Device Mapping

The Spotify virtual device listener polls `GET /v1/me/player` every 3 seconds. When playback is active:

1. Checks the playing Spotify Connect device name against `device_meta` rows with `key = 'spotify_connect_name'`
2. If a match exists, routes the `NowPlayingUpdated` event to the matched local device ID
3. The Spotify virtual device is kept in `Standby` state; the mapped local device shows the playback
4. If the Spotify Connect speaker changes mid-playback, the previous effective device receives `NowPlayingEnded` first

Mappings are managed in the Settings UI at `/settings/spotify-connect`. Stored as:
```
device_meta: key = 'spotify_connect_name', value = '<Spotify device name>'
```

### Multiroom / Device Joining

`MultiRoomInterface` is implemented by ASE and Sonos drivers.

**ASE implementation** (`app/Integrations/BangOlufsen/Ase/Connectors/MultiRoomControls.php`):
- `getMultiRoomId()` – fetches the B&O JID (`activeSources.primaryJid`) from the device; cached in Redis and stored in `device_meta` as key `ase_jid`
- `getJoinablePeerIds()` – returns JIDs from `primaryExperience.listenerList._capabilities.value["listener.jid"]` (literal dot in key — use direct array access, not `data_get`)
- `joinSession(Device $host)` – `POST {ip}:8080/BeoZone/Zone/ActiveSources/primaryExperience` with host JID
- `leaveSession()` – `DELETE {ip}:8080/BeoZone/Zone/ActiveSources/primaryExperience`

**Sonos implementation** (`app/Integrations/Sonos/Connectors/MultiRoomControls.php`):
- `joinSession(Device $host)` – constructs per-IP `Network` for both host and self, calls `SetAVTransportURI` SOAP action at the Speaker level (avoids coordinator requirement)
- `leaveSession()` – calls `BecomeCoordinatorOfStandaloneGroup` SOAP action on the speaker
- `getJoinablePeerIds()` returns `[]`; UI falls back to showing all Sonos devices

**JID-to-Device lookup:** query `device_meta` where `key IN ('ase_jid', 'sonos_uuid')` and `value IN ($peerIds)`.

Populate JIDs for all B&O devices with: `php artisan devices:sync-sources`

### Multiroom Presets

`MultiroomPreset` model stores named groupings of device IDs (`device_ids` JSON column). Activating a preset calls `joinSession($host)` on all member devices, with the first device as host. Managed at `/multiroom` via the `MultiroomPresets` Livewire component.

### Client Device Management

Physical and software controllers (ESP8266, ESP32, Raspberry Pi, Squeezebox Touch, etc.) self-register and are approved by an admin. The server tracks firmware metadata and controls which media players each client can see.

Two client types: `single` (one assigned device) and `multi` (explicit list, or all devices when none assigned).

Key files:
- Model: `app/Models/Client.php` + `client_device` pivot
- API controller: `app/Http/Controllers/Api/ClientController.php`
- Admin UI: `app/Livewire/ClientManager.php`
- Settings page: `resources/views/settings/clients.blade.php` at `/settings/clients`

See `docs/architecture/client-devices.md` for the full DB schema, device resolution logic, and component internals.
See `docs/api/client-devices.md` for the firmware integration guide and full endpoint reference.

### Source Management

`DeviceSource` rows represent sources synced from the device. Two UI-controlled fields:
- `hidden` (bool) — exclude from source list display
- `sort_order` (unsigned smallint) — custom display order

Managed via the `DeviceSourceManager` Livewire component on the device detail page. Preferences survive source refreshes because records are keyed by `source_id`.

### Local Packages

Mozart-platform support ships as two git-submodule Composer packages under `packages/`, wired via `path` repositories in the root `composer.json` (see `docs/architecture/plugin-architecture.md`):
- `packages/djneo92nl/beo-mozart-php` — pure PHP Mozart REST + WebSocket client, no Laravel dependency, tests standalone via its own `composer test`. Submodule: `github.com/djneo92nl/beo-mozart-php`.
- `packages/remoment/mozart-driver` — Laravel driver package implementing the Mozart `MusicPlayerDriver` on top of it. Not yet split into its own repo/submodule.

See `docs/architecture/device-drivers.md` for the Mozart driver's contracts and capabilities.

---

## Infrastructure (Docker)

Defined in `compose.yaml` via Laravel Sail:
- **laravel.test** – PHP 8.4 app server (port 80)
- **redis** – cache, sessions, and queue backend
- **mosquitto** – MQTT broker (port 1883; WebSockets on 9001 for browser live updates)
- **meilisearch** – search (port 7700)
- **Vite dev server** – port 5173

Client devices find the server via mDNS (`_remoment._tcp`, TXT `path=/api`, `api_version`), advertised by Avahi on the Docker **host** — set up by `ansible/setup.yml`, not inside a container. See `docs/architecture/discovery-for-clients.md`.

---

## Frontend

Blade templates + Livewire 3 for real-time UI. Alpine.js for client-side interactivity. Rendering stays in Livewire: `public/js/remoment-live.js` only listens to MQTT over WebSockets and triggers `$wire.$refresh()`, falling back to 1s polling when the broker is unreachable (see `docs/architecture/live-updates.md`). Tailwind CSS and Font Awesome loaded via CDN (not compiled). The layout (`resources/views/layouts/app.blade.php`) provides a persistent sidebar navigation.

### Livewire Components

- `Nowplaying` (`app/Livewire/Nowplaying.php`) — full playback card with transport, volume, mute, and click-to-seek; refreshes on MQTT push (`liveDevice`)
- `DeviceCard` (`app/Livewire/DeviceCard.php`) — device card with transport, volume, mute, and click-to-seek; refreshes on MQTT push; shows color gradient from `ArtworkCache`; includes Multiroom button for capable devices
- `DeviceQueue` (`app/Livewire/DeviceQueue.php`) — "Up Next" list on the device page for `queue`-capable devices, loaded lazily via `wire:init`
- `SystemHealth` (`app/Livewire/SystemHealth.php`) — `/settings/health`: MQTT broker reachability, scheduler/queue-worker heartbeats, pending/failed jobs, per-device listener state and last seen
- `DeviceSourceManager` (`app/Livewire/DeviceSourceManager.php`) — source list with hide/show toggle, drag-to-reorder, and activate button
- `MultiroomPresets` (`app/Livewire/MultiroomPresets.php`) — create/activate/delete named multiroom groupings
- `DeviceHistory` (`app/Livewire/DeviceHistory.php`) — last 10 unique tracks for a device
- `PlayHistory` (`app/Livewire/PlayHistory.php`) — paginated play history with device/source filters
- `ClientManager` (`app/Livewire/ClientManager.php`) — approve/reject pending registrations, edit client name/type/device assignment, regenerate tokens
- `SetupWizard` (`app/Livewire/SetupWizard.php`) — first-time setup checklist: add devices, client devices, library source (see `docs/architecture/setup-wizard.md`)

### Web Pages

- `/setup` — first-time setup wizard (see `docs/architecture/setup-wizard.md`); auto-redirected here for a logged-in admin while zero devices exist
- `/devices` — responsive device grid dashboard (playing devices get wide card, others get compact)
- `/devices/create` — add device with cascading brand→product→driver form (Alpine.js)
- `/devices/{id}` — device detail: nowplaying + up next queue + source manager + info panel
- `/devices/{id}/edit` — edit device name, IP, brand, product
- `/history` — full play history browser
- `/stats` — listening statistics (top artists, devices, hourly heatmap)
- `/multiroom` — multiroom session management and presets
- `/receiver` — full-screen now-playing display; fetches `GET /api/devices`, lets user pick device (or auto-selects if only one); re-fetches `GET /api/devices/{id}` on MQTT push (polling every 3s without a broker) and supports play/pause/next/previous, seek and mute via the REST API. Accepts `?device={id}` query parameter to skip the picker.
- `/artists` — artist library browser
- `/albums/{id}` — album detail with track listing
- `/settings` — overview of listeners, MQTT config
- `/settings/users` — user management table
- `/settings/listeners` — start/stop device background listeners
- `/settings/health` — scheduler, queue worker, MQTT broker and per-device listener health
- `/settings/dlna` — discover DLNA servers, trigger library scans
- `/settings/spotify-connect` — map Spotify Connect speaker names to local devices
- `/settings/clients` — manage client device registrations; approve/reject pending, assign devices, view tokens

### Controllers

- `DeviceController` (`app/Http/Controllers/DeviceController.php`) — full CRUD + standby + source activation (web)
- `Api/DeviceController` (`app/Http/Controllers/Api/DeviceController.php`) — REST API
- `HistoryController` — play history views
- `SettingsController` — settings, listeners, DLNA, Spotify Connect, client devices
- `Api/ClientController` (`app/Http/Controllers/Api/ClientController.php`) — client registration, status polling, device list, heartbeat
- `StatsController` — statistics views

---

## Code Style

Laravel Pint with the `laravel` preset (`pint.json`). Run `./vendor/bin/pint` before committing.
