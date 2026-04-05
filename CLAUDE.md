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

- `NowPlayingUpdated` → `UpdateDeviceCache`, `StorePlaybackHistory` (queued), `PublishNowPlayingToMqtt`
- `ProgressUpdated` → `UpdateDeviceCache`, `PublishProgressToMqtt`
- `NowPlayingEnded` → `UpdateDeviceCache`

Device listeners (`app/Integrations/*/Services/DeviceListener.php`) poll devices and fire these events. The queue must be running for `StorePlaybackHistory` to process.

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
- `app/Models/` – Eloquent models for persistence: `Device`, `Track`, `Album`, `Artist`, `Metadata`

These are distinct classes; domain objects represent live state, Eloquent models represent stored history.

### Device State Caching

`app/Domain/Device/DeviceCache.php` caches device state using keys `device:{id}:state`, `device:{id}:now_playing`, and `device:{id}:last_seen` with a 3600s TTL.

### MQTT Publishing

`app/Services/MqttService.php` publishes to topics under `remoment/player/{deviceId}/...`. The Mosquitto broker runs in Docker on port 1883.

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

Blade templates + Livewire 3 for real-time UI. Alpine.js for client-side interactivity. Tailwind CSS 3 for styling. The `Nowplaying` Livewire component (`app/Livewire/Nowplaying.php`) handles volume and transport controls.

## Code Style

Laravel Pint with the `laravel` preset (`pint.json`). Run `./vendor/bin/pint` before committing.