# Hardware check: Raspberry Pi 2 and Pi 3

The real-hardware checks the roadmap leaves to the owner: workstream 1 (hardware checklist), 1a (SQLite for Control, the 24-hour stress test) and 9b item 4 (performance budget). One script measures what can be measured; the rest is a manual checklist.

> **Status: nothing here has run on a Pi.** The scripts were tested on a Mac only with `--dry-run` and `--self-test` (canned data through the report generator, plus the median/p95 helpers). The Docker, `vcgencmd`, `/proc` and stress-test steps are written from the docs and the command's source and are **unverified until the first real run**. Expect to fix the script on that run. The budgets are **proposals**.

Files: `scripts/hardware-check/run.sh` (collect), `report.sh` (turn results into `report.md` and `report.json`), `budgets.conf` (the proposed budgets). Reports go to `scripts/hardware-check/reports/` (git-ignored). Plain POSIX sh plus `curl`, `awk`, `df`, `docker`, and `vcgencmd` when present; nothing to install.

## 1. Prepare the Pi

Provisioning waits for the Manager (workstream 10); until then, do it by hand following [install-docker.md](../user/install-docker.md).

- **OS.** Pi 3: 64-bit Raspberry Pi OS Lite (arm64). Pi 2: only a 32-bit OS exists (armv7). Docker from the official convenience script or `docker.io`/`docker-compose-plugin`; check `docker compose version`.
- **Build natively on the Pi** (`docker compose -f docker-compose.prod.yml build`), never emulating another architecture ([frankenphp.md](frankenphp.md)). The first build takes long and a 1 GB board may need swap for it; note whether it finished.
- **`.env` essentials.**
  - `PROFILE=control` (or `standard`; `full` is not a Pi profile). One profile per run: change `.env`, restart the stack, run again.
  - `COMPOSE_PROFILES=` empty for Control; `db` for Standard (MariaDB).
  - Control: `DB_CONNECTION=sqlite`, `DB_DATABASE=/data/sqlite/database.sqlite` (the `remoment-sqlite` named volume; never a bind mount), `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`. See [profiles.md](profiles.md).
  - `APP_URL`, `MQTT_PUBLIC_HOST` set to the Pi's address.
- **The SD-card note.** An SD card is the weak spot: write wear and fsync latency are exactly what the SQLite test probes. Record the card (brand, size, A1/A2 rating). A USB SSD is the comparison if the card fails. The report records the root device type (`mmcblk` = SD card).
- **A realistic load.** The stress test simulates six listeners itself, but the roadmap gate says "with the real listeners also running": add real devices (or at least a few) and let `device:listen` run, so the app container does its own work during the test.
- **Quiet host.** Nothing else on the Pi; the idle sample is of the whole host (`MemTotal - MemAvailable`).
- **Cooling and power.** Use the official power supply: an undervoltage flag in `vcgencmd get_throttled` invalidates the run. A 24-hour run needs a session that survives your SSH connection: `tmux` or `nohup sh ... &`.

## 2. Run it

From the repository root on the Pi:

```bash
sh scripts/hardware-check/run.sh --dry-run --profile control     # read the plan first
sh scripts/hardware-check/run.sh --profile control               # 10-minute stress, everything else
sh scripts/hardware-check/run.sh --profile control --hours 24    # the real SQLite gate
sh scripts/hardware-check/run.sh --profile standard              # no stress (SQLite is Control only)
sh scripts/hardware-check/run.sh --self-test                     # any machine: report generator on canned data
```

Options: `--url` (default `http://localhost`), `--minutes N`, `--hours H`, `--out DIR`, `--skip-stress`, `--compose-file`, `--project-dir`. Env: `IDLE_SECONDS` (60), `SAMPLE_SECONDS` (30), `REQUESTS` (20), `APP_SERVICE` (`app`).

The steps, in order; a failing step is noted and the next one still runs:

| Step | Measures |
|---|---|
| (a) host | model (`/proc/device-tree/model`), architecture, kernel, RAM, swap, disk free, storage type, CPU temperature, `vcgencmd get_throttled` |
| (b) stack | `docker compose ps`, architecture of every image, `docker stats --no-stream`, the active profile from `GET /api/info` |
| (c) idle memory | host memory in use, every 5 s for 60 s: average and peak |
| (d) web | 20 sequential requests (after a warm-up) to `/api/info`, `/api/devices`, `/devices` and, outside Control, `/library` and `/api/library/albums`: median and p95; then 3 bursts of 10 concurrent requests to `/devices` with memory sampled, peak recorded |
| (e) stress | Control only: `php artisan profile:stress-sqlite --minutes=N --listeners=6` in the `app` container; memory and temperature every 30 s; verdict (`PASS`/`FAIL`) and the `database is locked` count read from its output. Refused with a message for Standard and Full |
| (f) logs | `database is locked`, `OOM`, `Killed`, `MaxAttemptsExceeded`, `Connection refused` in the container logs since the run started |

Output folder: `results.env` (every number), raw `*.txt`, `*.samples`, `*.times`, `report.md`, `report.json`. Edit `budgets.conf` and run `sh scripts/hardware-check/report.sh <folder>` to re-label without re-measuring.

Note: the stress command works on its own temporary SQLite file and refuses the configured database, so it does not touch the install's data. It does load the Pi, so the web timings are taken before it, not during.

## 3. Read the report

