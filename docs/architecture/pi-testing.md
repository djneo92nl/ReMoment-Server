# Pi test plan: the node and the server profiles

Status: **plan for the owner's hardware run (2026-11-22).** Nothing here has been run. It extends [hardware-check.md](hardware-check.md) (the scripted host/stack/stress measurements and the per-brand checklists) with two things it does not cover: the **ReMoment Node** on a Pi, and a **feature-by-feature pass per profile** on a Pi server. Every result goes in section 6, and every claim in the docs that this plan proves gets its `unverified` removed (`config/hardware-status.php` for devices).

Rules for the run: GET-only against real B&O devices, never run the 24-hour stress on a device you need, one section at a time, write down the Pi model, OS image, RAM and the commit (`git rev-parse --short HEAD`) first.

## 0. Prepare

| Item | Needed for |
|---|---|
| Pi 2 or 3 (1 GB, armv7) and a Pi 4/5 (arm64) if you have one: the answer per board is the point | everything |
| Raspberry Pi OS Lite (Bookworm), 32-bit for the Pi 2/3, 64-bit for the Pi 4/5; SD card class A1 or an SSD | everything |
| The `.deb` of the node: `remoment-node_*_armhf.deb` / `_arm64.deb` from the `node.yml` run (artifact `remoment-node-deb`) | section 1 |
| A server to pair with (the dev server on the Mac works: `devdash up`, reachable on the LAN at port 8080) | section 1 |
| USB sound card or USB turntable interface, `ffmpeg` | 1.3 line-in |
| USB CD drive, an audio CD with a known MusicBrainz release, `cyanrip` | 1.4 ripper |
| A USB stick formatted FAT32 (and one exFAT) | 1.5 sync target |
| A speaker that plays an MP3 stream (a Sonos, a UPnP renderer, a Beosound) | 1.3, 1.5 |

## 1. The node (`remoment-node`, workstream 18)

Pass criteria are in the last column. Record anything that does not match.

### 1.1 Install and pairing

| # | Step | Pass when |
|---|---|---|
| 1 | `sudo apt install ./remoment-node_*_armhf.deb` | installs without errors; user `remoment` exists in groups `audio` and `cdrom`; `systemctl is-enabled remoment-node` is `enabled`, `is-active` is `inactive` |
| 2 | `dpkg-deb --info` and `dpkg -L remoment-node` | binary in `/usr/bin`, unit and `*.conf.disabled` drop-ins in `/lib/systemd/system`, conffile `/etc/remoment-node/config.json` |
| 3 | `"server": ""` in the config, `sudo systemctl start remoment-node`, `journalctl -u remoment-node -f` | the node finds the server by mDNS (`_remoment._tcp`, TXT `path=/api`), logs a **pairing code**, saves the address in `/var/lib/remoment-node/server` |
| 4 | Same with `"server": "http://<ip>:8080"` | the configured address wins, no browse |
| 5 | Approve the code in Settings > Clients | the node picks up its token by itself; the token file is mode 0600 in a 0700 directory |
| 6 | Node card in Settings > Clients | shows the `node` badge, the 30 s heartbeat, version and declared roles |
| 7 | `sudo systemctl stop remoment-node`, wait 2 minutes | the `node.offline` alert is raised (Settings > Health, MQTT `remoment/alerts/node.offline`); it resolves when the node returns |
| 8 | Revoke the token in Settings > Clients | the node stops with a clear log line and does not hammer the server |
| 9 | `apt install` of a newer `.deb` (or the same version again) | config kept, a running node is restarted; `apt remove` stops it and keeps token and config, `apt purge` removes them |

Measure: idle RSS (`systemctl status`, `ps -o rss`), CPU while idle, binary size, time from boot to first heartbeat. Targets (from [node-agent.md](node-agent.md)): a few tens of MB resident, under 64 MB unit limit.

### 1.2 The job channel

