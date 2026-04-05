# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ReMoment Server is a Laravel 12 application that acts as a universal controller and abstraction layer for networked audio/video devices (Bang & Olufsen ASE, Sonos). It normalizes control interfaces across brands, captures playback history, and publishes events to MQTT for IoT integration.

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
```

## Architecture

### Event-Driven Core

The application is built around an event-listener pattern defined in `app/Providers/AppServiceProvider.php`:

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

### Domain vs. Model Layer

- `app/Domain/` – Non-Eloquent value objects used in events and cache: `NowPlaying`, `Track`, `Artist`, `Album`, `Radio`, `Source`
- `app/Models/` – Eloquent models for persistence: `Device`, `Track`, `Album`, `Artist`, `Metadata`, `Play`

These are distinct classes; domain objects represent live state, Eloquent models represent stored history.

### Device State Caching

`app/Domain/Device/DeviceCache.php` caches device state using keys `device:{id}:state`, `device:{id}:now_playing`, and `device:{id}:last_seen` with a 3600s TTL.

### Artwork Caching & Processing

When a new track starts playing, `DispatchArtworkProcessing` dispatches the `ProcessArtwork` queued job. The job:
1. Downloads the original image URL from the music service
2. Resizes to 512×512 and 320×320 JPEG proxies, stored under `storage/app/public/artwork/{md5(url)}/`
3. Extracts 5 dominant colors via ColorThief
4. Stores proxy URLs + hex colors in Redis (`artwork:{md5}`, 30-day TTL) via `ArtworkCache`
5. Updates any matching `albums.colors` column in the database

`app/Domain/Artwork/ArtworkCache.php` provides static helpers for reading/writing the cache and extracting the image URL from a `NowPlaying` object (handles both Sonos plain-string and B&O `['url' => '...']` image formats).

The API (`DeviceDetailResource`) and MQTT (`PublishNowPlayingToMqtt`) include an `artwork` key with proxy URLs and colors once processed. The device card UI renders a color gradient from the dominant colors. Artwork is absent on the first play of a new URL (job is async), then present for all subsequent plays.

Run `php artisan storage:link` once on new environments to create the `public/storage` symlink.

### Playback History

`StorePlaybackHistory` (queued) persists each play to the `plays` table via the `Play` Eloquent model. `ClosePlaybackHistory` sets `ended_at` when playback stops. Plays can be browsed at `/history`.

### MQTT Publishing

`app/Services/MqttService.php` publishes to topics under `remoment/player/{deviceId}/...`. The Mosquitto broker runs in Docker on port 1883.

Topics:
- `remoment/player/{id}/data` – JSON: `{track, artist, artwork?: {proxy_512, proxy_320, colors}}`
- `remoment/player/{id}/progress` – progress percentage (0–100)

### Local Package

`packages/duncan3dc/sonos` is a local fork of the Sonos library (on branch `laravel12`). Changes here are not published upstream.

## Infrastructure (Docker)

Defined in `compose.yaml` via Laravel Sail:
- **laravel.test** – PHP 8.4 app server (port 80)
- **redis** – cache, sessions, and queue backend
- **mosquitto** – MQTT broker (port 1883)
- **meilisearch** – search (port 7700)
- **Vite dev server** – port 5173

## Frontend

Blade templates + Livewire 3 for real-time UI. Alpine.js for client-side interactivity. Tailwind CSS and Font Awesome loaded via CDN (not compiled). The layout (`resources/views/layouts/app.blade.php`) provides a persistent sidebar navigation.

### Livewire Components

- `Nowplaying` (`app/Livewire/Nowplaying.php`) — full playback card with transport + volume controls, polls every 1s
- `DeviceCard` (`app/Livewire/DeviceCard.php`) — compact standby/unreachable card, polls every 5s; shows a color gradient from `ArtworkCache` when artwork has been processed
- `DeviceHistory` (`app/Livewire/DeviceHistory.php`) — last 10 unique tracks for a device
- `PlayHistory` (`app/Livewire/PlayHistory.php`) — paginated play history with device/source filters

### Web Pages

- `/devices` — responsive device grid dashboard (playing devices get wide card, others get compact)
- `/devices/create` — add device with cascading brand→product→driver form (Alpine.js)
- `/devices/{id}` — device detail: nowplaying + info panel with edit/delete
- `/devices/{id}/edit` — edit device name, IP, brand, product
- `/history` — full play history browser
- `/settings` — overview of users, devices, MQTT config
- `/settings/users` — user management table

### Controllers

- `DeviceController` (`app/Http/Controllers/DeviceController.php`) — full CRUD
- `HistoryController` (`app/Http/Controllers/HistoryController.php`) — play history views
- `SettingsController` (`app/Http/Controllers/SettingsController.php`) — settings + user management

## Code Style

Laravel Pint with the `laravel` preset (`pint.json`). Run `./vendor/bin/pint` before committing.