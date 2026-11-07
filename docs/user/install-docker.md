# Install with Docker Compose

For people comfortable with Docker and a terminal. This page uses `docker-compose.prod.yml`, the production stack: it builds the images from the repository and runs the app with `network_mode: host`, so it needs a **Linux host** (a Pi, a mini PC, a VM or an LXC with nesting). Docker Desktop on macOS or Windows cannot pass SSDP/mDNS multicast to a container, so device discovery does not work there (add devices by IP instead, see [troubleshooting.md](troubleshooting.md)). `compose.yaml` is the developer stack (Laravel Sail), not an install method.

> **Not available yet:** published container images. Every command below builds the images on your machine from the source (`build:` entries in `docker-compose.prod.yml`); the first build takes a while, longer on a Pi. A Raspberry Pi image is also not built ([install-raspberry-pi.md](install-raspberry-pi.md)). An `app:upgrade` command does not exist either ([upgrading-and-backups.md](upgrading-and-backups.md)).

## 1. Get the code

The repository has one git submodule (`packages/djneo92nl/beo-mozart-php`), so clone with submodules. The stack bind-mounts the checkout into the container, so keep it somewhere permanent.

```bash
git clone --recurse-submodules https://github.com/djneo92nl/ReMoment-Server.git /opt/remoment
cd /opt/remoment
```

(The URL is the `app_repo` of `ansible/group_vars/all.yml`; change it to your fork if you have one.)

## 2. Write `.env`

`.env.example` is Laravel's generic file, not a production one. Write your own; this is the minimum, modelled on `ansible/templates/env.j2` (which has the full list):

```ini
APP_NAME=ReMoment
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=http://192.168.1.50          # how you and the speakers reach this host

PROFILE=standard                     # control | standard | full

SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1                 # the app runs on the host network

MQTT_HOST=127.0.0.1
MQTT_PORT=1883

# Standard and Full:
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=remoment
DB_USERNAME=remoment
DB_PASSWORD=choose-a-password
COMPOSE_PROFILES=db

# Control instead (no MariaDB, leave COMPOSE_PROFILES empty):
# DB_CONNECTION=sqlite
# DB_DATABASE=/data/sqlite/database.sqlite

# Optional: address speakers use to fetch your own files (empty = APP_URL)
# STREAM_BASE_URL=http://192.168.1.50
WWWUSER=1000                         # `id -u` of the user owning the checkout
WWWGROUP=1000
```

Notes:

- `PROFILE` is read at container start, so changing it later needs a restart. The default without it is `standard`.
- `COMPOSE_PROFILES` selects containers: `db` (MariaDB, Standard and Full), and for Full the optional `analysis`, `structure`, `stems` and `search` (Meilisearch, unused by the app). Without `db` there is no MariaDB, which is right for Control only. Stream sidecars have their own profiles: `spotify-stream`, `beolink-stream`, `radio-relay`; see the architecture docs `spotify-stream.md`, `beolink-stream.md`, `radio-relay.md`.
- `APP_PORT` (default 80) is the port the web server listens on. Mosquitto always uses 1883 (MQTT) and 9001 (MQTT over WebSocket).
- Own music: `MUSIC_DIR` (default `/srv/music`) and `LIBRARY_DIR` (default `/srv/library`) are mounted into the app at the same paths; create them first. Add both as storage locations later at `/settings/storage`.
- API keys (Spotify, Last.fm, Discogs, ...) are better entered in the web UI at `/settings/services`; `.env` works as a fallback.

## 3. Start

```bash
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d
```

Then install dependencies and prepare the app inside the container (the checkout is bind-mounted, so `vendor/` and `public/build/` are created on the host). These are the steps `ansible/deploy.yml` runs:

```bash
alias dc='docker compose -f docker-compose.prod.yml exec -T -u sail app'
dc composer install --no-dev --optimize-autoloader
dc npm ci
dc npm run build
dc php artisan key:generate --force      # only if APP_KEY is empty; writes it into .env
dc php artisan migrate --force
dc php artisan db:seed --force           # creates the admin login (see first-run.md)
dc php artisan storage:link --force
dc php artisan optimize
docker compose -f docker-compose.prod.yml restart app
```

The commands are taken from `ansible/deploy.yml`, but this exact manual sequence (in particular `key:generate` after the first start) has not been run end to end on a fresh host: *unverified*. Ansible puts `APP_KEY` into `.env` before the first start. If you generate it after the stack is up, the restart on the last line matters. **Back up `APP_KEY`**: stored service secrets are encrypted with it ([upgrading-and-backups.md](upgrading-and-backups.md)).

## 4. Open it

Browse to `http://<host>:<APP_PORT>/` and sign in; with zero devices the admin lands on the setup wizard at `/setup`. Continue with [first-run.md](first-run.md).

Check what runs: `docker compose -f docker-compose.prod.yml ps`, logs with `... logs -f app`, and `/settings/health` in the browser.

## Firewall

Open `APP_PORT`, 1883 and 9001 to your LAN (9001 is what browsers use for live updates). Mosquitto allows anonymous connections (`docker/mosquitto/config/mosquitto.conf`): keep these ports off the internet.