| # | Step | Pass when |
|---|---|---|
| 1 | Admin job `ping` (`POST /api/admin/nodes/{id}/jobs`, or MCP) | accepted, result returned in seconds; the MQTT nudge `remoment/node/{id}/cmd/job` arrives (`mosquitto_sub`) |
| 2 | Stop the broker, send a job | the node still takes it on its next poll (REST is authoritative, MQTT only a doorbell) |
| 3 | Send the same job twice (same idempotency key) | executed once |
| 4 | Kill the node during a job | the job goes `lost` after its lease; no duplicate on restart |

### 1.3 Line-in (`roles: ["line-in"]`, [line-in.md](line-in.md))

Config: `"line_in": {"device": "hw:1,0", ...}`, enable the drop-in `line-in.conf.disabled` (`PrivateDevices` is off in it), switch the role on in Settings > Clients.

| # | Step | Pass when |
|---|---|---|
| 1 | `arecord -l` | the USB card is listed; the `remoment` user can open it (group `audio`) |
| 2 | `GET /api/line-ins` | the node is listed |
| 3 | `POST /api/devices/{id}/line-in` on a `stream_url` speaker | sound from the source plays on the speaker; record the **latency** (target 2 to 5 s) |
| 4 | Watch `top` for 10 minutes | ffmpeg stays under about 30 % of one core on a Pi 3; no dropouts (`journalctl`) |
| 5 | Stop playback, wait `LINE_IN_IDLE_SECONDS` | ffmpeg ends, the Icecast mount `linein-{node}.mp3` is gone |
| 6 | Unplug the card while running | the node reports a failure, does not loop; plug back, the next start works |

Open questions this answers: Icecast per-mount source credentials in the current `docker/icecast` config, whether the mount survives a dropped source, USB audio glitches on a Pi 2.

### 1.4 Ripper (`roles: ["ripper"]`, [node-ripper.md](node-ripper.md))

