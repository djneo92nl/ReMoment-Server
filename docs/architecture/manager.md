# ReMoment Manager (workstream 10, read-only core)

Code: `manager/` (`server.js`, `bin/remoment-manager`, `test/`). Status: **skeleton built**: status, logs, restart, audit, CLI, and the web page Settings › Manager (admin login, `App\Domain\Manager\ManagerClient`). Upgrade and rollback are built (see "Upgrade and rollback"), and so is **SMB serve** (see "SMB shares", 2026-11-14, tested against fake commands only, never against a real Samba), **SMB mount** (a NAS share as a storage location, see "NAS mounts", 2026-11-21, fake `systemctl` only) and the **installers** (systemd unit and script for Linux, launchd agent for a Mac, see "Installers", 2026-11-21, rendered into a scratch directory only); the Pi image is not (see "Still to do"). Plan: [v1-roadmap.md](v1-roadmap.md) section 10.

## What it is for

A small service on the **host**, outside the app container, that shows and restarts the containers of the ReMoment compose project. It must keep working when the app, the web UI or the database is down, which is why it is not part of the Laravel app and has no dependency on it. Shape borrowed from the owner's DevDash: `docker compose` through `execFile` with argument arrays, a localhost HTTP API, a CLI that is only a client of that API, a header and Host check on every change, crashed and restarting containers shown (not hidden).

## Decisions (defaults chosen by the owner; changeable, the contract is the HTTP API and the CLI)

| Decision | Choice | Reason |
|---|---|---|
| Language | **Node, one dependency-free file**, no npm packages | Reuses DevDash's shape; Node already exists on dev hosts. Go (static binary for armv7/arm64) stays open if Pi measurements say Node is too heavy on 1 GB. |
| Token | **Separate from the admin password**: random 256-bit hex, created on first start, file mode 0600 in a 0700 directory (`~/.remoment-manager/token`, directory from `REMOMENT_MANAGER_HOME`) | The app cannot verify the admin password while it is down. |
| Binding | **127.0.0.1** by default, port `REMOMENT_MANAGER_PORT` (default 8099); `REMOMENT_MANAGER_BIND=0.0.0.0` (Ansible: `manager_lan: true`) opens the web page to the LAN | Root-equivalent power; the LAN page is opt-in, plain HTTP, and says so on the page. |
| Updating itself | Later, by its own installer | Not built. |
| Init files | `manager/install/`: systemd unit + install/uninstall scripts for Linux, launchd agent for a Mac (see "Installers") | Idempotent, token made root-only; run only into a scratch directory so far. |

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
remoment-manager smb [status|config|enable|disable|user create|user remove]    see "SMB shares"
remoment-manager mount [status|add|on|off|remove]                              see "NAS mounts"
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

## SMB shares (serve)

Code: `manager/smb.js` (wired into `server.js`), `manager/test/smb.test.js` with `fake-samba.js`; the app side is the Network shares card of Settings › Manager and `ManagerClient::smb*()`. **Every part of it is unverified on a real Samba host**: the tests put fake `id`, `useradd`, `userdel`, `smbpasswd`, `testparm`, `systemctl`, `smbcontrol` and `smbstatus` scripts first on PATH and prove the arguments, the order, the generated file and the refusals, not that Samba accepts the file or serves it.

**What it does.** Samba runs on the host (decided). The manager owns one config *fragment*, `REMOMENT_SMB_CONF` (default `/etc/samba/remoment.conf`), and never edits `smb.conf`. Three fixed shares, named exactly `library`, `exports`, `inbox`, each off until switched on:

