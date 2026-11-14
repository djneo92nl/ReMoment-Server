# ReMoment Manager (workstream 10, read-only core)

Code: `manager/` (`server.js`, `bin/remoment-manager`, `test/`). Status: **skeleton built**: status, logs, restart, audit, CLI, and the web page Settings › Manager (admin login, `App\Domain\Manager\ManagerClient`). Upgrade and rollback are built (see "Upgrade and rollback"); no SMB, no Pi image yet (see "Still to do"). Plan: [v1-roadmap.md](v1-roadmap.md) section 10.

## What it is for

A small service on the **host**, outside the app container, that shows and restarts the containers of the ReMoment compose project. It must keep working when the app, the web UI or the database is down, which is why it is not part of the Laravel app and has no dependency on it. Shape borrowed from the owner's DevDash: `docker compose` through `execFile` with argument arrays, a localhost HTTP API, a CLI that is only a client of that API, a header and Host check on every change, crashed and restarting containers shown (not hidden).

## Decisions (defaults chosen by the owner; changeable, the contract is the HTTP API and the CLI)

| Decision | Choice | Reason |
|---|---|---|
| Language | **Node, one dependency-free file**, no npm packages | Reuses DevDash's shape; Node already exists on dev hosts. Go (static binary for armv7/arm64) stays open if Pi measurements say Node is too heavy on 1 GB. |
| Token | **Separate from the admin password**: random 256-bit hex, created on first start, file mode 0600 in a 0700 directory (`~/.remoment-manager/token`, directory from `REMOMENT_MANAGER_HOME`) | The app cannot verify the admin password while it is down. |
| Binding | **127.0.0.1** by default, port `REMOMENT_MANAGER_PORT` (default 8099); `REMOMENT_MANAGER_BIND=0.0.0.0` (Ansible: `manager_lan: true`) opens the web page to the LAN | Root-equivalent power; the LAN page is opt-in, plain HTTP, and says so on the page. |
| Updating itself | Later, by its own installer | Not built. |
| Init files | Examples below only; nothing installs them | Installers come later. |

## What it controls

One fixed compose project: `REMOMENT_PROJECT_DIR` (default: the repository root), `REMOMENT_COMPOSE_FILE` (default `docker-compose.prod.yml`, relative to the project dir), `REMOMENT_COMPOSE_PROJECT` (default `remoment`). It only works on the services that `docker compose config --services` lists, and takes a service name only if it is exactly one of them. Everything it runs is `docker compose -f FILE -p PROJECT <config|ps|logs|restart> ...` plus read-only `docker inspect` and `docker stats` on container ids that `compose ps` returned.

## API

All JSON unless noted; all need `Authorization: Bearer <token>` except `/health`.

```
GET  /health                  → { status, name, version, uptime_seconds }          no token, nothing sensitive
GET  /status                  → { project, services: [Service], host: Host }
GET  /logs/{service}?tail=200 → text/plain; tail 1..2000 (capped), non-numeric → 400
POST /restart/{service}       → { status: "ok", action: "restart", service }       needs header x-remoment-manager: 1
POST /restart-all             → { status: "ok", action: "restart-all", service: null }   same header
GET  /audit?limit=50          → { data: [ { time, action, service, result } ] }    newest first, limit 1..500
```

`Service` = `{ service, container, state (running|restarting|exited|missing|…), health (healthy|unhealthy|starting|null), problem (null|"crashed"|"restarting"), restart_count, up_seconds, exit_code (exited only), memory_bytes, memory_usage ("128MiB / 1GiB"), cpu_percent }`. `crashed` = exited with a non-zero exit code other than 143 (137 only when OOM-killed), as DevDash; `restarting` = the container is in the restarting state. A service in the compose file without a container is `missing`.

`Host` = `{ disk: { path, free_bytes, total_bytes } | null, memory: { total_bytes, free_bytes }, load: [1m, 5m, 15m], uptime_seconds, cpu_temperature_c | null }` (temperature from `/sys/class/thermal/thermal_zone0/temp`, `null` where absent; `REMOMENT_THERMAL_FILE` overrides the path for tests).

