# ReMoment Manager (workstream 10, read-only core)

Code: `manager/` (`server.js`, `bin/remoment-manager`, `test/`). Status: **skeleton built**: status, logs, restart, audit, CLI, and the web page Settings › Manager (admin login, `App\Domain\Manager\ManagerClient`). No upgrade, no SMB, no Pi image yet (see "Still to do"). Plan: [v1-roadmap.md](v1-roadmap.md) section 10.

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

- Neither page has upgrade, start/stop of single processes or SMB yet. HTTPS for the LAN page (today: a reverse proxy).
- Processes inside the app container (supervisor: queue, scheduler, listeners), start/stop of single services.
- Upgrade and rollback (calls `app:upgrade`, previous image tag plus `backup:run`/`backup:restore`), host backup now, diagnostics bundle.
- SMB serve and mount (Samba on the host).
- Installers: Raspberry Pi image, install script for plain compose. (The Ansible install exists: `ansible/manager.yml`, systemd unit from `ansible/templates/remoment-manager.service.j2`; syntax-checked only, not run against a host.)
- How the manager updates itself (decision c) and the init system per target (decision d).
- Go instead of Node, if the Pi measurements say so.

## Unverified

- Against a real compose stack: the field names of `docker compose ps --format json` (`ID`, `Service`, `State`, `Health`, `ExitCode`, NDJSON on current Compose, an array on older) and `docker stats`/`inspect` output are written from memory and the fake, not checked against a live Docker; the status tests only prove the parsing of that shape.
- A Pi (armv7, 1 GB): the cost of `docker stats --no-stream` per status call (about a second on a laptop) and Node's footprint.
- The init files above.
