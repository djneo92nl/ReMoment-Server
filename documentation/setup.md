# Setup & Installation

## Requirements

- PHP 8.4
- Composer
- Node.js + npm
- Docker (for Redis, MQTT broker, Meilisearch)

## Quick Start

```bash
# Clone the repo and install everything
composer setup
```

This runs in sequence: installs Composer dependencies, copies `.env.example` → `.env`, generates an app key, runs database migrations, installs npm packages, and builds frontend assets.

## Start the Dev Environment

```bash
composer dev
```

Runs four concurrent processes:

| Process | Description |
|---------|-------------|
| `php artisan serve` | HTTP server on port 8000 |
| `php artisan queue:listen` | Queue worker (required for playback history) |
| `php artisan pail` | Real-time log viewer |
| `npm run dev` | Vite dev server on port 5173 |

## Docker Services

Start infrastructure services (Redis, MQTT, Meilisearch) with Laravel Sail:

```bash
./vendor/bin/sail up -d
```

| Service | Port | Purpose |
|---------|------|---------|
| Redis | 6379 | Cache, sessions, queue backend |
| Mosquitto | 1883 | MQTT broker |
| Mosquitto WS | 9001 | MQTT over WebSocket (optional) |
| Meilisearch | 7700 | Full-text search |

## Environment Variables

Key variables in `.env`:

```dotenv
# Application
APP_URL=http://localhost

# Database (SQLite by default)
DB_CONNECTION=sqlite

# Cache & Queue (use Redis in production)
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

# MQTT
MQTT_HOST=localhost
MQTT_PORT=1883
MQTT_CLIENT_ID=remoment-server
```

## Running Tests

```bash
composer test
```

Clears the config cache before running PHPUnit. Tests use an in-memory SQLite database and mock drivers where needed.

To run a single test file or method:

```bash
php artisan test --filter=TestClassName
php artisan test tests/Path/To/TestFile.php
```

## Code Style

The project uses Laravel Pint with the `laravel` preset. Run before committing:

```bash
./vendor/bin/pint
```

## Starting Device Listeners

After setup, start the device state listeners to receive real-time updates:

```bash
# Start listeners for all registered ASE (Bang & Olufsen) devices
php artisan app:get-current-playing-media

# Discover new devices on the network
php artisan device:discovery
```

See [Device Discovery](device-discovery.md) for more detail.