Errors: `401 unauthorized`, `403 bad_host` / `missing x-remoment-manager header`, `404 not_found` / `unknown_service`, `405 method_not_allowed`, `409 busy` (a change is running), `413 too_large`, `400 bad_tail`, `502 docker_failed` (docker's last 500 characters of output).

## CLI

```
remoment-manager status [--json]        services, problems, host figures
remoment-manager logs <service> [--tail N]
remoment-manager restart <service>
remoment-manager restart-all
remoment-manager audit [--limit N] [--json]
remoment-manager upgrade [--wait]       starts the upgrade; --wait follows the log and exits 0 (ok) or 1 (failed)
remoment-manager rollback [--wait]
remoment-manager upgrade-status [--json]
remoment-manager token                  prints the token file's path, never the token
```

It reads the token file and calls the API; `REMOMENT_MANAGER_HOME` and `REMOMENT_MANAGER_PORT` pick the instance.

## Security model

- `execFile` with argument arrays in exactly one place (`run()`); no shell, no generic exec, no docker passthrough. The only request input that reaches a command is a service name equal to one of the compose file's services; `tail` is parsed to an integer and capped.
- The `Host` header must be `127.0.0.1:<port>` or `localhost:<port>` (also for `/health`), against DNS rebinding; bound to 127.0.0.1 unless LAN mode is switched on (then also IP addresses, `*.local`, this machine's name and the allow-list, see above).
- Bearer token compared in constant time (SHA-256 of both sides, `timingSafeEqual`); never logged, never returned; the file is re-chmodded 0600 at start.
- Every change needs the `x-remoment-manager: 1` header (a browser cannot send it cross-site without a preflight).
- One change at a time (lock): a second change answers 409. Reads (status, logs, audit) stay available during a change. A change has a 120 s limit.
- Every change is appended (time, action, service, result) to `audit.log` in the manager home, mode 0600. Failed changes are logged as `failed (exit N)`. Denied requests are not logged (no token spam).
- Request bodies are capped at 1 KiB (only `/login` takes one: the token).

## The manager's own web page (separate from the app and its admin login)

`GET /` serves one inline page (no libraries, no external requests) that signs in with the **manager token**, never the admin password, and then shows the same data as the CLI: containers (state, health, crashed/restarting, uptime, restarts, memory, CPU), host figures, logs per service, Restart per service and Restart all, and the audit log. It works when the app, the web UI or the database is down, which is the point.

- Sign-in: `POST /login {token}` (needs the `x-remoment-manager: 1` header) sets an `HttpOnly; SameSite=Strict` session cookie, 12 hours, kept in memory (a manager restart signs everyone out); `POST /logout` ends it. The API accepts the bearer token (CLI, the app) or that session; changes still need the header. Ten wrong tokens from one address in ten minutes answer 429 for everyone behind it, even the right token, until the window passes. A login is audited.
- The page itself holds nothing secret and is served without a session; a CSP with a per-response nonce allows no external requests, `X-Frame-Options: DENY`; page text goes in with `textContent` only (a test forbids `innerHTML` and friends).
- **Loopback by default.** With `REMOMENT_MANAGER_BIND=0.0.0.0` (or one address) the page is reachable from the network over plain HTTP and shows a warning banner; `/health` then carries `lan: true`. The Host check then accepts IP addresses, `*.local`, this machine's name and `REMOMENT_MANAGER_ALLOWED_HOSTS` (comma list), on the manager's port only; a foreign domain pointing at the address (DNS rebinding) is still refused. Without HTTPS the token and cookie cross the network in clear: only on a trusted LAN, or behind a reverse proxy that adds TLS.
- Ansible: `manager_lan: true` (and optionally `manager_allowed_hosts`) in `group_vars/all.yml`, then `manager.yml`; the page is at `http://<server>:8099/`.
- Tested in `manager/test/manager.test.js` (page headers, login, session, header, logout, throttle, Host in both modes) and clicked through once in a browser against the fake docker; not run against the real stack.

### Open mode, start/stop and the CPU graph

- **Open mode** (`REMOMENT_MANAGER_OPEN=1`, Ansible `manager_open: true`): the page and the browser API need no sign-in; the Host check and the `x-remoment-manager` header on changes stay. The token file and bearer token still work for the CLI and the app. With open mode on a LAN, anyone on that network can stop containers.
- **Start/stop of every service**: `POST /start/{service}` (`compose --profile '*' up -d {service}`, up to 15 minutes, it may build the image) and `POST /stop/{service}`; all commands use `--profile '*'`, so services behind a profile (analysis, structure, stems, icecast, librespot, beolink, meilisearch, mariadb) are listed and can be started. A service stopped here stays stopped until started again; a deploy does not start it unless its profile is in `COMPOSE_PROFILES`.
- **Features** (`GET /features`, `POST /features/{name}` `{enabled}`): analysis, structure, stems, spotify-stream, radio-relay. Switching one on edits `.env` (its values such as `STEMS_URL`, and its profile in `COMPOSE_PROFILES`), starts its containers (`up -d`) and recreates `app` (`up -d app`, the app reads `.env` at creation: the web UI is gone for a few seconds), then runs `php artisan optimize:clear` and `optimize` in it (retried while it starts) so the cached config picks up the new values; off undoes it and stops the containers (Icecast stays while another enabled feature needs it). The choice is recorded in `features.json` in the manager home, and `deploy.yml` re-applies it after it rewrote `.env` (`node manager/server.js --apply-env`), so a deploy does not undo it. Not editable: any other `.env` value (a fixed list, no free-form editor). The `analysis` feature needs `PROFILE=full`; the Manager does not check that.
- **Feedback**: a row shows Starting/Stopping/Restarting (pulsing dot) and all buttons are disabled until the call returns.
- **CPU history**: `GET /history` returns `{ interval_seconds, data: { service: [{ t, cpu, mem }] } }`, an in-memory ring of 240 samples (an hour at the default 15 s, `REMOMENT_SAMPLE_SECONDS`, 0 = off) taken from the same `docker stats` call as `/status` (cached 5 s). It is lost on a manager restart. The page draws one line per container (100% = one core).

## Web page inside the app (Settings › Manager)

This one is the app's view of the manager, behind the admin login, for convenience when the app is up. The manager's own page above is the independent one.

`/settings/manager` (`Livewire\ManagerPanel`, behind the admin login like all of `/settings`) shows the containers with state, health, crashed/restarting, uptime, restarts, memory and CPU, the host figures, a log viewer per service and the recent audit entries; Restart per service and Restart all (with a confirm; restarting `app` stops the server that serves the page, so the answer may not arrive). It refreshes every 10 s while the tab is visible.

The app calls the manager at `MANAGER_URL` (default `http://127.0.0.1:8099`: production runs host-networked) with the manager's own token and the `x-remoment-manager: 1` header (`config/manager.php`). The token is read from `MANAGER_TOKEN_FILE` (default `/run/remoment-manager/token`), the manager's home mounted read-only into the app container (`MANAGER_HOME`, `docker-compose.prod.yml`, set by Ansible's `env.j2`); `MANAGER_TOKEN` is an alternative. The admin password is never the token. Without a manager, a token or an answer the page explains which one is missing instead of failing. Not available on a dev machine where Docker runs the app in a bridge network (the manager's Host check wants `127.0.0.1`). Tests: `tests/Feature/ManagerPageTest.php` with `Http::fake`; unverified against the real manager.

## Upgrade and rollback

The web version of `app:upgrade` ([backup-restore.md](backup-restore.md)), which stays as the CLI. Code: `startJob`, `upgradeJob`, `rollbackJob` in `manager/server.js`; state in `upgrade.json` in the manager home (mode 0600, survives a manager restart; a job found still "running" after a restart is marked failed `interrupted`). One change at a time: the job holds the same lock as restart/start/stop, so every other change answers 409 while it runs; reads stay available.

**You install the new code first** (git pull, the Ansible deploy, or a new image on the registry); the manager does not fetch code. What it does for the `app` service:

1. **Keep the current image**: `docker tag <image> <repo>:previous` (the image name is read from `compose config --format json`, `services.app.image`, and checked against a strict pattern). No image yet (first install) is a skipped step. Failure stops before anything changed.
2. **New image**: `compose build app` (`REMOMENT_UPGRADE_MODE=build`, the default: the code is on disk) or `compose pull app` (`pull`: registry images, once they exist). Failure stops here, the running container is untouched (`image_failed`).
3. **Start it**: `compose up -d --no-build app`.
4. **`php artisan app:upgrade --force --json`** in the app container (`exec -T -u sail`), retried while the container is still starting and prints no JSON object. Its exit code and `exit_reason` become the result: **0** ok, **1** `blocked` (pre-flight, nothing migrated), **2** `backup_failed` (nothing migrated), **3** `migration_failed` (**the app stays in maintenance mode**), **4** `health_failed` (the app is up). Anything else or no answer: `app_upgrade_failed`. From its JSON the manager keeps `backup`, `migrations_run`, `steps`, `health`, `problems`, `reasons`, `rollback` (`previous_version`, `backup`, `commands`, `note`). Audit lines: `upgrade started`, then `upgrade ok` or `upgrade failed (<reason>)`.

**Rollback** (`POST /rollback`) is only possible for the last upgrade when it failed and has not been rolled back (`can_rollback`). It follows that upgrade's own `rollback` block, never anything from the request: the previous image comes back (`docker tag <repo>:previous <image>`, `up -d --no-build app`; skipped when step 1 had kept none, refused when the tag is gone), then, **only after `migration_failed` or `health_failed`** (the only exits that touched the database), `php artisan backup:restore <archive> --force` with the archive path from the failed upgrade's answer (refused unless it matches `^/?[A-Za-z0-9_][A-Za-z0-9_./-]*$` with no `..`, so it can never be taken for a flag), then `php artisan up`, `optimize:clear`, `queue:restart`. Anything saved after the backup is lost; after `blocked`/`backup_failed` only the image goes back. A failed step leaves the upgrade rollback-able so it can be retried. Audit: `rollback started`, `rollback ok` / `failed (<reason>)`.

**Security.** Neither endpoint takes a body, a query value or a path segment into a command: the service is the constant `app` (checked to be in the compose file), the image comes from the compose file, the archive from the app's answer, all through the one `execFile` with argument arrays. Token or session, the `x-remoment-manager` header and the Host check apply as to every change. The CLI and the app's Settings › Manager card use the same endpoints; the manager's own page has no upgrade button yet.

**Status stream.** The job answers 202 at once; `GET /upgrade?since=N` returns the records (`upgrade`, `rollback`: `state` running/ok/failed, `exit_code`, `exit_reason`, `message`, `steps: [{name, result: ok|failed|skipped, seconds}]`, …) plus the log lines after `N` (`log_next` is the next offset); the log is in memory, the last 500 lines. Timeouts: 15 minutes per image step and per `app:upgrade` run.

**Settings › Manager** (`Livewire\ManagerPanel`, `ManagerClient::upgrade()/rollback()/upgradeStatus()`): an Upgrade card with an Upgrade button (a `wire:confirm` says the app is unavailable while it migrates and that a backup comes first), the steps and result of the last upgrade or rollback, the hand-run commands of the app's rollback block after a failure, and a Roll back button (with its own confirm, naming the data loss) while `can_rollback`. While a job runs the page polls every 2 s and shows the log. A manager without these endpoints simply shows no card. Admin login only like the whole page; the buttons do nothing else than the two calls above.

**Not verified:** against a real Docker, a real image build or a real `app:upgrade` (the tests use the fake docker and its canned answers); `docker image inspect/tag` and `compose config --format json` output shapes are written from memory; whether `backup:restore --force` into a database that is already migrated forward works on MariaDB (the restore docs say "fresh install"); the app's own container is recreated during an upgrade, so a web page that triggered it loses its connection for a while (the page reconnects through its poll).

## Tests

`cd manager && node --test test/` (no packages). A fake `docker` script (`test/fake-docker.js`) is put first on PATH; it answers `compose config --services`, `compose ps`, `inspect`, `stats`, `compose logs`, `compose restart` with canned output and records every call. No real container is touched. Covered: status shape, crashed and restarting, logs tail cap, exact restart arguments, unknown service gives 404 with no command run, 401, 403 (Host and header), 404/405, 409 while a change runs, 413, audit entries, failed restart, token file mode, the CLI.

## Example init files (the Ansible unit is derived from the systemd one; the launchd one is untested)

systemd (`/etc/systemd/system/remoment-manager.service`):

```ini
[Unit]
Description=ReMoment Manager
After=docker.service network.target
Wants=docker.service

[Service]
User=remoment
Environment=REMOMENT_PROJECT_DIR=/opt/remoment
Environment=REMOMENT_COMPOSE_FILE=docker-compose.prod.yml
Environment=REMOMENT_MANAGER_HOME=/var/lib/remoment-manager
ExecStart=/usr/bin/node /opt/remoment/manager/server.js
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

launchd (`~/Library/LaunchAgents/nl.remoment.manager.plist`):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>nl.remoment.manager</string>
  <key>ProgramArguments</key><array><string>/usr/local/bin/node</string><string>/path/to/ReMomentServer/manager/server.js</string></array>
  <key>EnvironmentVariables</key><dict><key>REMOMENT_PROJECT_DIR</key><string>/path/to/ReMomentServer</string></dict>
  <key>RunAtLoad</key><true/><key>KeepAlive</key><true/>
</dict></plist>
```

## Still to do (open)

- Neither page has start/stop of single processes or SMB yet; the manager's own page has no upgrade button (the CLI and Settings › Manager have). HTTPS for the LAN page (today: a reverse proxy).
- Processes inside the app container (supervisor: queue, scheduler, listeners), start/stop of single services.
- Host backup now, diagnostics bundle; registry images for `REMOMENT_UPGRADE_MODE=pull` (none published yet).
- SMB serve and mount (Samba on the host).
- Installers: Raspberry Pi image, install script for plain compose. (The Ansible install exists: `ansible/manager.yml`, systemd unit from `ansible/templates/remoment-manager.service.j2`; syntax-checked only, not run against a host.)
- How the manager updates itself (decision c) and the init system per target (decision d).
- Go instead of Node, if the Pi measurements say so.

## Unverified

- Against a real compose stack: the field names of `docker compose ps --format json` (`ID`, `Service`, `State`, `Health`, `ExitCode`, NDJSON on current Compose, an array on older) and `docker stats`/`inspect` output are written from memory and the fake, not checked against a live Docker; the status tests only prove the parsing of that shape.
- A Pi (armv7, 1 GB): the cost of `docker stats --no-stream` per status call (about a second on a laptop) and Node's footprint.
- The init files above.
