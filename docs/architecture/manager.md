# ReMoment Manager (workstream 10, read-only core)

Code: `manager/` (`server.js`, `bin/remoment-manager`, `test/`). Status: **skeleton built**: status, logs, restart, audit, CLI. No web page, no upgrade, no SMB, no installers yet (see "Still to do"). Plan: [v1-roadmap.md](v1-roadmap.md) section 10.

## What it is for

A small service on the **host**, outside the app container, that shows and restarts the containers of the ReMoment compose project. It must keep working when the app, the web UI or the database is down, which is why it is not part of the Laravel app and has no dependency on it. Shape borrowed from the owner's DevDash: `docker compose` through `execFile` with argument arrays, a localhost HTTP API, a CLI that is only a client of that API, a header and Host check on every change, crashed and restarting containers shown (not hidden).

## Decisions (defaults chosen by the owner; changeable, the contract is the HTTP API and the CLI)

| Decision | Choice | Reason |
|---|---|---|
| Language | **Node, one dependency-free file**, no npm packages | Reuses DevDash's shape; Node already exists on dev hosts. Go (static binary for armv7/arm64) stays open if Pi measurements say Node is too heavy on 1 GB. |
| Token | **Separate from the admin password**: random 256-bit hex, created on first start, file mode 0600 in a 0700 directory (`~/.remoment-manager/token`, directory from `REMOMENT_MANAGER_HOME`) | The app cannot verify the admin password while it is down. |
| Binding | **127.0.0.1 only**, port `REMOMENT_MANAGER_PORT` (default 8099); no setting to bind elsewhere | Root-equivalent power; a LAN page is opt-in later. |
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
- The `Host` header must be `127.0.0.1:<port>` or `localhost:<port>` (also for `/health`), against DNS rebinding; bound to 127.0.0.1.
- Bearer token compared in constant time (SHA-256 of both sides, `timingSafeEqual`); never logged, never returned; the file is re-chmodded 0600 at start.
- Every change needs the `x-remoment-manager: 1` header (a browser cannot send it cross-site without a preflight).
- One change at a time (lock): a second change answers 409. Reads (status, logs, audit) stay available during a change. A change has a 120 s limit.
- Every change is appended (time, action, service, result) to `audit.log` in the manager home, mode 0600. Failed changes are logged as `failed (exit N)`. Denied requests are not logged (no token spam).
- Request bodies are capped at 1 KiB (no endpoint takes one).

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

- The web page inside ReMoment (Settings › Manager) and the app-to-manager link with the admin login (the roadmap's step 2), and the manager's own LAN page behind the token (step 8, opt-in, with the "LAN only" warning).
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
