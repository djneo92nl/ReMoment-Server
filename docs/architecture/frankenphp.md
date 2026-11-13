# FrankenPHP (web server, dev and production)

Production and development serve the web UI and REST API with **FrankenPHP** (Caddy + embedded PHP) in **classic mode**: every request boots the app, like php-fpm, so there is no state shared between requests. It replaced `php artisan serve`, which handled one request at a time.

## Files

| File | Role |
|---|---|
| `docker/frankenphp/Dockerfile` | Production app image, `FROM dunglas/frankenphp:1-php8.4` (Debian, thread-safe PHP). Adds the PHP extensions, ffmpeg, fpcalc, librsvg, supervisor, Node and Composer (the Ansible deploy runs `composer` and `npm` inside the container). Build context is `./docker` |
| `docker/frankenphp/Caddyfile` | `admin off`, `auto_https off`, listens on `:{$APP_PORT:80}`, root `public/`, 300 MB request body limit, `php_server` |
| `docker/frankenphp/php.ini` | Same limits as the Sail image (300M uploads, `variables_order=EGPCS`) |
| `docker/8.4/supervisord.prod.conf` | `[program:php]` runs `frankenphp run`; queue workers, scheduler and `device:listen` are unchanged (CLI `php`, symlinked to `/usr/bin/php`) |
| `docker-compose.prod.yml` | Builds the image; `FRANKENPHP_THREADS` (default 4) sets the PHP threads that serve requests concurrently |

Development (`compose.yaml`) builds the same image with `DEV=true` (adds xdebug and pcov, both off until asked) and image name `remoment/app-dev`; the Sail image (`docker/8.4/Dockerfile`) is no longer used by compose. It differs from production in three files: `docker/frankenphp/Caddyfile.dev` (listens on 80 and `APP_PORT_ALT`, replacing the old `php-alt` `artisan serve`, because Docker Desktop drops port bindings when two host ports share a container port), `docker/8.4/supervisord.conf`, and bind mounts of the Caddyfiles and of `start-container` (the entrypoint can then never be older than the supervisor config; it runs as `bash start-container` because the file is not executable on the host). `composer dev` on the host (Herd) still uses `artisan serve`.

## Modes: classic and worker

`FRANKENPHP_MODE` (`.env`, compose) picks the Caddyfile; `start-container` turns it into `CADDYFILE`, which the supervisor `php` program reads (`%(ENV_CADDYFILE)s`):

| Mode | Caddyfile (prod / dev) | What it is |
|---|---|---|
| `classic` (default) | `Caddyfile` / `Caddyfile.dev` | every request boots the app |
| `worker` | `Caddyfile.worker` / `Caddyfile.dev.worker` | **Octane**: `FRANKENPHP_WORKERS` (default 2) workers boot the app once and keep it in memory; `MAX_REQUESTS` (default 500) makes a worker restart itself to cap leaks; the other `FRANKENPHP_THREADS` still serve what is not PHP-worker work |

Worker mode is opt-in and **refused in the Control profile** (start-container falls back to classic with a message): it costs RAM per worker on the board that is the floor. Pieces: `laravel/octane` (composer), `config/octane.php` (server `frankenphp`, default flush/listeners), `public/frankenphp-worker.php` (Octane's stub, as `octane:install` writes it). Try it in dev with `FRANKENPHP_MODE=worker` in `.env`, then `docker compose up -d --force-recreate`; code changes then need `docker compose exec laravel.test supervisorctl restart php`, because the app is not re-read per request.

### Static-state audit (2026-11-12, by reading the code, not run)

No finding that serves wrong data across requests. Request state is already in `scoped()` bindings (`SelectedDevice`, `ArtworkEntryMemo`, `IdAliases`, in `AppServiceProvider`), `DeviceCache`/`ArtworkCache`/`LibraryCache` go through the cache store, `Setting` is an uncached query. Handled: `SpotifyTokenService` built its `Session` (credentials and callback URL) in the constructor; a controller instance can outlive a request, so it is now built on first use. Left as is, with reason:

- `MqttService` singleton: one socket per worker, client id has the pid, reconnects after 45 s idle and retries once. Needs no flush; a long-lived socket is the point. `mqtt.host` changes need a worker restart.
- `Analysis\Tools::$versions` (a failed probe is remembered until `Tools::forget()`), `Spectrum::$plans/$window` (bounded, constant frame size), `SmartFields::$fields` (option lists are re-evaluated per call), `ServiceCredentials::$declarations` (fixed strings, values read per call), `Scenes\OpenApiSpec::$memo` (a new key per changed file; only a deploy changes it), `StreamUrl::$profile` (set and restored in `try/finally`), `Profile::$warned` (log once per worker): bounded or constant; a deploy or `MAX_REQUESTS` recycles them.
- `config([...])` at runtime in `SqliteImporter` and `BackupWriter`: believed console-only; **to verify** they are not reachable from a web request or Livewire action.
- `LibraryCache::remember` uses `defer()`: needs Octane 2.5+ (installed 2.23).

### Still to do before worker mode is more than a trial

- **Memory soak** on a 1 GB Pi 2/3 over several days: RSS per worker, growth between restarts, with 6 listening devices. Set `FRANKENPHP_WORKERS` and `MAX_REQUESTS` from it.
- **Hardware check run** (`scripts/hardware-check`) with `FRANKENPHP_MODE=worker`, to compare with classic (roadmap 9b item 4).
- Verify the two items above (`config()` mutation reachability, Livewire under workers: file uploads at `/settings/storage`, `wire:poll`, the MCP endpoint's `ApiBridge` request swap).
- A request-isolation test against a running worker (same session cookie twice, two different `SelectedDevice` cookies back to back).

## Architectures

The base image is multi-arch (amd64, arm64, arm/v7). Nothing in the Dockerfile or compose file pins a platform, and `ansible/deploy.yml` runs `docker compose build` on the target host, so each machine builds natively for its own architecture (a Pi 2 builds armv7, a Pi 3/4 on a 64-bit OS arm64, an x86 box amd64). Never build one architecture by emulating another.

## Behaviour worth knowing

- Host networking and `APP_PORT` are unchanged, so the Avahi `_remoment._tcp` advertisement needs no change.
- Caddy runs as `sail` and binds port 80 through `setcap cap_net_bind_service` on the `frankenphp` binary.
- Healthcheck: the base image probes Caddy's admin API on :2019, which is off here, so the Dockerfile replaces it with a request for `/robots.txt` on `APP_PORT` (served by Caddy, no PHP boot). It shows whether Caddy answers, not whether PHP works. Found when the Manager showed the first production `app` as `unhealthy`; unverified until the rebuilt image runs there.
- Caddy state lives in `/data` and `/config` (`XDG_*` set in supervisor); nothing persists, there are no certificates.
- `/stream/{track}` (Range requests) and uploads work as before. A new proxy in front would need its own body limit.
- Verified on arm64: `/api/info` 200, a 50 MB POST through the body limit, PHP 8.4 ZTS with pcntl, sockets, gd, imagick, redis, igbinary, pdo_mysql, opcache.

## Not done

- **Measurement on a Pi 2.** idle RAM and RAM under concurrent requests against the old `artisan serve` (roadmap 9b item 4). Lower `FRANKENPHP_THREADS` if memory is tight.
- Extensions the old Ubuntu image had and the app does not use (pgsql, ldap, imap, mongodb, memcached, swoole) are not installed; xdebug and pcov only in the dev build.
