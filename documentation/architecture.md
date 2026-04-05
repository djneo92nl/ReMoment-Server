# Architecture

ReMomentServer is a Laravel 12 application that acts as a universal abstraction layer over networked audio devices. It normalises control interfaces across brands, maintains real-time playback state in cache, stores playback history in a database, and publishes events to MQTT for IoT integration.

---

## High-Level Overview

```
Network Devices (Bang & Olufsen, Sonos)
         │
         │  HTTP streaming / polling
         ▼
   DeviceListener (per device, long-running)
         │
         │  fires Laravel Events
         ▼
┌─────────────────────────────────┐
│        Event Dispatcher          │
└────────────┬────────────────────┘
             │
   ┌─────────┼──────────────────┐
   ▼         ▼                  ▼
UpdateDeviceCache  PublishToMqtt  StorePlaybackHistory (queued)
   (Redis)            (MQTT)           (MySQL/SQLite)
```

---

## Event-Driven Core

All state changes flow through Laravel events. Bindings are defined in `app/Providers/AppServiceProvider.php`.

| Event | Sync Listeners | Queued Listeners |
|-------|----------------|------------------|
| `NowPlayingUpdated` | `UpdateDeviceCache`, `PublishNowPlayingToMqtt` | `StorePlaybackHistory` |
| `ProgressUpdated` | `UpdateDeviceCache`, `PublishProgressToMqtt` | — |
| `NowPlayingEnded` | `UpdateDeviceCache` | — |
| `VolumeUpdated` | — | — |

Sync listeners run immediately in the request cycle. `StorePlaybackHistory` is queued via Redis so database writes never block the listener process.

---

## Integration Driver Pattern

`config/devices.php` maps brand + product combinations to driver classes:

```php
'Bang & Olufsen' => [
    'BeoSound Essence' => [
        'driver_name' => 'ASE',
        'driver'      => \App\Integrations\BangOlufsen\Ase\MusicPlayerDriver::class,
    ],
],
```

The `Device` Eloquent model resolves the driver at runtime via the Laravel service container:

```php
app()->make($this->device_driver, ['device' => $this]);
```

All drivers implement one or more contracts from `app/Integrations/Contracts/`:

| Interface | Methods |
|-----------|---------|
| `MusicPlayerDriverInterface` | `getCurrentPlayingAttribute()` |
| `MediaControlsInterface` | `play()`, `pause()`, `stop()`, `next()`, `previous()` |
| `VolumeControlInterface` | `setVolume()`, `getVolume()`, `mute()`, `unmute()`, `incrementVolume()`, `decrementVolume()` |

The API checks `instanceof` on these interfaces to determine a device's capabilities at runtime — no configuration needed.

---

## Device State Caching

`app/Domain/Device/DeviceCache.php` caches live device state in Redis with a 1-hour TTL.

| Cache Key | Type | TTL |
|-----------|------|-----|
| `device:{id}:state` | `State` enum | 3600s |
| `device:{id}:now_playing` | `NowPlaying` object | 3600s |
| `device:{id}:last_seen` | timestamp | 3600s |

Volume is cached separately in `app/Domain/Device/Cache/Volume.php` with a 6-minute TTL.

The `State` enum (`app/Domain/Device/State.php`) has four values: `playing`, `paused`, `standby`, `unreachable`.

---

## Domain vs. Model Layer

There are two parallel representations of media data:

| Layer | Location | Purpose |
|-------|----------|---------|
| Domain objects | `app/Domain/Media/` | Live state — passed through events and stored in cache |
| Eloquent models | `app/Models/Media/` | Persistent history — stored in the database |

**Domain objects** (`NowPlaying`, `TrackData`, `ArtistData`, `AlbumData`, `Radio`, `Source`) are plain PHP classes with nullable properties and a `toArray()` method. They are never persisted directly.

**Eloquent models** (`Track`, `Album`, `Artist`, `Metadata`) represent the playback history database. They are written only by the `StorePlaybackHistory` queued listener.

---

## Playback History Storage

