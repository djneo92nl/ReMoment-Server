# FrankenPHP (production web server)

Production serves the web UI and REST API with **FrankenPHP** (Caddy + embedded PHP) in **classic mode**: every request boots the app, like php-fpm, so there is no state shared between requests. It replaced `php artisan serve`, which handled one request at a time.

## Files

| File | Role |
|---|---|
| `docker/frankenphp/Dockerfile` | Production app image, `FROM dunglas/frankenphp:1-php8.4` (Debian, thread-safe PHP). Adds the PHP extensions, ffmpeg, fpcalc, librsvg, supervisor, Node and Composer (the Ansible deploy runs `composer` and `npm` inside the container). Build context is `./docker` |
| `docker/frankenphp/Caddyfile` | `admin off`, `auto_https off`, listens on `:{$APP_PORT:80}`, root `public/`, 300 MB request body limit, `php_server` |
| `docker/frankenphp/php.ini` | Same limits as the Sail image (300M uploads, `variables_order=EGPCS`) |
| `docker/8.4/supervisord.prod.conf` | `[program:php]` runs `frankenphp run`; queue workers, scheduler and `device:listen` are unchanged (CLI `php`, symlinked to `/usr/bin/php`) |
| `docker-compose.prod.yml` | Builds the image; `FRANKENPHP_THREADS` (default 4) sets the PHP threads that serve requests concurrently |

The dev image (`docker/8.4`, Sail) is unchanged and still uses `artisan serve`.

## Architectures

The base image is multi-arch (amd64, arm64, arm/v7). Nothing in the Dockerfile or compose file pins a platform, and `ansible/deploy.yml` runs `docker compose build` on the target host, so each machine builds natively for its own architecture (a Pi 2 builds armv7, a Pi 3/4 on a 64-bit OS arm64, an x86 box amd64). Never build one architecture by emulating another.

## Behaviour worth knowing

- Host networking and `APP_PORT` are unchanged, so the Avahi `_remoment._tcp` advertisement needs no change.
- Caddy runs as `sail` and binds port 80 through `setcap cap_net_bind_service` on the `frankenphp` binary.
- Caddy state lives in `/data` and `/config` (`XDG_*` set in supervisor); nothing persists, there are no certificates.
- `/stream/{track}` (Range requests) and uploads work as before. A new proxy in front would need its own body limit.
- Verified on arm64: `/api/info` 200, a 50 MB POST through the body limit, PHP 8.4 ZTS with pcntl, sockets, gd, imagick, redis, igbinary, pdo_mysql, opcache.

## Not done

- **Octane worker mode.** Needs a static-state audit (`MqttService` singleton in `AppServiceProvider`, statics in `Analysis/Tools`, `Analysis/Spectrum`, `SmartFields`) and a multi-day memory soak on 1 GB.
- **Measurement on a Pi 2.** idle RAM and RAM under concurrent requests against the old `artisan serve` (roadmap 9b item 4). Lower `FRANKENPHP_THREADS` if memory is tight.
- Extensions the old Ubuntu image had and the app does not use (pgsql, ldap, imap, mongodb, memcached, swoole, xdebug, pcov) are not installed.
