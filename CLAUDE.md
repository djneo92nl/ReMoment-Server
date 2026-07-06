# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ReMoment Server is a Laravel 12 application that acts as a universal controller and abstraction layer for networked audio/video devices (Bang & Olufsen ASE, Sonos, Spotify). It normalizes control interfaces across brands, captures playback history, publishes events to MQTT for IoT integration, and exposes a REST API for client devices.

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

`capabilities` values: `media_controls` | `volume_control` | `radio_control` | `source_control` | `source_activation` | `multi_room` | `library_playback`

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
      "proxy_512": "/storage/artwork/abc123/512.jpg",
      "proxy_320": "/storage/artwork/abc123/320.jpg",
      "colors": ["#1a2b3c", "#4d5e6f", "#7a8b9c", "#0d1e2f", "#3c4d5e"]
    }
  }
}
```

`now_playing` is `null` when the device is in standby or unreachable. `artwork` is absent on the first play of a new URL (processed asynchronously), present on all subsequent plays. `radio` and `source` keys are absent when not applicable — use whichever is present to identify playback type.

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

---

## MQTT Reference

The Mosquitto broker runs in Docker on port 1883. Each device's MQTT base topic is `remoment/player/{device_id}` and is also returned in the API as `mqtt_topic`.

### Topics

| Topic | Trigger | Payload |
|-------|---------|---------|
| `remoment/player/{id}/data` | New track starts | `{ "track": "Name", "artist": "Name", "artwork": { "proxy_512": "…", "proxy_320": "…", "colors": ["#…"] } }` |
| `remoment/player/{id}/progress` | Every second while playing | Progress in seconds (integer string) |

`artwork` is absent in the MQTT payload if not yet processed. Published by `PublishNowPlayingToMqtt` and `PublishProgressToMqtt` listeners. The broker is publish-only from the server; no subscriptions are consumed.

---

## Architecture

### Event-Driven Core

Defined in `app/Providers/AppServiceProvider.php`:

- `NowPlayingUpdated` → `UpdateDeviceCache`, `StorePlaybackHistory` (queued), `PublishNowPlayingToMqtt`, `DispatchArtworkProcessing`
- `ProgressUpdated` → `UpdateDeviceCache`, `PublishProgressToMqtt`
- `NowPlayingEnded` → `UpdateDeviceCache`, `ClosePlaybackHistory`

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

### Integration Drivers

**Bang & Olufsen ASE** (`app/Integrations/BangOlufsen/Ase/`)
- `MusicPlayerDriver` — all audio capabilities
- `VideoPlayerDriver` — HDMI/video plus library playback
- Communicates via REST to `{ip}:8080/BeoZone/Zone/…`
- Capabilities: all interfaces including source activation, multiroom (JID-based), library playback

**Sonos** (`app/Integrations/Sonos/`)
- Communicates via UPnP SOAP to device IP
- Library: local fork of `duncan3dc/sonos` in `packages/duncan3dc/sonos` (branch `laravel12`)
- Capabilities: media controls, volume, radio, multiroom, library playback

**Spotify** (`app/Integrations/Spotify/`)
- Virtual device — polls Spotify Web API every 3 seconds
- Capabilities: media controls only (cloud-controlled)
- Can route playback to a mapped local device via `spotify_connect_name` device meta key

### Domain vs. Model Layer

- `app/Domain/` – Non-Eloquent value objects used in events and cache: `NowPlaying`, `TrackData`, `ArtistData`, `AlbumData`, `Radio`, `Source`
- `app/Models/` – Eloquent models for persistence: `Device`, `DeviceSource`, `DeviceMeta`, `DlnaServer`, `MultiroomPreset`, `Play`, media models (`Track`, `Album`, `Artist`, `Metadata`)

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

`State` enum values: `playing` | `standby` | `paused` | `unreachable`

### Artwork Caching & Processing

When a new track starts playing, `DispatchArtworkProcessing` dispatches the `ProcessArtwork` queued job. The job:
1. Downloads the original image URL from the music service
2. Resizes to 512×512 and 320×320 JPEG proxies, stored under `storage/app/public/artwork/{md5(url)}/`
3. Extracts 5 dominant colors via ColorThief
4. Stores proxy URLs + hex colors in Redis (`artwork:{md5}`, 30-day TTL) via `ArtworkCache`
5. Updates any matching `albums.colors` column in the database

`app/Domain/Artwork/ArtworkCache.php` provides static helpers for reading/writing the cache. Artwork is absent on first play of a new URL (async), present on all subsequent plays.

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

### Source Management

`DeviceSource` rows represent sources synced from the device. Two UI-controlled fields:
- `hidden` (bool) — exclude from source list display
- `sort_order` (unsigned smallint) — custom display order

Managed via the `DeviceSourceManager` Livewire component on the device detail page. Preferences survive source refreshes because records are keyed by `source_id`.

### Local Package

`packages/duncan3dc/sonos` is a local fork of the Sonos library (on branch `laravel12`). Changes here are not published upstream.

---

## Infrastructure (Docker)

Defined in `compose.yaml` via Laravel Sail:
- **laravel.test** – PHP 8.4 app server (port 80)
- **redis** – cache, sessions, and queue backend
- **mosquitto** – MQTT broker (port 1883)
- **meilisearch** – search (port 7700)
- **Vite dev server** – port 5173

---

## Frontend

Blade templates + Livewire 3 for real-time UI. Alpine.js for client-side interactivity. Tailwind CSS and Font Awesome loaded via CDN (not compiled). The layout (`resources/views/layouts/app.blade.php`) provides a persistent sidebar navigation.

### Livewire Components

- `Nowplaying` (`app/Livewire/Nowplaying.php`) — full playback card with transport + volume controls, polls every 1s
- `DeviceCard` (`app/Livewire/DeviceCard.php`) — compact standby/unreachable card, polls every 1s; shows color gradient from `ArtworkCache`; includes Multiroom button for capable devices
- `DeviceSourceManager` (`app/Livewire/DeviceSourceManager.php`) — source list with hide/show toggle, drag-to-reorder, and activate button
- `MultiroomPresets` (`app/Livewire/MultiroomPresets.php`) — create/activate/delete named multiroom groupings
- `DeviceHistory` (`app/Livewire/DeviceHistory.php`) — last 10 unique tracks for a device
- `PlayHistory` (`app/Livewire/PlayHistory.php`) — paginated play history with device/source filters

### Web Pages

- `/devices` — responsive device grid dashboard (playing devices get wide card, others get compact)
- `/devices/create` — add device with cascading brand→product→driver form (Alpine.js)
- `/devices/{id}` — device detail: nowplaying + source manager + info panel
- `/devices/{id}/edit` — edit device name, IP, brand, product
- `/history` — full play history browser
- `/stats` — listening statistics (top artists, devices, hourly heatmap)
- `/multiroom` — multiroom session management and presets
- `/receiver` — full-screen now-playing display; fetches `GET /api/devices`, lets user pick device (or auto-selects if only one); polls `GET /api/devices/{id}` every 3s and supports play/pause/next/previous via the REST API. Accepts `?device={id}` query parameter to skip the picker.
- `/artists` — artist library browser
- `/albums/{id}` — album detail with track listing
- `/settings` — overview of listeners, MQTT config
- `/settings/users` — user management table
- `/settings/listeners` — start/stop device background listeners
- `/settings/dlna` — discover DLNA servers, trigger library scans
- `/settings/spotify-connect` — map Spotify Connect speaker names to local devices

### Controllers

- `DeviceController` (`app/Http/Controllers/DeviceController.php`) — full CRUD + standby + source activation (web)
- `Api/DeviceController` (`app/Http/Controllers/Api/DeviceController.php`) — REST API
- `HistoryController` — play history views
- `SettingsController` — settings, listeners, DLNA, Spotify Connect
- `StatsController` — statistics views

---

## Code Style

Laravel Pint with the `laravel` preset (`pint.json`). Run `./vendor/bin/pint` before committing.