Every row is `pass`, `FAIL`, `warn` (a short stress run), `not measured` (the tool was missing: no `vcgencmd`, no docker) or an info row. Proposed budgets (`budgets.conf`, **proposals**, nobody has measured a Pi):

| Number | Proposed budget |
|---|---|
| Idle memory in use, Control, 1 GB board | < 600 MB |
| Idle memory in use, Standard, 1 GB board | < 750 MB |
| Web p95, warm, `/devices` (the other pages and API calls use the same number) | < 2000 ms |
| Memory peak in a burst of 10 concurrent requests | < 85 % of RAM |
| `database is locked` (stress output and logs) | 0 |
| `OOM`, `Killed`, `MaxAttemptsExceeded`, `Connection refused` in logs | 0 |
| CPU temperature | < 80 C |
| `vcgencmd get_throttled` | `0x0` |
| Stress duration for the gate | 1440 min |

## 4. What each number decides

| Result | Decides |
|---|---|
| **Stress `PASS` at 1440 min on a Pi 2** with zero locks | SQLite Control is supported on the Pi 2. A `FAIL` means the Pi 2 is not supported, not flaky (roadmap 1a, condition 4) |
| Stress on a Pi 3 only | the Control floor is the Pi 3; run both, the answer per board is the point |
| Idle and burst memory per profile | the Pi budget in 9b item 4 and the numbers in [system-requirements.md](system-requirements.md) (currently estimates); whether Standard fits a 1 GB Pi 3; whether to lower `FRANKENPHP_THREADS` ([frankenphp.md](frankenphp.md)) |
| Web p95 | the web-server choice (FrankenPHP classic against `artisan serve` and nginx plus php-fpm, 9a/9b); for the comparison, run the script once per server setup and keep the folders |
| Image architectures `arm/v7` for app, redis, mosquitto and a started stack on the Pi 2 | whether the armv7 images work. If any image is missing, the Control floor is the Pi 3 (roadmap 1a). Note which image failed and how |
| Temperature and throttling | whether a case, heatsink or better supply is a requirement for the docs |

## 5. Manual checklists (not scripted)

### 5.1 Device brands

Work through [refactor-hardware-check.md](refactor-hardware-check.md) (listeners, discovery, library playback, volume, multiroom, settings, API behaviour, artwork, web UI, MQTT, history, queue editing) on the device lab, GET-only where possible; the BeoPlay V1 stays in standby (read-only checks, `php artisan ase:map <ip>`). Mozart: [mozart-hardware-test.md](mozart-hardware-test.md). Ideally repeat sections 1 and 2 of that list with the **Pi** as the server: SSDP/mDNS discovery on a Pi with host networking is a different network path than the Mac.

### 5.2 Home Assistant package

Never loaded in a real Home Assistant ([home-assistant.md](home-assistant.md)).

- [ ] `php artisan ha:package --base-url=http://<server>` writes the YAML.
- [ ] Put it in HA's `packages/` (with `homeassistant: packages: !include_dir_named packages`), restart or reload; no configuration errors.
- [ ] Call two commands from Developer Tools > Actions (for example a pause and a volume set on a device you can afford to touch); the device reacts and HA shows no error.
- [ ] Write down the HA version, the commands tried and what the server logged.

### 5.3 Development environment

- [ ] The dev `.env` needs `PROFILE=full` to see sections and stems (the default is `standard`, and analysis, structure and stems are Full-only); restart the dev containers after changing it.

### 5.4 Bring-up questions only a Pi answers

- [ ] The image build finished on the Pi 2 and Pi 3 (time, swap used).
- [ ] The stack starts with `PROFILE=control` (app, redis, mosquitto; no MariaDB), `/api/info` answers `features.profile: "control"`, and a gated URL answers 422 `not_available_in_profile`.
- [ ] `backup:run` works on the SD-card install and `backup:restore` restores it on a fresh `migrate` ([backup-restore.md](backup-restore.md)).
- [ ] A power cut with the stack running: the SQLite file opens afterwards (`PRAGMA integrity_check`).

## 6. Record the results

Per run, keep the report folder name (host, profile, date) and fill in below. Per device model (feeds the planned `verified` field of the hardware table in `config/devices.php` / the hardware list; that field does not exist yet).

| Device model | Date | Checked on | Listener | Discovery | Playback | Volume | Multiroom | Queue | Notes / `verified`? |
|---|---|---|---|---|---|---|---|---|---|
| | | | | | | | | | |

Per Pi:

| Board | OS / arch | Storage | Profile | Idle MB | `/devices` p95 | Burst peak MB | Stress (min, locks, verdict) | Max temp / throttled | Report folder |
|---|---|---|---|---|---|---|---|---|---|
| Pi 2 | | | | | | | | | |
| Pi 3 | | | | | | | | | |

## Not verified

- Everything in `run.sh` that touches a Pi: `/proc/device-tree/model`, `vcgencmd` output parsing, storage-type detection, `docker compose ps --format` and `docker image inspect` fields (older Compose versions may lack `--format`), the parsing of the stress command's table (`database is locked` row) and its `PASS`/`FAIL` line, container log scanning (`docker compose logs --since`), and the sampling of fractional `sleep` in the burst. Only the report generator and the median/p95/average helpers were tested.
- That the budgets are sensible. They are a first guess.
- A 24-hour run: the collection steps have never run, even briefly; a `docker compose exec` that drops after hours is a risk (the stress command runs in the container; if the exec dies, the verdict is missing and the report says "not measured").