| Share | Path (manager environment) | Access |
|---|---|---|
| `library` | `REMOMENT_SMB_LIBRARY_DIR` (default `LIBRARY_DIR`, else `/srv/library`) | **read-only**; the admin can switch writing on (a PC can then delete music; the next scan finds changes) |
| `exports` | `REMOMENT_SMB_EXPORTS_DIR` (default `EXPORT_SHARE_DIR`, else `/srv/exports`) | read-only, always; finished exports appear here (below) |
| `inbox` | `REMOMENT_SMB_INBOX_DIR` (default `/srv/inbox`; **set it to the root of the inbox storage location**: the manager cannot read the app's database) | read-write, always; copy albums here, then Import inbox |

Unless guests are allowed, every share is `guest ok = no`, `valid users = <share user>`, `follow symlinks = no`, `wide links = no`; writable ones get create/directory masks 0664/0775 and, when `REMOMENT_SMB_FORCE_USER` is set (the app's unix user), `force user` so the app can read and move what arrives. **Guest access is an opt-in per share** (`guest: true` in the share change, `--guest`, the card's Allow guests with a confirm; off by default, and the card marks an open share): the section then has `guest ok = yes` and no `valid users`, so anyone on the network reads it (and writes it, on the inbox) without a password. The library cannot be guest and writable at once (`422 guest_writable`). Guests need `map to guest = Bad User`: the fragment carries its own `[global]` section with that line (the owner's note, 2026-11-21) whenever a share is on, so no installer or admin step is needed for it (a test checks the section; that Samba merges a second `[global]` from an include is unverified). The `include` line goes at the **end** of `smb.conf` (the fragment brings its own `[global]` and share sections, so it must not split `smb.conf`'s own `[global]`). Otherwise there is one share user for all shares, separate from the admin login and from every other account, no per-share users. A password share cannot be switched on before the user exists; a guest share can.

**Share user.** `POST /smb/user` is the only thing that creates it, an explicit authenticated action (CLI `smb user create`, the card's Create user). The name is `REMOMENT_SMB_USER` (default `remoment-smb`, validated against `^[a-z_][a-z0-9_-]{0,30}$`; never the deploy user). Steps: `id -u` (an existing system account the manager did not create is refused `409 user_exists`, never touched), `useradd --system --no-create-home --shell /usr/sbin/nologin --user-group -- NAME`, then `smbpasswd -a -s NAME` with the generated password on **stdin** (never an argument, never in `ps`). A second call is a password reset. The password is 24 characters from a 56-letter alphabet, made with `crypto.randomBytes`, **returned once in that response** and kept nowhere: not in the audit, not in `smb.json`, not in status, not in any error text (a failing `smbpasswd` has the password stripped from its message). The web card shows it in a box with "I have noted it"; the CLI prints it once. `DELETE /smb/user` removes the Samba entry and, only if the manager made the account, the account; refused `409 shares_enabled` while a share is on.

**Applying a change** (`POST /smb/shares/{name}` `{enabled, writable?}`): validate (name from the fixed list, booleans only, the path from the environment checked: absolute, `[A-Za-z0-9_./ -]` only, no `..`, not `/` or a system directory like `/etc`, `/usr`, `/var`, `/home`) → render the fragment for all shares → write `REMOMENT_SMB_CONF.new` (0644) → `testparm -s --suppress-prompt <the new file>`; **only when it accepts it** the file replaces the live one (a rejected file is deleted, the old fragment stays, `502 config_invalid`) → save the choice in `smb.json` (0600, no secrets) → `systemctl start smbd` when Samba is not running and a share is on, else `smbcontrol smbd reload-config`. Switching everything off leaves an empty fragment and does not stop `smbd` (it may serve other things). The one-line `include = <fragment>` in `smb.conf` is **not** added by the manager: the installer or the admin does it once; status says `include_present: false` and the card warns when it is missing. The change takes the same one-at-a-time lock as restart and upgrade (409 while another change runs).

**Dry run.** `?dry_run=1` on any SMB change renders the fragment and lists the steps without writing a file, saving a choice or running a command (also no password is made); `GET /smb/config` renders the current choices; `REMOMENT_SMB_DRY_RUN=1` makes every SMB change a dry run (status says `dry_run: true`). CLI `--dry-run`, the card's Preview button.

```
GET    /smb                      → { dry_run, running, service, include_present, conf_file, problems, user: {name, created_at, password_set_at}|null, sessions: {count, by_share, data: [{user, machine}]}|null, shares: [{name, label, note, enabled, guest, writable, can_set_writable, path, path_error, path_exists}] }
GET    /smb/config               → { config }                            the fragment for the current choices, nothing written
POST   /smb/shares/{name}        { enabled, writable?, guest? } [?dry_run=1]     → { status, share, enabled, writable } | { dry_run, share, enabled, writable, config, actions }
POST   /smb/user [?dry_run=1]    → { status, user, created, password }   the only place a password is ever returned
DELETE /smb/user [?dry_run=1]    → { status, user, removed }
```

All need the token (or session) and, for the three changes, the `x-remoment-manager: 1` header and the Host check, like every other change. Errors: `404 unknown_share`, `422 invalid | no_user | bad_path | bad_setup | guest_writable`, `409 busy | user_exists | shares_enabled`, `404 no_user`, `502 config_invalid | write_failed | reload_failed | useradd_failed | smbpasswd_failed | userdel_failed`. `sessions` comes from `smbstatus --json` (Samba 4.12+; `null` when it fails): a count, a count per share, and user and machine name of up to 20 sessions, never addresses. `running` is `systemctl is-active REMOMENT_SMB_SERVICE` (default `smbd`).

CLI: `remoment-manager smb [status]`, `smb config`, `smb enable|disable <share> [--writable|--read-only] [--guest|--no-guest] [--dry-run]`, `smb user create|remove [--dry-run]`.

**Audit.** `smb share` (service = the share) with `enabled`, `disabled` or `failed (<reason>)`; `smb user` with `created`, `password reset`, `removed` or `failed (...)`. Never a password.

**Security.** Everything runs through the one `run()` (`execFile`, argument arrays, no shell; `run()` got an `input` option for stdin). The only request input is a share name from a fixed list and booleans; the user name and all paths come from the manager's environment and are validated before they reach a command or the file. Root: writing `/etc/samba`, `useradd` and `smbpasswd` need root, and the Ansible unit runs as `remoment`: **SMB needs the manager to run as root** (a drop-in `User=root`) or the fragment and Samba to be writable and the commands permitted otherwise; a failed write says "does the manager run as root?". `manager/install/install-linux.sh --with-samba` installs Samba and appends the include line (with a backup of `smb.conf`); the Ansible role does not yet.

**Exports share, app side.** `EXPORT_SHARE_DIR` (config `export.share_dir`, empty = off; `docker-compose.prod.yml` mounts `${EXPORT_SHARE_DIR:-/srv/exports}` into the app at the same path) is the directory the `exports` share points at. When the export job finishes, `App\Domain\Export\ExportShare::publish()` puts the zip parts in a folder `{title} - {first 8 characters of the export id}`: a hard link when the share directory is on the same disk as the private exports directory, else a copy (so two copies of a large export while it is kept). Folder 0755, files 0644. `ExportRetention` (expiry, the directory cap, an export removed) and `ExportService::cancel()` remove the folder with the export; the retention also sweeps folders of unknown exports once an hour old, **only folders matching the ` - xxxxxxxx` pattern**, so anything else in that directory is never touched. A failure to publish is logged and never fails the export. Tests: `tests/Feature/ExportShareTest.php`.

## NAS mounts (an SMB share of a NAS as a storage location)

Code: `manager/mount.js` (wired into `server.js`), `manager/test/mount.test.js` with `fake-mount.js`; the app side is the NAS mounts card of Settings › Manager (`Livewire\ManagerPanel`), `ManagerClient::mount*()` and the `mount.unavailable` alert (`AlertChecks::mounts`, [alerts.md](alerts.md)). **Every part of it is unverified on a real host**: the tests put fake `systemctl` and `systemd-analyze` first on PATH (real `ls` against temp directories, a fixture `mountinfo`) and prove the files, the order, the refusals and the redaction, not that systemd or the NAS accepts anything. Linux only (systemd, cifs); on a Mac the card works against the API but there is nothing to mount.

**What it writes.** For a mount named `nas`, mount point `REMOMENT_MOUNT_BASE/nas` (default `/mnt/remoment/nas`; a request never picks a path):

| File | Content |
|---|---|
| `REMOMENT_MOUNT_CRED_DIR/nas.cred` (default `/etc/remoment-manager/credentials/`, directory 0700, file 0600) | `username=`, `password=`, optional `domain=`. The only place the password is ever stored; absent for a guest mount (no user, option `guest`). |
| `REMOMENT_MOUNT_UNIT_DIR/<escaped path>.mount` (default `/etc/systemd/system`, mode 0644) | `What=//host/share`, `Where=`, `Type=cifs`, `Options=credentials=…,ro|rw,[vers=…,][uid=,gid=,]file_mode/dir_mode,iocharset=utf8,soft,nosuid,nodev,noexec,_netdev,nofail`, `After=`/`Wants=network-online.target`, `TimeoutSec=30`. Read-only unless the form says writable; SMB1 is not offered. |
| `…/<escaped path>.automount` | `Where=`, `TimeoutIdleSec=0`, `Before=remote-fs.target docker.service`, `WantedBy=remote-fs.target`. **The automount is what is enabled**: the path exists as a trigger from boot, before Docker starts the app, the share is mounted on first use, and a NAS that is off never blocks boot or the app (`nofail`, `soft`: an unreachable share returns an error instead of hanging). |

The unit name is the systemd escaping of the path (`mnt-remoment-my\x2dnas.mount`; `escapePath()` is tested against `systemd-escape -p`). The same options as an `/etc/fstab` line (`_netdev,nofail,x-systemd.automount`) are shown in the dry run for an admin who prefers fstab; the manager never edits fstab. `REMOMENT_MOUNT_UID` / `REMOMENT_MOUNT_GID` (numeric, the app's user; the installer sets them from `--app-uid`) own the files on the mount so the app can read them.

**Dry run, then an explicit confirm.** Every change has a dry run (`?dry_run=1`) that writes nothing, runs nothing and returns the unit files, the fstab line, the credentials file **with the password hidden** (`password=***`), the list of steps and a `plan` token (SHA-256 of what would be written, never covering the password). A real call must repeat that token as `confirm` (body; `?confirm=` for DELETE): none → `422 confirm_required` (the answer carries the plan), a plan that no longer matches the form → `409 plan_changed`. So what is applied is exactly what was shown. `REMOMENT_MOUNT_DRY_RUN=1` makes every change a dry run. The web card previews first and applies with the preview's token; the CLI prints the plan and says which `--confirm` to repeat.

```
GET    /mounts                        → { dry_run, method: "systemd", base, problems, data: [Mount] }
POST   /mounts [?dry_run=1]           { name, host, share, user?, password?, domain?, writable?, version?, confirm? } → { status, name, path, updated } | { dry_run, name, updating, path, plan, config: { mount_unit, automount_unit, fstab, credentials, credentials_file }, actions }
POST   /mounts/{name}/active [?dry_run=1]   { active, confirm? }      → { status, name, active } | { dry_run, plan, actions }   off = disable the automount and unmount
DELETE /mounts/{name} [?dry_run=1|?confirm=PLAN]                       → { status, name, removed } | { dry_run, plan, actions }
```

`Mount` = `{ name, host, share, user, domain, writable, version, path, unit, active, mounted, readable, state, error, lost, last_ok_at, checked_at }`; `state` is `ok` (automount active, a cifs mount in `/proc/self/mountinfo` at the path and the folder lists), `not_mounted` (active but the share did not mount: NAS off or unreachable), `unreadable` (mounted but the listing fails, or the probe did not answer within `REMOMENT_MOUNT_PROBE_SECONDS`, default 5) or `inactive` (switched off on purpose). `lost` is a mount that is not ok and worked before (`last_ok_at`). Same token/session, header and Host rules as every change; errors `404 unknown_mount`, `422 invalid | bad_setup | confirm_required`, `409 busy | plan_changed | mount_busy`, `502 write_failed | config_invalid | daemon_reload_failed | enable_failed`.

**Applying** (add or update; adding a name again updates it): validate every field (name `^[a-z]([a-z0-9-]{0,29}[a-z0-9])?$`, host a plain host name or IPv4 address, share segments of `[A-Za-z0-9_$][A-Za-z0-9_.$-]*` up to four, user, domain, version one of `default|2.0|2.1|3.0|3.1.1`, password up to 128 characters without line breaks or edge spaces, no spaces anywhere that reaches a unit file) → credentials written to `.new`, chmod 0600, renamed → mount point created → both units written to a staging folder inside the unit directory and checked with `systemd-analyze verify` (a rejected unit is never installed and a new credentials file is removed again, `502 config_invalid`) → renamed into place → state saved in `mounts.json` (0600, **no password**) → `systemctl daemon-reload` → (an update first stops the old mount so the new options apply) → `systemctl enable --now <automount>`. The same one-change-at-a-time lock as everything else.

**Off and remove.** Off = `systemctl disable --now` the automount, then `systemctl stop` the mount; a share that is in use is not unmounted: the automount is started again and the answer is `409 mount_busy`. Remove does the same, then deletes both units, the credentials file, the state entry, runs `daemon-reload` and removes the mount-point folder **only when it is empty** (never forced). The web card refuses to remove a mount while a storage location lives on it, and asks the manager for its confirm token itself.

**Status and the alert.** `GET /mounts` probes each mount (`systemctl is-active`, a listing with its own kill timeout that also triggers the automount, the mountinfo file), cached `REMOMENT_MOUNT_CACHE_SECONDS` (default 10) with one probe in flight per mount, and stops waiting after the probe timeout, so a stalled NAS can never stall the manager. A mount that was ok and is not any more is audited (`mount lost`, `mount back`). The app's `alerts:check` (every minute) reads it: `mount.unavailable` per mount, **critical** when it was lost, a warning when it never worked, nothing for a mount that is switched off, resolved when it is back or removed; a manager that does not answer changes nothing.

**Security.** Everything runs through the one `run()` (argument arrays, no shell); only validated request fields reach a file or a command. **The password** travels once, in the body of the confirmed add, over the same link as the manager token (plain HTTP on a LAN page: use a trusted network or a TLS proxy), is written to the 0600 credentials file and **nowhere else**: not in any argument (the mount uses `credentials=<file>`; the CLI takes it from stdin only, `--password-stdin`, and refuses a user without it), not in the audit log, `mounts.json`, a unit file, the fstab preview, any response or any error text (command output has it replaced by `***`). Tested by searching every argument, file and answer for the password. Credentials are not part of the app's backups (they live on the host, outside the app database). Root: writing `/etc/systemd`, `/etc/remoment-manager` and `systemctl enable` need root, like SMB serve (`install-linux.sh` runs the service as root by default).

**In the app.** The card (Settings › Manager, below Network shares) lists the mounts with their state and offers Add (form → Preview → Apply), Unmount / Switch on, Remove and **Use as storage location** (library; inbox too on a writable mount): the entry only, a `StorageLocation` with driver `local` and `root` = the mount path **taken from the manager's answer, never from the browser**, refused unless the mount is ok right now, with a name that cannot collide (`NAS nas`, `NAS nas 2`) and no second entry for the same folder; Settings › Storage does scanning and the rest, and shows the location as unreachable through the existing `storage.unreachable` alert when the folder goes away. The button is hidden in Control (no storage locations). Tests: `tests/Feature/ManagerMountsTest.php`.

**Unverified on a real host** (all of it): systemd accepting the units and `systemd-analyze verify` on units in a staging folder; that `_netdev`, `nofail` and `soft` in a unit's `Options=` are passed through harmlessly to `mount.cifs`; `Before=docker.service` on the automount giving the ordering wanted (and not a boot cycle); `cifs-utils` being installed (the installer does not install it yet); a real NAS (Synology, QNAP, Windows) accepting `vers=` and `credentials=`; the automount triggering on the `ls` probe and a stalled share staying bounded by the probe timeout (a kernel-blocked `ls` is only killed when it wakes); the file indexer and analysis over SMB (slower, no change notifications, a Pi on Wi-Fi may not keep up, see the roadmap); IPv6 hosts and share names with spaces (refused on purpose); and the alert noise of a NAS that flaps (no debounce: one failed probe raises, the next ok one resolves).

## Tests

`cd manager && node --test test/*.test.js` (no packages). A fake `docker` script (`test/fake-docker.js`) is put first on PATH; it answers `compose config --services`, `compose ps`, `inspect`, `stats`, `compose logs`, `compose restart` with canned output and records every call. No real container is touched. Covered: status shape, crashed and restarting, logs tail cap, exact restart arguments, unknown service gives 404 with no command run, 401, 403 (Host and header), 404/405, 409 while a change runs, 413, audit entries, failed restart, token file mode, the CLI. `test/smb.test.js` (fake `id`, `useradd`, `smbpasswd`, `testparm`, `systemctl`, `smbcontrol`, `smbstatus`), `test/mount.test.js` (fake `systemctl`, `systemd-analyze`; generators, validation, dry run and confirm, apply order, password redaction over every argument/file/answer, probe states, lost mounts, stalled share, off/remove, CLI) and `test/install.test.js` (the installers run into a scratch root with guard commands that fail the test if `systemctl`, `chown`, a package manager or the macOS service tool is ever called). `cd manager && npm test`.

## Installers

Files in `manager/install/`; the Ansible install (`ansible/manager.yml`, `templates/remoment-manager.service.j2`) stays as it is and is the way for the Proxmox/Debian target. **Unverified on a real host**: the scripts were only run into a scratch directory (`DESTDIR`) by `manager/test/install.test.js`, where they never call `systemctl`, `chown`, `useradd`, a package manager or `launchctl`; the macOS ones were also linted with `plutil`. Nothing was installed on this machine.

| Target | Files | Run |
|---|---|---|
| Linux with systemd (plain compose host, Debian, Ubuntu, a Pi OS) | `remoment-manager.service` (template), `install-linux.sh`, `uninstall-linux.sh` | `sudo manager/install/install-linux.sh` |
| macOS (development machine; user agent, no sudo) | `nl.remoment.manager.plist` (template), `install-macos.sh`, `uninstall-macos.sh` | `bash manager/install/install-macos.sh` (the scripts have no executable bit yet: a `chmod +x` was refused in the session that wrote them) |
| Compose only, no systemd | the note below | by hand |

**`install-linux.sh`** (options: `--project-dir`, `--compose-file`, `--compose-project`, `--home`, `--port`, `--user`, `--app-uid`, `--app-gid`, `--lan`, `--with-samba`, `--node`, `--dry-run`; `--help`). Idempotent: it writes a file only when its content changed, never replaces an existing token, and restarts the service only when something changed. It checks root, systemd, Node 18+, the checkout and (for a non-root user) the docker group; every value that ends up in a unit or env file is limited to `[A-Za-z0-9_./-]`, so nothing can add a line. What it makes:

- `/etc/systemd/system/remoment-manager.service` from the template, `User=` the chosen user (default **root**: SMB serve and NAS mounts need it; any user in the `docker` group is enough for containers, status and upgrade), `EnvironmentFile=` the two files below, `Restart=always`, `NoNewPrivileges`.
- `/etc/remoment-manager/manager.env`: the settings the installer owns (`REMOMENT_PROJECT_DIR`, `…COMPOSE_FILE`, `…COMPOSE_PROJECT`, `…MANAGER_HOME`, `…MANAGER_PORT`, `…MANAGER_BIND`, and for a root service with an app uid `REMOMENT_MANAGER_TOKEN_UID`, `REMOMENT_MOUNT_UID`, `REMOMENT_MOUNT_GID`), replaced on every run; `/etc/remoment-manager/local.env` is yours (SMB paths, `REMOMENT_SMB_FORCE_USER`, `REMOMENT_MOUNT_BASE`), created commented-out and **never touched again**.
- **The token**: `<home>/token` (default `/var/lib/remoment-manager/token`), 256 random bits from `/dev/urandom` straight into a mode-0600 file, only when absent; it is never printed and never a command argument. The home is 0700. **The app has to read it** (it mounts the manager's home read-only as `MANAGER_HOME`): a root service with `--app-uid` (default: the owner of the checkout, i.e. the user the app container runs as) makes the home 0755 and gives only the token file to that uid; the manager does the same on every start when `REMOMENT_MANAGER_TOKEN_UID` is set and it runs as root (`ensureToken`; ignored for a non-root manager), everything else in the home stays root-only. A non-root service owns the home itself, as under Ansible.
- `/usr/local/bin/remoment-manager` (link to the CLI) and `/etc/profile.d/remoment-manager.sh` (`REMOMENT_MANAGER_HOME`/`PORT` for the CLI).
- With `--with-samba` (needs root): installs Samba when `smbd` is missing (`apt-get` or `dnf`), creates the empty fragment `/etc/samba/remoment.conf` and appends `include = /etc/samba/remoment.conf` to the end of `smb.conf` once, after a one-time backup `smb.conf.remoment-backup`. It does not install `cifs-utils` (NAS mounts); `apt install cifs-utils` first.
- Then `daemon-reload`, `enable`, start or restart, and waits for `/health`. `--lan` binds `0.0.0.0` (plain HTTP, says so).

**`uninstall-linux.sh`** (`--home`, `--purge`, `--dry-run`): stops and disables the service, removes the unit, `manager.env`, the profile script and the CLI link; **keeps** the home (token, audit log, mount and share state) and `local.env` unless `--purge`, and **never touches** what the manager configured for others: the Samba fragment and include line and the NAS mount units and credentials stay and keep working (even `--purge` leaves `/etc/remoment-manager/credentials`, because the units point at it; it says so, and refuses `/etc`, `/var`, `/usr` ... as `--home`). Safe on a host where it was never installed.

**macOS.** `install-macos.sh` renders `nl.remoment.manager.plist` (a user LaunchAgent: node, `server.js`, the environment, `PATH` with Docker Desktop's directories, `KeepAlive`, log in `~/Library/Logs/remoment-manager.log`), validates it with `plutil -lint`, writes it only when changed and loads it for the current user; the manager makes its own token on first start in `~/.remoment-manager/token` (0600). `uninstall-macos.sh` unloads and removes it and keeps `~/.remoment-manager` unless `--purge`. SMB serve and NAS mounts are Linux features and have no use here.

**Compose-based variant (a note, not built).** The manager can in principle run as a container in the same compose project (a small Node image with the `docker` CLI, `/var/run/docker.sock` mounted, `network_mode: host`, the project directory mounted at the same path, its home as a volume the app reads read-only). That gives status, logs, restart, features and upgrade; it **cannot** do SMB serve or NAS mounts, which edit the host's Samba and systemd, and it cannot restart itself without a host-side restart policy. It also puts the Docker socket (root-equivalent) in a container on the same network as the app. It is therefore not offered: on a host with systemd use `install-linux.sh`; on a host without systemd start `node manager/server.js` from a process supervisor of your choice (the environment variables are the ones in `manager.env`); the user docs say the same.

## Still to do (open)

- Neither page has start/stop of single processes yet; the manager's own page has no SMB card (the CLI and Settings › Manager have); the manager's own page has no upgrade button (the CLI and Settings › Manager have). HTTPS for the LAN page (today: a reverse proxy).
- Processes inside the app container (supervisor: queue, scheduler, listeners), start/stop of single services.
- Host backup now, diagnostics bundle; registry images for `REMOMENT_UPGRADE_MODE=pull` (none published yet).
- SMB mount and SMB serve are built but unverified (see "NAS mounts" and "SMB shares"); still open: the Ansible role does not install Samba, `cifs-utils` or the include line (the shell installer does Samba only), per-share users, the `exports` share on installs without `EXPORT_SHARE_DIR` set in `.env`, the manager's own page has no cards for SMB or mounts, a debounce for a flapping NAS, and the NAS password only changing by adding the mount again (no "change password" button).
- Installers: the Linux and macOS scripts are built but unverified on a host; open: the Raspberry Pi image (preinstalled manager), a `.deb` or similar so the manager updates with the OS, and an installer for hosts without systemd. (The Ansible install exists: `ansible/manager.yml`, systemd unit from `ansible/templates/remoment-manager.service.j2`; syntax-checked only, not run against a host.)
- How the manager updates itself (decision c) and the init system per target (decision d).
- Go instead of Node, if the Pi measurements say so.

## Unverified

- SMB serve (all of it): Samba accepting the fragment (now with its own `[global]` section, included at the end of `smb.conf`), `testparm -s` on that fragment, `smbcontrol smbd reload-config` picking it up, `smbpasswd -a -s` reading two lines from stdin, `smbstatus --json` field names (`sessions`, `tcons`, `username`, `remote_machine`, `service`), a Mac/Windows/Linux client mounting the shares, the `force user` file ownership, hard links across the mounts (a copy is the fallback), systemd unit names other than `smbd` (`REMOMENT_SMB_SERVICE`), running the manager as root.

- Against a real compose stack: the field names of `docker compose ps --format json` (`ID`, `Service`, `State`, `Health`, `ExitCode`, NDJSON on current Compose, an array on older) and `docker stats`/`inspect` output are written from memory and the fake, not checked against a live Docker; the status tests only prove the parsing of that shape.
- A Pi (armv7, 1 GB): the cost of `docker stats --no-stream` per status call (about a second on a laptop) and Node's footprint.
- The installers (`manager/install/`): a real `systemctl enable --now`, the token hand-over with `chown` to the app uid (and that the app container can then read it through the read-only mount), `apt-get install samba`, the include line at the end of `smb.conf`, `launchctl bootstrap` of the plist, `plutil` accepting the plist on every macOS version, Node 18 on the target (Debian 13 ships 20), and the installer's checks on a host with a different `id`/`stat`.
- NAS mounts (all of it): see the end of "NAS mounts".