`StorePlaybackHistory` runs on every `NowPlayingUpdated` event (via the queue). It:

1. Computes a SHA-256 deduplication hash from the track details
2. Checks a 24-hour cache entry for that hash — skips if already stored
3. Upserts `Artist` → `Album` → `Track` (by name + source, to avoid duplicates)
4. Stores arbitrary key-value `Metadata` entries against the track (e.g. Spotify ID, duration, images)

`Metadata` is polymorphic (`metadatable_type` / `metadatable_id`) so it can attach to tracks, albums, or artists.

---

## Database Schema

```
devices
├── id, ip_address, device_name
├── device_brand_name, device_product_type
├── device_driver (class path), device_driver_name
└── last_seen

device_meta
├── device_id → devices.id
└── key, value  (e.g. upnp_uuid, jid)

artists
└── name, source  [UNIQUE: name+source]

albums
├── artist_id → artists.id
└── name, source, images (JSON), released_at  [UNIQUE: artist_id+name+source]

tracks
├── album_id → albums.id, artist_id → artists.id
├── external_id, name, duration, source
└── images (JSON)  [UNIQUE: external_id+source]

metadata  (polymorphic)
├── metadatable_type, metadatable_id
├── key, value, type (string|int|float|bool|json|url)
├── source (spotify, musicbrainz, …)
└── parent_id → metadata.id  (nested metadata)
```

---

## MQTT Publishing

`app/Services/MqttService.php` publishes JSON payloads to the Mosquitto broker (Docker, port 1883). A new connection is opened per publish call.

Published topics:

| Topic | Trigger | Payload |
|-------|---------|---------|
| `remoment/player/{id}/data` | `NowPlayingUpdated` | `{"track": "…", "artist": "…"}` |
| `remoment/player/{id}/progress` | `ProgressUpdated` | `"42"` (percentage string) |

See [MQTT Integration](mqtt.md) for full topic reference.

---

## Frontend

The UI is built with Blade templates, Livewire 3, Alpine.js, and Tailwind CSS 3. Tailwind and Font Awesome are loaded via CDN. The main layout (`resources/views/layouts/app.blade.php`) provides a persistent sidebar with navigation and flash message display.

### Livewire Components

| Component | File | Poll interval | Purpose |
|-----------|------|---------------|---------|
| `Nowplaying` | `app/Livewire/Nowplaying.php` | 1s | Full playback card with transport controls and volume slider |
| `DeviceCard` | `app/Livewire/DeviceCard.php` | 5s | Compact card for standby/unreachable devices |

### Web Routes

| Route | Controller | Purpose |
|-------|-----------|---------|
| `GET /devices` | `DeviceController@index` | Device grid dashboard |
| `GET /devices/create` | `DeviceController@create` | Add device form |
| `POST /devices` | `DeviceController@store` | Save new device |
| `GET /devices/{id}` | `DeviceController@show` | Device detail + controls |
| `GET /devices/{id}/edit` | `DeviceController@edit` | Edit device form |
| `PATCH /devices/{id}` | `DeviceController@update` | Save device changes |
| `DELETE /devices/{id}` | `DeviceController@destroy` | Remove device |
| `GET /settings` | `SettingsController@index` | Settings overview |
| `GET /settings/users` | `SettingsController@users` | User management |
| `DELETE /settings/users/{id}` | `SettingsController@destroyUser` | Remove user |

### Device Dashboard Layout

The devices index (`/devices`) renders a responsive CSS grid (`md:grid-cols-2 lg:grid-cols-3`). Devices are sorted by state before rendering:

1. Playing/Paused → `Nowplaying` component wrapped in `md:col-span-2` (large card)
2. Standby/Unreachable → `DeviceCard` component (compact card)

### Device Forms

The create/edit forms (`resources/views/devices/partials/form.blade.php`) use Alpine.js to cascade brand → product model → driver class selection from `config/devices.php`. The `uuid` field is auto-generated on creation (required by the `devices` table schema).

The JSON API (documented in [api.md](api.md)) provides the same control surface for external clients.