First run `cyanrip -h` and compare with the flags in `node/ripper.go` (`-d -o -N -s -r -a -t`): they were written from the documentation, not from a run. Enable the `ripper.conf.disabled` drop-in (384 MB), declare the role and the drive (`/dev/sr0`, `offset` from AccurateRip's drive list).

| # | Step | Pass when |
|---|---|---|
| 1 | `GET /api/nodes/rippers` with no disc | the node is listed, disc state "no disc" |
| 2 | Insert the audio CD, run a `ripper.status` check (the "Rip this disc" card on `/library/collection`) | the TOC is read, the MusicBrainz disc id matches `discid` / the MusicBrainz site, candidate releases are listed |
| 3 | Choose the release, start the rip | per-track progress shows in the card and the task tray; temp dir fills under `rips/`; `nice`/`ionice` applied |
| 4 | Wait for the end | FLAC files are in the library with the tags (`MUSICBRAINZ_ALBUMID` etc. in the file: check with `metaflac --list`), the album exists, a matching physical item is marked **ripped** and linked |
| 5 | Open the album, play a track | plays; AccurateRip result in the cyanrip log looks sane |
| 6 | Cancel a rip halfway | cyanrip is killed, temp dir removed, job `cancelled` |
| 7 | Pull the network cable halfway through the upload | uploaded files are tracked in the marker file; reconnect, the rest uploads; no duplicates (identical bytes are kept once) |
| 8 | Fail a rip on purpose (scratched disc) | `rip.failed` alert |

Record: time per disc on a Pi 2/3, peak RSS, CPU temperature while ripping, whether `cyanrip` exists as an armv7 package.

### 1.5 Sync target (`roles: ["sync-target"]`, [offline-sync.md](offline-sync.md), [../api/sync.md](../api/sync.md))

Needs a drop-in with `ReadWritePaths=/media/usb/STICK` (your mount point) and the role declared with that path.

| # | Step | Pass when |
|---|---|---|
| 1 | Create a target in Tools > Sync (a small playlist, 500 MB budget, MP3 profile), **Estimate** | the number of files and bytes is plausible; no files are written |
| 2 | **Claim** the stick | a marker file is written; the server stores the stick id |
| 3 | **Run** the sync | files appear with FAT32-safe names, the manifest is written last; progress in the task tray; the node stops cleanly on low space |
| 4 | Run again | no changes (the plan is empty) |
| 5 | Remove a track from the playlist, edit a tag on the stick by hand, run again | the removed file is deleted; a hand-edited file is left alone (released) |
| 6 | Try to sync to a stick claimed by another server | refused |
| 7 | Pull the stick mid-run | the manifest on the stick is still consistent (temp files only), the job fails with an alert |
| 8 | Plug the stick into a car radio / player | it plays the files in order |
| 9 | Plan time and memory for a large library | note the time the plan and the artifact preparation take on the server (the transcode cost per album on a Pi server is the open number) |

Run once on FAT32 and once on exFAT.

## 2. The server on a Pi: one pass per profile

Use [hardware-check.md](hardware-check.md) section 2 for the scripted numbers (idle memory, web p95, stress, logs), then this checklist for what **works**. Switch profile with `PROFILE=` in `.env` and recreate the containers (`profile:upgrade` for Control to Standard); a feature outside the profile must answer `422 not_available_in_profile` (API) or redirect to `/not-in-profile` (web), and its scheduler task and worker must not run (`supervisorctl status`, `php artisan schedule:list`).

Legend per cell: record **ok / slow / broken / n.a.** and a note.

| Area | Check | Control | Standard | Full |
|---|---|---|---|---|
| Boot | stack up from cold, `GET /api/info` answers; `features.profile` matches | | | |
| Devices | discovery finds a real device, listener starts, state in `/devices`, MQTT `remoment/player/{id}/state` | | | |
| Playback | play/pause/next, volume, mute, seek on one device per brand you own | | | |
| Live UI | `/devices` updates by MQTT push; kill the broker, the UI falls back to polling | | | |
| Radio | play a station, ICY title shows; a relayed station (`radio:relay`, profile `radio-relay`) | | | |
| History and stats | a play is stored, `/history` and `/stats` load | | | |
| Alerts | stop the queue worker, the stuck-queue alert fires and resolves | | | |
| Backup | `backup:run`, `backup:restore` on a fresh `migrate` (never yet run against a real MariaDB 11.4) | | | |
| Scenes | create, run by hand, a schedule fires, an MQTT/event trigger fires (`scenes:listen` running) | | | |
| Sub devices | link the Chromecast to the V1's HDMI 1, cast, the host switches and wakes | | | |
| Sleep timer, alarm | fade, stop and volume restore; alarm ramp | | | |
| Home Assistant | discovery on, entities show in HA; command path (`ha:commands on`, `ha:listen`) runs a scene from an HA button | | | |
| PWA, push | install the web app; alert notification; Web Push on Android and on iOS (installed app) | | | |
| Library | add a storage location, scan files, `/library` browses; time the first scan | n.a. | | |
| Enrichment | MusicBrainz, cover art, lyrics arrive; rate limits hold | n.a. | | |
| Artwork | covers process, no baseline/progressive problem on an ESP32 client | n.a. | | |
| Catalogue | `catalogue:sync` for one artist, discography, wishlist, new releases | n.a. | | |
| Physical, CSV import | import a CSV, barcode lookups trickle in | n.a. | | |
| Podcasts | subscribe to a feed, play an episode on a speaker, resume after pause | n.a. | | |
| Tag editor | edit tags of one file, undo | n.a. | | |
| Export, sync | export an album (MP3 320, AAC); the Pi 3 time for one album | n.a. | | |
| Similar, Discover | Discover hub loads, similar tracks need embeddings (Full only) | n.a. | | n.a. unless analysis runs |
| Analysis | `library:analyze` on 20 files (loudness, fingerprint); Essentia sidecar is **x86-64 only**: expect it not to start on arm64 | n.a. | n.a. | note the failure mode |
| Deep analysis | sections, stems (weights are an opt-in download), mood | n.a. | n.a. | likely n.a. on a Pi |
| Manager | install `manager/install` (systemd), upgrade dry run, NAS mount dry run, SMB share on a real Samba host | | | |
| Diagnostics | `diagnostics:bundle`: no IPs, no secrets in the zip | | | |
| Upgrade | `app:upgrade --check`, then a real upgrade and a rollback | | | |

Also record per profile after 30 minutes of normal use: memory in use, load average, CPU temperature, `vcgencmd get_throttled`, and the failed-jobs count.

## 3. Where the admin gate matters on the Pi

The API is open by default; only what could be overwritten or broken by accident needs the admin bearer token. While testing, confirm with `curl` (no token, then with `POST /api/admin/login`):

| Request | Without token | With token |
|---|---|---|
| `GET /api/devices`, play/pause/volume, library browse, scenes, podcasts list | 200 | 200 |
| `PUT /api/devices/{id}/info` (rename) | 200 (open, decided 2026-10-26) | 200 |
| `PUT /api/devices/{id}/sources/{source_id}` (hide, reorder), `POST .../sources/activate`, sound adjustment, Bluetooth | 200 (open, 2026-11-22) | 200 |
| `POST/PUT/DELETE /api/admin/devices*` (add, edit address, delete), `PUT /api/admin/devices/{id}/volume-limits` | 401 | 200 |
| `GET /api/devices/{id}/network`, `PUT .../network/*` | **401** | 200 |
| `/api/admin/*` (tokens, clients, health, queues, storage, library settings, rips, sync, backup copy path, HA package) | 401 | 200 |
| `POST /api/nodes/*` | 401 without the node's own Bearer token; an admin token must not work here either | node token: 200 |

The rule is in [../api/admin-auth.md](../api/admin-auth.md): public unless it can break the connection. No known client changes the network settings; note any that breaks on a 401 here.

## 4. What each result decides

| Result | Decides |
|---|---|
| Node idle memory and armv7 behaviour | whether the Go node is the right base for a Pi 2, and the unit's memory limits |
| Line-in latency and glitches | whether line-in is offered for the Pi 2 or only Pi 3 and up |
| cyanrip armv7 and rip time | whether the ripper role is documented for armhf or arm64 only |
| Stick sync on FAT32 vs exFAT, plan time | the default export profile and the recommended budget, the Sync v1 limits |
| Per-profile table above | the supported-hardware table (`config/hardware-status.php`), the profile advice in [system-requirements.md](system-requirements.md), the performance budget of 9b |
| Failed Essentia/stems start on arm64 | confirms Full is x86-64 only; keep the Dockerfile fail-fast guard |

## 5. Known gaps you will hit (not bugs to report)

- The analysis sidecar has no arm64 or armv7 wheels (see [audio-analysis.md](audio-analysis.md)).
- The node has no eject helper, no `sync.probe`/`sync.verify`, and no self-update.
- `device:listen` and the queue worker need a restart after a deploy; `scenes:listen` and `ha:listen` are separate supervisor programs gated by profile.
- Ansible `profile: full` enables the analysis sidecar on arm64 without checking the architecture.

## 6. Record the results

Copy this block per board and profile.

| Field | Value |
|---|---|
| Board, RAM, OS, kernel | |
| Commit, profile, date | |
| Storage (SD class / SSD) | |
| Node idle RSS / CPU | |
| Section 1 failures (step numbers) | |
| Section 2 failures (area, profile) | |
| Idle memory per profile, web p95 | |
| Temperature, `get_throttled` | |
| Gate check (section 3) surprises | |
| Decisions it supports (section 4) | |
