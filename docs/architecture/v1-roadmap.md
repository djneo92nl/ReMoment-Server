# v1.0 scope and roadmap

Status: **plan.** Everything below is in scope for 1.0. This document orders it, says what depends on what, and defines "done" per item. Detail lives in the per-system docs it links to; this is the map.

Effort: S = days, M = 1–2 weeks, L = several weeks. Feasibility notes come from reading the repo and a first look at the external APIs; anything marked *unverified* needs a check before building.

## Overview

| # | Workstream | Effort | Depends on | State | Priority |
|---|---|---|---|---|---|
| 1 | Stability: cards disappearing, hardware checklist, Spotify API audit, backup/restore | M | | **mostly built** (cards fix, Spotify audit, backup); hardware checklist and alerts to do | Must |
| 1a | Profiles: Control / Standard / Full, chosen in the setup wizard | M | 1 | design below | Must |
| 1b | Queue stability and management: are the runners stable, per-queue health, pause/cancel/prioritise jobs | M | 1 | partly built; review below | Must (stability), Should (management) |
| 2 | TV control | M | | **phase 1 built** (Beoplay V1 page, wake); keys and Philips to do | Must |
| 2a | Sub devices (devices connected to other devices: converter, Chromecast on a TV, changers) | M | 2 (first case) | design below | Should |
| 3 | Availability layer (external libraries add to the collection) | L | | design decision first | Must |
| 4 | Discovery | M | 3 (partly) | to do | Should |
| 5 | Deezer and Tidal | L | 3, hardware probes | design in [streaming-services.md](streaming-services.md) | Should |
| 6 | Physical library (CDs, tapes, vinyl) | M | 3 | to do | Should |
| 7 | CD import (ripping) | M | 6 (links to items) | **started**: desktop ripper app (sign-in, upload) and the upload API exist; ripping itself to do | Can slip |
| 8 | Additional features: scenes/automations, podcasts, audiobook resume, tag editor, PWA + alerts, playback history | M–L | varies | to do | Mixed (per item) |
| 8a | Home Assistant: MQTT discovery and automations built in HA | M | 8 (scenes, alerts), 2a (TV events) | plan in [home-assistant.md](home-assistant.md) | Should |
| 8b | Mobile app support: admin API for apps, one-library playlists everywhere, contract gaps, player housekeeping, alerts feed | M–L | 8.6, 8.7 | plan below; the app side is in `~/Code/ReMomentiOS/docs/roadmap.md` | Must (contract items), Should (rest) |
| 9 | Release: docs, OpenAPI, MCP, upgrade path, versioning | S | all | to do | Must |
| 9a | Distribution and upgrades: registry images, compose, Ansible, Pi image, `app:upgrade`, migration squash | L | 1a | design below | Must |
| 9b | Release readiness: CI, end-user docs, network guardrails, performance budget, diagnostics bundle | M | 1a | design below | Must (diagnostics: Should) |
| 10 | Manager: host service that controls the running instance (containers, processes, upgrade with rollback, host stats) and SMB shares (serve and mount) for the library and the drop directory; on every install target | L | 1b, 9a, backup | **decided, design draft** | Must |

## Progress (as of 2026-10-27)

Built and committed since this plan was written (hashes are on `main`):

| Item | What | Commit |
|---|---|---|
| 1 Cards | One cause of vanishing cards fixed: a `<style>` before the card's `<div>` became the Livewire root. **Not yet confirmed** by an hour-long run; other suspects (MQTT refresh, Spotify routing, state flapping) could not be reproduced | `316b979` |
| 1 Spotify audit | Every call listed in [spotify-api-audit.md](spotify-api-audit.md); likes and playlist import moved to the generic endpoints; removed endpoints degrade to "no data". Response shapes **unverified** on a real post-February app | `01932fa` |
| 1 / 9b Spotify keys | Settings card for the user's own Client ID and secret, plus [spotify-app-setup.md](spotify-app-setup.md) | `c39b118` |
| 1 Backup and restore | `backup:run` / `backup:restore`, nightly schedule with retention, health warning; no secrets by default. **Never run against real MariaDB 11.4**; see [backup-restore.md](backup-restore.md) | `a626f9b` |
| 2 TV | Beoplay V1 page, wake by source, number keys | `5d2dab0` |
| 2a Cast | CASTV2 codec, receiver connection, read-only `cast:probe` | `0ff1c58` |
| 7 Ripper | `ripper/`: Tauri desktop app, signs in with the admin token, MusicBrainz disc lookup, tags files, uploads through `POST /api/admin/files`; ripping itself still to do | `d0e24af`, `6ca86f3` |
| Not in the plan | Move playback and sync groups (fake multiroom); BeoLink stream (B&O multiroom as an Icecast stream for any speaker) | `5b12aec`, `d89bc44` |

Still open on workstream 1: the hardware checklist (needs the device lab), health alerts (need the MQTT alert topic of 8.6), and confirming the card fix over time.

**Priority.** *Must* = 1.0 does not ship without it. *Should* = in 1.0 unless time runs out, cut in the order listed under "Cut order". *Can slip* = moves to 1.1 without hurting 1.0. Per-item tags for workstream 8 are on the items below.

**Cut order** (first to go): 8.2 podcasts, 8.4 tag editor, 8.3 resume position, the HA command path (8a), 7 CD import, 6 physical library, 5 Tidal (Deezer stays if verified), 4 similar tracks. 8b's admin endpoints can ship area by area; its contract items (2, 3, 6) are Must because the app and the `features` list depend on them.

Suggested order: 1 and 2 in parallel, with 1a (profiles) right behind 1 because the stack, the scheduler and the budget of 9b depend on it (independent; 2a follows the first TV work), then 3, then 4, 5 and 6 in parallel, then 7, 8 (with 8a after its scenes and alerts), 8b alongside 8 (its contract items first: they block the app and cost little), and 9, 9a and 9b last (the migration squash is the very last step before the release tag). Workstream 3 is the one that shapes the rest, so it is decided first.

---

## 1. Stability

**Goal:** a release nobody has to babysit.

- **Cards disappearing** *(one cause fixed, `316b979`; confirmation pending)*. Reproduce first. Suspects: the Livewire `$wire.$refresh()` triggered by MQTT push re-rendering while a keyed list changes (`remoment-live.js`), the device-list filtering that hides the Spotify device while it is routed (`SpotifyRouting`), and state flapping to `unreachable` between listener restarts. Add a regression test per cause found (a Livewire test for the list, a unit test for the filter). Done when: a device card never vanishes through a state change, a routing change or a listener restart, over an hour with several devices.
- **Hardware checklist** *(not started)*. Work through `refactor-hardware-check.md` on the device lab (GET-only where possible) and record results.
- **Spotify API audit** *(done, `01932fa`, `c39b118`; see [spotify-api-audit.md](spotify-api-audit.md))*. In February 2026 Spotify moved Development Mode to Premium-only, one Client ID per developer, 5 users per ID and a reduced endpoint set, and reportedly removed several endpoints (new releases, artist top tracks, some album metadata); removal for existing apps was postponed ([migration guide](https://developer.spotify.com/documentation/web-api/tutorials/february-2026-migration-guide)). List every Spotify endpoint the server calls, check each against the guide, and replace or guard those at risk (the library endpoints are now generic and URI-based). Done when: no call depends on a removed endpoint, and a removed one degrades to "no data", never an error page.
- **Backup and restore** *(built, `a626f9b`; see [backup-restore.md](backup-restore.md); still to do: restore on a scratch MariaDB, copy archives off the machine)*. The database moved to MariaDB and has a corruption history (`storage/db-recovery/`). Add `backup:run` (database dump + settings + the artwork-independent state: playlists, favourites, plays, device and client config, analysis results) to a configurable location, scheduled, with retention; and `backup:restore` that works on a fresh install. Own audio files are not included (they are the user's files; document how to back them up). Done when: a restore on a clean container reproduces devices, library, playlists, favourites, history and settings.
- **Health** *(not started; blocked on 8.6 phase 1)*. Alerts for the failure cases `/settings/health` already detects (device offline, queue stuck, scheduler silent, storage unreachable) through the notification path in workstream 8.

## 1a. Profiles (Control / Standard / Full)

**Goal.** One codebase that runs on a Pi 2 as a device controller and on a mini PC as the full media server, with the user choosing, and the stack honest about what it runs. Decided 2026-10-26.

| Profile | For | Runs | Hardware | Database |
|---|---|---|---|---|
| **Control** | Wall panels (ESP32), a few devices | Device listeners, REST API, MQTT, live web UI, radio, play history | Pi 2 and up | **SQLite** |
| **Standard** | Control plus a library | Adds library scan, enrichment, artwork, Spotify/DLNA, own-file indexing, MariaDB search | Pi 3 and up | MariaDB |
| **Full** | Everything | Adds audio analysis and the ML sidecar, discovery sync, rip upload, optional Meilisearch | the Recommended and Essentia tiers of [system-requirements.md](system-requirements.md) | MariaDB |

- **A real switch.** A setting (and `.env` value) picked in the setup wizard that turns off the listeners, scheduled jobs and queues a profile does not need. Jobs like the five-minute library cache warm and the nightly analysis must not run in Control. Compose profiles keep the heavy containers (analysis, icecast, Meilisearch) out of the lower profiles.
- **Reported to clients.** `GET /api/info` `features` (workstream 8.7) includes the active profile.
- **SQLite for Control: conditions.** SQLite failed in 2026-10 (lock contention and bind-mount corruption, see [database.md](database.md)); Control only works if (1) WAL mode and a `busy_timeout`, on a native or named volume, never a bind mount; (2) Redis keeps queue, cache and sessions; (3) play history is written by a single worker; (4) a **24-hour stress test** with real listeners counts `database is locked` and gates the decision; if it fails, the Pi 2 is not supported rather than flaky; (5) the backup of workstream 1 covers an SD-card install.
- **Upgrading a profile.** Control to Standard is SQLite to MariaDB: wrap the existing `db:import-sqlite` in a `profile:upgrade` command (backup, switch `.env`, migrate, import).
- **Meilisearch** is removed from `docker-compose.prod.yml` for Control and Standard (it costs hundreds of MB); Full may enable it as an optional compose profile. Whether search is built on it is a separate decision; Standard uses MariaDB search.
- **Pi 2 is 32-bit armv7.** Every image in the Control stack (app, Redis, Mosquitto) needs an armv7 build; *unverified*. If one is missing, the Control floor is the Pi 3 (arm64).

Done when: the wizard offers the three profiles, each starts only what it should, a Pi 2 and a Pi 3 meet the budget of 9b for their profile, and moving Control to Standard keeps the data.

## 1b. Queue stability and management

**Goal.** The queue runners keep running without attention, and the admin can see and steer what is in them.

**What exists today** (read from the repo, not from a running install):
- Supervisor (`docker/8.4/supervisord.prod.conf`) runs `queue` (`QUEUE_WORKERS` workers on `health,default`, `--tries=3 --max-time=3600`), `storage-queue` (scans and imports, `--timeout=3600`), `analysis-queue` (`nice -n 15`, `--timeout=900`), the `scheduler` and the `listeners` (`device:listen`), all with `autorestart` and retries without limit so a Redis that is not up yet does not leave a program stuck in FATAL.
- Redis is the queue driver in production; `retry_after` is 3700 s, above the longest job timeout (a comment records the earlier `MaxAttemptsExceededException` this fixed).
- `/settings/health` has a Queue tab (`QueueManager`, `Domain/Health/QueueInspector`): pending jobs by class (waiting, running, delayed), failed jobs with retry, forget and clear, a worker restart (`queue:restart`) and re-queueing missing enrichment.

**Review (a session can do this from the configuration; the soak part needs a real run):**
1. Worker settings against the Pi budget: no `--memory` limit set (the default of 128 MB applies), `QUEUE_WORKERS` default on a 1 GB board, `--max-time` and `--max-jobs` so a leaking job class cannot grow a worker for an hour.
2. Timeouts and `retry_after` for every queue, including any job class with its own `$timeout` longer than its queue's `--timeout`.
3. Failure behaviour: which job classes retry forever, which are idempotent, what a failed job leaves behind (a half-imported file, a stuck "running" marker), and `failed_jobs` growth.
4. Heartbeats: `health:queue` is one timestamp; per-queue worker heartbeats and the age of the oldest waiting job per queue are what actually show a stuck `analysis` or `storage` runner.
5. A **soak test**: a 24-hour run with real listeners and a busy library scan (the same run as the SQLite stress test of 1a), counting failed jobs, restarts, memory per worker and the oldest-waiting age.

**Management additions:** pause and resume one queue, cancel a pending job, move a job class to another queue or change its priority, per-queue concurrency (`storage` and `analysis` stay at one), a live view of what each worker runs now and for how long, and the failed-job list with the exception and the payload's entity (the track or file) instead of the class name alone. Alerts (workstream 8.6) for "queue X has not progressed for N minutes". All of it behind the admin login and, for apps, the admin API of 8b.

Done when: the review's findings are fixed or written down, a 24-hour soak shows no stuck queue and no unexplained failed job, per-queue health shows on `/settings/health` and in `/api/info` `features`/alerts, and an admin can pause a queue and cancel a job without a shell.

## 2. TV control

The B&O design and what is built are in [beovision-tv.md](beovision-tv.md); the generic TV model and the Philips plan are in [tv-devices.md](tv-devices.md). Scope for 1.0:

1. **Remote keys.** `POST /api/devices/{id}/keys` (`{ key }`, 422 for a key outside `supportedKeys()`), OpenAPI, MCP tool, and a remote card on the device page for devices with `remote_control`. For B&O the transport is known: B&O's one-way commands (`POST BeoZone/Zone/Stream/Cursor/Select`, `Menu/Root`, `Generic/Red`, ...), mapped from `RemoteKey` in beovision-tv.md. **Unverified on the V1 firmware**: a hardware probe (someone at the TV) comes first and decides `supportedKeys()`.
2. **Philips Android TV**: map `RemoteKey` to Jointspace key names through `POST /6/input/key`; `supportedKeys()` from what the firmware answers. HDMI input switching is *unverified* on current firmware (investigate `Source` + cursor/OK, and `activities/launch` intents).
3. **BeoVision / Beoplay V1: done in phase 1** (commit `5d2dab0`): the V1 is a model with its listener; sources carry `input` and `signal`; the device page has Input and Apps tiles, number keys, sleep timer and a Power on button. A Mozart-platform BeoVision cannot be probed, so it is *unverified* and **out of 1.0 unless a unit becomes available** (Mozart's B&O products are soundbars; their TVs run webOS, a later driver). `ConverterDigitTransport` is the precedent for keys through another device.
4. **Inputs in the UI: done for ASE TVs** (names, icons, `input` label, signal dot, default input on wake through `SwitchToDefaultSource`). Philips has apps only until its HDMI switching is solved (item 2).
5. **Wake: done for B&O TVs**: sending a source turns the TV on, so `powerOn()` activates the default or first visible source (verified by the user on the V1, not yet exercised by an automated hardware run). Wake-on-LAN from a stored MAC, or CEC through another device, is still open for the Philips TV.

Done when: the Philips TV and the Beoplay V1 can be controlled from the web page, the API and MCP, with power, volume, inputs or apps, and remote keys, and what a model cannot do is not offered (capability, not brand). **Left to reach it:** items 1 and 2, and a probe of which one-way commands the V1 accepts.

## 2a. Sub devices

**Concept.** A **sub device** is a device in its own right (a player or something we can control, with its own driver, id, card, API and MQTT topic) that is **connected to another device**, its **host**. The link itself can be device-controlled: the host decides whether the sub device is heard or seen, and commands to the host (a borrowed source, an HDMI input) switch it in. Nothing about the sub device's own control changes; the link adds the relation and the routing between the two.

**Cases that exist or are planned:**

| Sub device | Host | The link | Controlled by |
|---|---|---|---|
| BeoLink Converter NL/ML (`ConverterDriver`) | an ASE player that borrows its source | the player's `borrowSource` on `BeoZone/System/Products`; the converter's sources show on the player as `borrowed` | the player (borrow, activate the converter's source); `SessionHostInterface::addListener()` on the converter joins a player to it |
| Chromecast (dongle) on a TV | a TV: **the Beoplay V1, a Chromecast on its HDMI 1 (named "SET-TOP BOX" on the V1)**; later a Philips TV | the TV's HDMI input the dongle is plugged into | the TV's source activation (HDMI switching works on an ASE TV such as the V1, which also reports `signal` per input; *unverified* on Philips, see 2.2) |
| CD changer / tape deck | a player or converter that has the source | a source with controls from `config/control-profiles.php` (workstream 6) | the host's source activation, then `ConverterDigitTransport`-style keys through the host |

A TV with Chromecast **built in** is not a sub device: Cast is a second playback transport on the same device (a separate Cast design doc is not written yet; the driver is in `app/Integrations/Cast/`). Only a separate box behind the TV is one.

**Today** the relation is not modelled: the converter's link is the `converter_jid` meta key plus converter-specific code, and `ConverterDigitTransport` is the one place that sends a sub device's keys through its host. 2a makes that general.

**Design (to settle in the first PR):**
- **Link record.** A `device_links` table: `device_id` (the sub device), `host_device_id`, `kind` (`borrow`, `input`, `source`), `via` (source id or input label, e.g. `HDMI 2`), `meta` JSON. Many-to-many: one converter serves several players; a TV hosts several boxes. One level deep for 1.0 (a sub device is not itself a host).
- **Both stay full devices.** Each keeps its own row, driver, listener and cache. The `/devices` grid keeps listing both (whether sub devices nest under the host card is a UI decision, below).
- **Host exposes the sub device.** The host's source list gets an entry for the link (`AvailableSource` with `input` = `via`); activating it switches the host and, where the driver can, starts the sub device. `active` on that source is true while the host's active input or source matches the link.
- **Routing while active.** Transport commands (play, pause, next, seek, queue) sent to the host go to the sub device while the link is active; volume, power and the other sources stay with the host. This is the pattern of `SpotifyRouting` (one device from two, for commands, capabilities and lists); build it as a generalisation of that class, not a second copy. Now playing and artwork of the sub device are shown on the host's card while it is active (the converter's `playsLocally` is the precedent for telling who actually outputs the sound).
- **API.** `DeviceDetailResource` gains `host` (`{ id, name, via }` or `null`) and `sub_devices` (`[{ id, name, via, active }]`); a `sub_devices` capability on hosts. OpenAPI, MCP and CLAUDE.md follow the usual rule.
- **Setup.** A "Connected devices" card on the device edit page to add or remove a link by hand; automatic suggestions only where a device reports the relation itself (the converter's entry in the player's `Products` list).

**Build order.**
1. `device_links` model, the card, API read, and the migration of the converter link (`converter_jid` stays the identity; the link replaces the hard-wired borrow code paths).
2. Source entries and routing on the host (generalised `SpotifyRouting`); `ConverterDigitTransport` reads the link instead of looking the converter up.
3. First new sub device: the Chromecast on the Beoplay V1's HDMI 1 (`hdmi_1`, link `kind` = `input`, `via` = `hdmi_1`), using the Cast driver (`app/Integrations/Cast/`, started: message codec, connection, `cast:probe`). Needs TV input switching for the active-link state: the Beoplay V1 already has it (activating an HDMI source, `active` and `signal` per input), so the V1 can be the first host; on the Philips TV (2.2) the link stays manual with an unknown state until switching is solved.

**Decided:**
- **Nesting.** A sub device that cannot be controlled by itself (a Chromecast dongle, a CD changer behind a line-in) has **no card in the `/devices` grid**: it is shown only nested in its host's card, and only while the link is active (the host's matching input or source is selected). Its own page is not listed; it is reached from the host card. It is still a full device underneath (own id, driver and listener, usable through the API and MCP). A sub device that **can be controlled by itself**, such as the NL/ML converter (its sources, listener join and now playing work without a player), keeps its own card in the grid and is also nested while its link is active. Whether a driver counts as self-controllable is a flag on the device model (`standalone`), not a guess from capabilities.
- **Link defaults.** A link can carry actions for the sub device that run when the link becomes active (and optionally when it ends): a power on, a power off, a source. Example: a CD changer wired to the line-in of a player, controlled by an ESP; selecting that line-in on the player sends the power-on command to the ESP, leaving it sends the power-off. Stored on the link (`meta`: `on_activate`, `on_deactivate`), run by the host's source listener when it sees the input change, not only when the change came from us (a remote or the TV's own menu counts too).
- **Follow (sub device wakes its host).** The other direction of the link: when the sub device starts playing, the host selects the link. This is what makes a Chromecast usable behind a TV that has no CEC and cannot be woken by the dongle: the Chromecast (its own device, Cast driver) reports a cast session, and the rule activates `hdmi_1` on the Beoplay V1, which both wakes the V1 and switches it to that input. Stored on the link next to the defaults (`meta`: `follow`: `off` | `from_standby` | `always`, `follow_off_after` minutes or null), default `from_standby`.
  - *Trigger:* a session start seen by the sub device's listener (an app launched and playing), after a short debounce (apps start and stop several times in a row). The Chromecast's idle backdrop/ambient app does not count. `isActiveInput` is not used: the dongle only reports it over CEC.
  - *Guard:* `from_standby` switches only when the host is in standby, or already on the link's input; `always` also takes the screen from another input; `off` disables it. A host on another input is never switched by default, so a cast does not interrupt the set-top box.
  - *Turning off:* with `follow_off_after` set, the host goes to standby that long after the sub device stops, but **only if the follow rule woke it** (kept as state on the link), never a TV someone turned on themselves.
  - *Needs:* the sub device must stay reachable while the host is in standby (see the power note in [beovision-tv.md](beovision-tv.md), "Chromecast on HDMI 1"), and the host must switch input by API (the V1 does; Philips is unverified).

**Open (decide while building 1):** how a sub device that is only reachable through its host (converter off, TV in standby, a changer behind a powered-down input) is shown. This only matters for self-controllable sub devices and for the nested card, because the others are hidden when the link is inactive. Options: `unreachable` as today (simple, but the card looks broken while it is just not selected), `standby` derived from the link (the host is up and the link is inactive), or a separate state shown only on nested cards. No preference yet; pick one when the first link is built and the real behaviour of the converter and the ESP is visible.

Done when: the converter and the Chromecast on the V1's HDMI 1 are each linked to a host, the host lists them as sources and routes transport commands while active, casting to the Chromecast with the V1 in standby wakes the V1 on HDMI 1, `GET /api/devices/{id}` shows the relation both ways, and removing the link leaves both devices working on their own.

## 3. Availability layer (external libraries add to the collection)

**Problem.** Spotify is a hosted copy of what the user saved. Albums the user lacks, new releases and what a service can play on which device are not visible, and Deezer and Tidal would repeat the pattern.

**Design.** On top of `LibraryIdentity` (one record per artist, album and track) an album gets an **availability**: where it can be had.

| Availability | Meaning | Playable how |
|---|---|---|
| `owned` | own files (and physical items, workstream 6) | stream from this server / DLNA |
| `streamable` | on a service, per library (`spotify`, `deezer`, `tidal`) | per library and **per device** (`LibrarySources::playableOn`) |
| `known` | in the catalogue (MusicBrainz) only | not playable: a **wanted** candidate |

- New table (name to settle, e.g. `album_availability`): album id, provider (`files`, `dlna`, `spotify`, `deezer`, `tidal`, `physical`), external id, last checked. Sync jobs write it; the existing `Metadata` rows (`spotify_album_uri`, later `deezer_album_id`) stay the id source.
- **Discography.** An artist page lists the artist's full discography from MusicBrainz release-groups (albums, EPs, optionally singles/live/compilations), each marked owned / streamable / missing. Release-group rows for albums the user lacks are stored as `known` albums with `hidden` from normal browsing, so they never appear in counts, shuffle or the touch clients unless asked for.
- **Wishlist.** The user marks a `known` or `streamable` album as wanted. Wanted shows in a shelf and in the API. When a wanted album becomes owned (import, physical item), the wish closes by itself.
- **Spotify becomes a provider**, not a special case: saved albums remain streamable availability, and the same sync can also record Spotify availability for albums the user does *not* save (a search by artist + album, conservative match as in [streaming-services.md](streaming-services.md)) so "playable on Spotify" does not depend on having saved it.
- **Scope.** The scope switch (`local`, `streaming`, a library id, `all`) keeps working; availability adds a filter, not a new scope: `?availability=owned|streamable|missing`.
- **Clients.** Touch clients and the artwork pre-cache only see owned and streamable (as today); `known` rows never reach them.

**Decisions (settled):**

1. The discography shows **albums and EPs only** by default. Singles, live albums and compilations are not in it (a toggle may come later, not for 1.0).
2. The wishlist is **household-wide**, like favourites.
3. Missing albums live in **their own table** (`catalogue_albums`), so no existing album query changes; an album is promoted to a real `albums` row when it is imported or becomes owned.
4. **MusicBrainz release-groups are the authority** for what counts as missing, with a manual "I don't want this" per release-group.

Done when: an artist page shows owned, streamable and missing albums, a missing album can be wished for and shows up in the wishlist, an imported album closes its wish, and nothing changes for existing browse endpoints and clients.

**Feasibility:** high; mostly data modelling and sync jobs on pieces that exist (MusicBrainz client with rate limits, `Normalizer`, enrichment jobs, `library:enrich`). The cost is the migration and the UI, not new external dependencies.

## 4. Discovery

Free sources only; all through the enrichment framework (rate limits, retries, `library:enrich`).

- **New releases.** ListenBrainz `GET /1/explore/fresh-releases/` (upcoming and recent releases with release-group MBIDs; days window up to 90) and the per-user variant, filtered to artists in the library. Whether the global endpoint needs a token and its rate limits are *unverified*; check the [ListenBrainz API docs](https://listenbrainz.readthedocs.io/en/latest/users/api/misc.html). New release-groups land as `known` catalogue albums (workstream 3) and show as a "New from your artists" shelf.
- **Missing albums** per artist, from workstream 3.
- **Rediscover.** Shelves from `plays`: "on this day", "not played in a year", "you used to play", forgotten favourites. No external calls.
- **Similar tracks / radio** from the EffNet embeddings: plan and measurements in [similar-tracks.md](similar-tracks.md); build only if its measured query time holds on the minimum hardware, otherwise ship similar *artists* (Last.fm, MusicBrainz) only.
- **Listening modes** already exist ([listening-modes.md](listening-modes.md)); surface them in the discovery hub.
- **ListenBrainz scrobbling** next to Last.fm (a per-source switch like `lastfm_disabled_sources`) so users who prefer it get their history and recommendations there.
- **Where it lives.** A Discover section on `/library` (shelves per `docs/frontend/library.md`) and `GET /api/library/discover` for clients, plus an MCP tool. Done when: a user sees new releases from their artists, missing albums, and rediscover shelves without configuring anything beyond an optional ListenBrainz token.

## 5. Deezer and Tidal

Per-device playback, not a broad library: see [streaming-services.md](streaming-services.md) for the design, the hardware probes that gate it, and the build order. Scope for 1.0:

- Deezer: catalogue client and id mapping, `deezer` library, `deezer_playback` capability, ASE and Mozart play-by-id, only on devices proven to support it.
- Tidal: read-only reporting (now playing, history, scrobbles) through Tidal Connect, plus playback only if the Mozart `tidal` queue item is verified.
- Both register as availability providers (workstream 3) so an album shows "playable on Deezer on {devices}".

Gate: if the probes show a service cannot start playback on any device the user has, ship it read-only and say so in the UI, rather than hide it.

## 6. Physical library (CDs, tapes, vinyl)

**Goal.** Catalogue the physical collection, tie it to the same album records as everything else, and prepare for sub-device control (CD changers, tape decks).

- **Model.** `physical_items`: id, album id (nullable until matched), medium (`cd`, `tape`, `vinyl`, `minidisc`, `other`), barcode, catalogue number, condition, notes, location (free text and/or a `physical_locations` row: shelf, box, changer slot), acquired date, `ripped_at`, `file_id` link to an own file (workstream 7). Several items can share an album (two pressings, a CD and a vinyl); an album can have physical items without any file.
- **Entry.** Barcode (typed, or scanned by a phone camera in the browser where available) → MusicBrainz release lookup by barcode (Discogs as fallback; the Discogs integration exists) → choose the release → item created, album found or created through `LibraryIdentity` (a `known` album is promoted). Manual entry for items without a barcode (tapes, bootlegs): artist, title, year, medium.
- **Views.** `/library/collection` (physical): filter by medium, location, ripped yes/no; an album page shows "Owned on CD (shelf B2)" next to the streamable/own-file availability; `physical` becomes an availability provider (workstream 3). API: `GET/POST/PUT/DELETE /api/library/physical`, CSV import/export for bulk entry, MCP tool.
- **Sub-device control.** A changer slot or a tape deck is a source with controls from `config/control-profiles.php` ([source-controls.md](../api/source-controls.md)). A changer or deck is a **sub device** (workstream 2a) of the player that has its source. A `physical_locations` row of type `changer` links to a device and a slot; "play" on such an item activates the source and sends the disc-change control. This is *unverified* per device (which ones expose a changer or a tape source, which controls the transport accepts) and is optional for 1.0: the catalogue ships without it, the link is added per verified device.

Done when: an item can be added by barcode or by hand, shows on its album page, can be filtered and exported, and ripping (workstream 7) can mark it done.

**Feasibility:** high for the catalogue (all lookups exist), low-to-medium for device control (depends on device probes).

## 7. CD import (ripping)

**Constraint.** A ripper needs an optical drive on the machine that runs it, and the server (Docker on the Pi/Proxmox hosts, [system-requirements.md](system-requirements.md)) usually has none. So ripping is an **optional, drive-gated feature**; everything else works without it, including "import from a folder".

- **Where it runs (changed 2026-10-27).** The drive is on a **helper machine** or the server. The first helper is built: **ReMoment Ripper** (`ripper/`, a Tauri v2 desktop app, see `ripper/README.md`). It signs in with the **admin login and bearer token** (kept in the OS keychain; not the client-registration flow planned earlier), looks a disc up on MusicBrainz, tags the files and uploads them through `POST /api/admin/files` (the upload endpoint exists: enrichment on upload, no duplicate files, Vorbis album artist and disc tags). Status: sign in and upload one existing file; **ripping itself is not built yet**. This replaces the earlier "standalone script" decision for desktops. **Open:** a headless variant for a Pi with a drive (a Tauri GUI is the wrong shape there); either a small CLI built on the same upload API, or drop it and rip on a desktop.
- **Engine.** `cyanrip` or `whipper` (secure ripping, AccurateRip check, MusicBrainz disc-ID lookup, FLAC, cue sheet, log), on the helper machine; on the server it is an opt-in container profile like `beolink-stream.md`/`spotify-stream.md` with the drive passed through (`devices:`). Output goes to the inbox; the analysis pipeline follows.
- **Flow.** `GET /settings/storage` (or `/library/collection`) shows the drive state (empty, disc present, ripping); the disc's MusicBrainz disc ID gives candidate releases; the user confirms the release (or enters a barcode, matching a `physical_item`), the rip runs on a queue (`rip` queue, one at a time), progress is live (MQTT → Livewire like the other live views), the result lands in the library, the physical item gets `ripped_at` and `file_id`, and the disc is optionally ejected.
- **Quality and failures.** The rip log (AccurateRip result, track confidences) is kept with the files and surfaces in library health ([library-health.md](library-health.md)); a damaged or unconfident rip is flagged, not silently accepted. A disc without a MusicBrainz match can be ripped under a manual name and tagged later (tag editor, workstream 8).
- **Hardware.** Pi-class hosts can rip (slowly) but not while also running analysis at full speed: the `rip` and `analysis` queues run one job at a time each and the rip yields to listeners.

Done when: with a drive attached, inserting a known CD, confirming the release and waiting results in tagged FLAC files in the library, a ripped marker on the physical item, and a log; without a drive the feature says so and the rest is unaffected. *Unverified:* drive passthrough on the Proxmox LXC container (device permissions); Docker Desktop cannot pass a drive, so the rip agent on a desktop runs natively, not in Docker. The upload endpoint exists (`POST /api/admin/files`); resume of a large upload and the physical-item link do not.

## 8. Additional features

Each is independent and small enough to cut if time runs out; ordered by value.

1. **Scenes and automations.** *[Should]* A scene = devices + volume + source/playlist/radio + multiroom/sync group, started by hand, on a schedule (the Laravel scheduler), by an MQTT message, or by the API/MCP. Builds on multiroom presets, sync groups and the move machinery. Includes alarm/wake-up (a scene at a time) and a sleep timer for devices without one (a scheduled pause). `GET/POST /api/scenes`, `POST /api/scenes/{id}/activate`.
2. **Podcasts.** *[Can slip]* RSS subscriptions, episodes as a library kind with resume position, played as a stream URL through the same paths as radio (`PlayStreamUrlInterface`, DLNA URL), new-episode checks on the scheduler. Needs a small `podcasts`/`episodes` schema and a page next to `/radio`.
3. **Resume position (audiobooks and podcasts).** *[Can slip]* A bookmark per album/episode, kept server-side, restored on play (`playback_positions`); scoped to files and podcasts only.
4. **Tag editor for own files.** *[Can slip (becomes Should if CD import ships)]* Edit artist, album, track, year, genre and cover of files (write tags, rename through the organizer), needed for manual rips and fixing imports; re-runs identity and analysis for the changed files.
5. **Lyrics on `/receiver`** *[Built, nothing to do]*. Already in `receiver.blade.php`: a lyrics button, a three-line view with synced (LRC) or estimated plain lyrics, and ambient mode switching to it; see `docs/frontend/receiver.md`. Kept in the list so the numbering holds.
6. **PWA and notifications (decided: MQTT first, browser notifications second).** *[Should (phases 1 and 2); phase 3 is 1.1]*
   - *Phase 1, MQTT:* health and other alerts are published on a `remoment/alerts/{kind}` topic (not retained, small JSON: kind, device, message, time). Anything can subscribe (Home-style dashboards, phones with an MQTT app).
   - *Phase 2, local browser notification:* the page already holds an MQTT-over-WebSocket connection (`remoment-live.js`). A subscriber on `remoment/alerts/#` raises a `Notification` (after the user allows it once) while a tab or the installed PWA is open. No push service, no accounts, works everywhere the Notification API does.
   - *Phase 3, background Web Push (**decided: 1.1, not in 1.0**):* only way to alert a closed browser. It needs a service worker, a VAPID key pair on the server and a push subscription stored **per browser** (`push_subscriptions`, no user), sent by a queued job. No accounts are needed, but it depends on the browser vendor's push service, and on iOS it only works for an installed PWA. Note a "server worker in the user's browser" cannot wake itself in the background without Web Push; a service worker alone only runs while the browser is.
   - Installable web app: manifest, icons, offline shell.
7. **API and clients.** *[Must]* `GET /api/info` gains a `features` list (what this server has enabled: ripping, podcasts, deezer) so clients do not guess.
8. **Playback history.** *[Must]* The server already records every play: `StorePlaybackHistory` writes a `plays` row (track through `LibraryIdentity`, device, start; `ClosePlaybackHistory` sets `ended_at`), which feeds play counts, `last_played_at`, "most played" sorts and the recent shelves. What is **not** there is the history itself: the only view is `DeviceHistory` (the last 10 unique tracks of one device), and there is no API, so no client can show "what did we listen to". Scope for 1.0:
   - **API (household-wide, no per-user history, like favourites).** `GET /api/library/history` (newest first, cursor-paged; filters `device_id`, `scope`, `from`, `to`, `q`; each entry a `TrackItem`-style track with artist, album and artwork, the device `{ id, name }`, `started_at`, `ended_at`, how long it was actually played and a `completed` flag, and the library it came from), `GET /api/devices/{id}/history` for one device, and `GET /api/library/albums/{id}/plays` and `/artists/{id}/plays` for the "recent plays" tab of an album or artist (the app and the web album page list it as missing for want of this endpoint). Plays that match no library track (`library_add_played_tracks` off, radio, a stream) are listed with the name, artist or station the play was logged with, `track_id` null, and `type` `radio` where it was a station.
   - **Web.** A History page under `/library` grouped by day with the device, "play again" and "add to playlist" on a row, and the same recent plays on the album and artist pages.
   - **Housekeeping.** A retention setting (keep everything, or the last N months; the nightly job prunes), delete one entry and clear all (admin). Counts and `last_played_at` are kept in step so a pruned play does not change them unexpectedly (decide: prune only the log rows and keep the aggregate counters).
   - **Performance.** An index on `plays (started_at)` and `(device_id, started_at)`; the list pages by cursor on `(started_at, id)`, never by offset. *Unverified:* the size of `plays` on a long-running library; measure before choosing the retention default.
   - Last.fm and ListenBrainz scrobbling stay as they are (external history); this is the server's own, so it also covers sources that are not scrobbled.
   Done when: the web History page and `GET /api/library/history` show what played on which device and when, an album and an artist show their recent plays, retention prunes the log, and `openapi.yaml` and an MCP tool (`playback_history`) cover it.

## 8a. Home Assistant

**Goal.** Home Assistant (HA) sees what ReMoment knows and can build automations on it. Decided into 1.0 on 2026-10-26 (reverses the earlier "not in 1.0"). The plan and entity list are in [home-assistant.md](home-assistant.md); this section says what is in scope and in what order.

- **Sensing (MQTT discovery).** Stats, server health, clients (online, battery, pending approval) and one source sensor per physical device, as in the plan. Players stay out: HA already has every speaker through its own integrations, so there is **no `media_player` entity** (decision kept).
- **Triggers for automations.** Plain MQTT topics already work as triggers (`remoment/player/{id}/state`, `/data`), but a UI-friendly set is added through discovery as HA `event`/trigger entities: track started, playback state changed, source or TV input changed, a new client waiting for approval, a scene activated, and the alerts of workstream 8.6 (`remoment/alerts/{kind}`). The TV (workstream 2) and sub-device links (2a) feed it: "TV on", "input changed to HDMI 2", "link became active".
- **Actions from HA.** Default is the REST API, which the server already has: a generated HA `rest_command` package (scenes, play playlist/radio on a device, move playback, sync groups, TV remote keys) offered as a download on the settings page, plus the examples in the doc. The server stays publish-only, so no new process. An optional MQTT command path (a `button` per scene, a source `select`) needs the server to subscribe to MQTT; it is a decision for later, listed in the plan's open questions.
- **Depends on.** Scenes (8.1) for the scene triggers and buttons; alerts (8.6 phase 1) for the alert events; the source/input events already exist from workstream 2.
- **Settings.** `HA_DISCOVERY_ENABLED` (default off), the discovery prefix, "Publish now". Same Mosquitto broker as ReMoment, or a bridge (open question 1 of the plan).

Done when: with discovery on, HA lists the ReMoment server with its stats, health, clients and source sensors; an HA automation can trigger on a track, state, source or alert event and call a ReMoment action (activate a scene, play something on a device) through the package; turning discovery off removes every entity.

**Feasibility:** high for sensing and triggers (publish-only on an existing MQTT service); medium for the command path (new subscriber).

## 8b. Mobile app support

**Goal.** The native iPhone/iPad app ([ReMomentiOS](../../../ReMomentiOS), plan in its `docs/roadmap.md`, which maps this document's workstreams onto the app) uses only the public REST API and MQTT, like every client. This workstream lists what the server must add or fix for it, so the app is not blocked on web-only features. It also helps the ESP clients and any future client. Rule: **each item ships its OpenAPI entry and a test first; the app starts on an endpoint when it is in `openapi.yaml`.** The app has no accounts either: its only identity is the admin bearer token of `docs/api/admin-auth.md`. **Auth principle (decided):** the API is open by default; the admin token guards only what could be overwritten or broken by accident (settings, files and storage, tokens, client approval, connections). Everything else, multiroom presets included, stays open.

1. **Admin API for apps** (inside the `admin.token` group; `401 unauthenticated` without a token). Today only login, the current token, sources and storage are there; everything else under `/settings/*` is Livewire or an HTML form with no JSON route. Each row names the web code to extract into a service class, then wrap:
   - *Tokens (Admin & Apps):* `GET /api/admin/tokens` (id, name, last used, IP; the current one marked), `DELETE /api/admin/tokens/{id}`.
   - *Health:* `GET /api/admin/health` (broker, scheduler, queue worker, queue size, devices, from `SystemHealth`), external services with a "check now" (`Domain\Health\ExternalServices`), analysis status and its actions (run backlog, analyse a file, retry a stage; `AnalysisStatus`), and the findings of each library health report (`GET /api/library/health/{report}`; the counts are already open at `GET /api/library/health`).
   - *Clients:* list pending and approved, approve, reject, edit (name, type, devices, hidden sources), regenerate token, delete (`ClientManager`). This closes the loop with the app's own pairing code.
   - *Multiroom presets (decided: open):* list, create, delete and activate presets without a token, like multiroom itself (`GET/POST /api/multiroom/presets`, `DELETE /api/multiroom/presets/{id}`, `POST /api/multiroom/presets/{id}/activate`), extracted from `MultiroomPresets`. They are not part of the admin group; the web component already has no auth check, so web and API agree.
   - *Devices:* the discovered-device inbox (queued scan, list, add, ignore, restore), add by hand, hide, delete (`DeviceDriverManager`, `DiscoveryInbox`).
   - *Radio stations:* create, update, delete, and the stream finder (`RadioStationController`, which is also in the guest part of `web.php` today).
   - *Connections (decided: stay fully on the web):* connecting, disconnecting and the scrobbling toggles of Spotify and Last.fm, and the Spotify Connect name mapping, are not part of the app API. The OAuth consent step needs a browser and the rest sits beside it in the settings. The app does not show them.
   - *Library settings and maintenance:* leading source, "add played tracks", and the `library_maintenance` actions the MCP tool already calls (`scan_dlna`, `verify_files`, `enrich`, `merge_duplicates`, `sync_genres`, `refresh_smart_playlists`).
   - *Storage* routes already exist; put them in `openapi.yaml` (today only in `file-storage.md` and the MCP `storage` tool).
   - *Network settings (decided: behind the token):* `docs/api/device-settings.md` said network and rename would both move behind the token. Decided 2026-10-26: only the **network settings** endpoints (`PUT /devices/{id}/network/interface`, `/network/wired`, `/network/wifi`, and the `GET /network` read) require the admin token, since a wrong value can lock a device out. **Rename stays open** (harmless, easy to undo). This is a breaking change for any ESP client that changes network settings; the others are unaffected. Until the ESP firmware holds a token, the app treats network settings as admin-only in its UI.
2. **One library per playlist, everywhere.** `POST /api/library/playlists/{id}/tracks` refuses a mix (`422 source_mismatch`) and `GET /api/library/editable-playlists` reports `track_source` (commit `bb37311`). The web UI, the MCP `manage_playlist` tool, smart-playlist output and imports still use `PlaylistEditor::addTracks` without the rule. Move the rule into the editor so every path follows it, add a report of existing mixed playlists (their `track_source` is `null`), and keep the reason in `docs/api/library.md` (a mix would need the queue to play a mix, which only works through the Spotify-as-local-stream route). Workstreams 3 and 5 extend it to `deezer` and `tidal`.
3. **Contract gaps.** Fields the app already reads that are not in `openapi.yaml`: `now_playing.lyrics` (`plain`, `synced`) and `now_playing.waveform` of `GET /devices/{id}`, and the whole player protocol (`players.md`). Add `features` and `api_version` handling to `GET /api/info` (workstream 8.7; suggested keys: `scenes`, `podcasts`, `rip`, `deezer`, `tidal`, `physical`, `discover`, `sub_devices`, `home_assistant`). Make the URLs in `GET /api/info` (`base_url`, `api_base_url`, `artwork_base_url`, `mqtt.ws_url`) and in artwork objects follow the request's host unless one is configured: a phone reaches the server by IP or mDNS name, and a configured `APP_URL` such as `remomentserver.test` does not resolve there (already seen in the placeholder artwork URLs). A test that fails when a field in a documented response is not in `openapi.yaml` keeps this from drifting.
4. **Player housekeeping.** Every reinstall or unsigned build of a ReMoment Player registers a new device, so the device list fills with stale "iPhone" and "iPad" entries (the dev server already has several). Hide players that have not reported for N days (not delete), show `last_seen` in `GET /api/players`, add an admin `DELETE /api/devices/{id}` for players, and let a registration with the same `name` and `kind` and no token adopt the offline player of that name instead of creating one (opt-in flag on register).
5. **Alerts feed.** Workstream 8.6 publishes alerts on MQTT only. A client that opens later misses them, and a phone cannot rely on being connected. Keep the last 100 alerts (`GET /api/alerts?since=`, id, kind, device, message, severity, time; the same JSON as the MQTT message plus an id) so an app shows what it missed. The server still has no push; that stays in 1.1.
6. **Client contract notes for features already planned:** `GET /api/library/history` and the per-album and per-artist plays endpoints (8.8), `supported_keys` in the device detail for devices with `remote_control` (2.1), `host` and `sub_devices` fields (2a), `GET /api/library/discover` whose shelves are data (kind, title, items) so a new shelf needs no client release (4), `GET /api/scenes` with name, icon and colour for Shortcuts and widgets (8.1), `sources[]` on the artist and album responses already carries every library id (`files`, `dlna`, `spotify`, later `deezer`, `tidal`, `physical`). Each of these is listed in its own workstream; this line only records that the app depends on their shapes being documented first.

Done when: with only an admin token the app can do everything the web settings pages do except the browser steps (OAuth consent, a file upload through the browser); `GET /api/info` lists `features` and URLs the phone can use; every endpoint the app calls is in `openapi.yaml`; a playlist cannot mix libraries from any path; stale players no longer pile up.

**Feasibility:** high for 2 to 5 (small, data exists); medium for 1 (many Livewire components to extract, but the logic is in domain classes and the MCP tools already call most of it); the OAuth return path in 1 is the one real unknown.

## 9. Release

- Version `1.0.0` in `GET /api/info`; a `CHANGELOG`; an upgrade note per migration set (the MariaDB move is already documented in `database.md`).
- `docs/api/openapi.yaml` and `docs/api/mcp.md` updated for every endpoint added (rule in CLAUDE.md); the capability list in CLAUDE.md and OpenAPI kept in step. The contract test of workstream 8b (documented fields exist in `openapi.yaml`) runs in CI.
- Tests green on `composer test`; Pint clean.
- Hardware pass per device brand in the lab, recorded in the per-system docs (what is verified, what is not).
- Install path verified from scratch (`composer setup`, Docker compose, the Ansible Proxmox deployment) with the setup wizard and backup/restore.
- Decide and write down what is *not* in 1.0 (see below).

## 9a. Distribution and upgrades

Public release means installs the author does not control. Decided 2026-10-26.

- **Registry images.** Versioned images (`1.0.0`, `1`, and `latest` for stable releases only) published to **GHCR** (the repository is public), built for `linux/amd64` and `linux/arm64` with buildx in CI. Today `docker-compose.prod.yml` builds `remoment/app:latest` on the host; that does not work for strangers. armv7 only if the Control stack on a Pi 2 is verified (see 1a).
- **Supported install methods.** Compose file and registry images (the base; everything else wraps it), the **Ansible playbook** (`ansible/`, Proxmox LXC), and a **Raspberry Pi image** (Should for 1.0: flash, first boot, setup wizard, mDNS name so clients find the server). A **Proxmox template** is 1.1. A premade image is rebuilt and tested every release, so it needs its own first-boot and upgrade path.
- **Upgrades** have a CLI (`app:upgrade`, Ansible) **and**, decided 2026-10-27, a web path through the Manager of workstream 10 (backup first, health check, rollback); no unattended auto-update. `app:upgrade`: check the version, run the backup of workstream 1, migrate, clear and warm caches, restart workers, report. The version is recorded in the database so a downgrade is refused. Migrations are forward-only; the backup is the rollback. Each release has a changelog with a "needs attention" section. An updater service next to the app ("hypervisor") is a later idea; it needs the Docker socket, which is root on the host.
- **Migration squash.** The 52 migration files include 28 that alter or fix (add a field, drop it, add it again, data repairs). Right before the public release, consolidate to **one migration per table**, drop the data-fix migrations after checking none seeds data a fresh install needs, and mark the two existing databases (production and dev) as already migrated: Laravel ignores `migrations` rows for deleted files, and the new files must not run on them. Proof: build a fresh MariaDB from the consolidated set, dump its schema, diff it against production's. After 1.0 never edit an old migration. Laravel's `schema:dump` gives one baseline file, not one per table; per-table files are a manual rewrite.
- **Tested upgrade.** Restore a production backup into a scratch database, run `app:upgrade` from the last 0.x tag, check the app.

Done when: `docker compose pull && up` from a registry tag works on amd64 and arm64, the Ansible playbook installs a tagged version, an install upgrades from the last 0.x release without manual steps, and a fresh install builds the same schema as production.

## 9b. Release readiness

1. **CI** (`.github/workflows/laravel.yml` already runs `php artisan test` on PHP 8.4 and SQLite on push and pull request to `main`). Add: **Pint as a warning** (not a block), the front-end build (`npm run build`), a **MariaDB 11.4 job** (production's driver; also proves the squashed migrations), image builds and pushes to GHCR on tags (buildx, multi-arch), and the OpenAPI contract test of 8b. Pin the Sonos fork (the workflow clones `djneo92nl/sonos` at `laravel12`) to a commit or tag.
2. **End-user docs** in `docs/user/` as plain markdown with relative links, so a published site (mkdocs, GitHub Pages) can be added later: install per method, first run, adding devices per brand, troubleshooting (device not found, playback fails, nothing updates live), reverse proxy and remote access, upgrades and backups. **Tiered:** plain language for the Pi image; technical for Docker, Ansible and ESP32 builders (they are technical by nature). English only for 1.0. A **supported-hardware table** generated from a `verified` field (verified, untested, partial, with a note) in `config/devices.php`, also shown by the setup wizard and device-add page as "untested model".
3. **Network guardrails.** The API and MQTT stay unauthenticated by design. The setup wizard and `/settings/health` **warn** (visible, dismissible) when the server is reachable from a non-private address; nothing is blocked by default. Docs state "LAN only", show how to use a VPN, and document a **reverse proxy with TLS**. How MQTT credentials are enabled on Mosquitto is documented for those who want them. An optional private-range restriction may come later.
4. **Performance budget** per profile, measured on a real Pi 2 and Pi 3 (both available), not estimated: RAM of the full stack at idle and with 6 listening devices, load time of `/devices` and `/library`, response time of `GET /api/devices` and the library endpoints. A seeded **fixture library** (`library:seed-fixture`, sized to about 800 albums like the production library) so runs compare; **query-count assertions** on the key endpoints in CI so an N+1 fails a test; measured again in the hardware pass of workstream 9.
5. **Diagnostics bundle** *[Should]*: `diagnostics:bundle` and a download on `/settings/health` (admin): versions, profile, migration status, health, queue sizes, failed jobs, listener status, device list, recent logs. Redacted by default (passwords, tokens, API keys, Spotify and Last.fm secrets; device IPs and names masked unless the user opts in); no library contents; never uploaded automatically; no crash telemetry. Log rotation with size caps (daily files, 7 days) so logs cannot fill an SD card. A per-device report runs the read-only probe for the model (`ase:map`, `mlgw:probe`) and attaches it, so an untested model can be promoted to `verified`.

6. **Accessibility and TV use** *[Should]*. Keyboard: every control reachable with Tab and operable with Enter or Space, a visible focus state, shortcuts on `/receiver` (space for play and pause, arrows for seek and volume), and move up/down buttons as an alternative to drag-to-reorder in the source manager. Screen readers: labels on icon-only buttons, the now-playing region announced politely (not on every progress tick), sensible landmarks. Contrast checked against WCAG AA (text on tinted cards; `safe_colors` already helps), `prefers-reduced-motion` respected, relative units so browser zoom works, touch targets of at least 44 px for wall tablets. **`/receiver` is also a TV display, viewed from across the room:** larger type, a remote-friendly layout (D-pad focus order, no hover-only controls), and readable at 10-foot distance. Method: an automated axe or Lighthouse pass over the main pages, fixes, and a check in the hardware pass. Not full WCAG conformance for 1.0. No scan has been run yet.
7. **License, README and third-party terms** *[Must]*. **Proposed (confirm before release):** the server under **AGPL-3.0** with a **CLA** for outside contributions, so a hosted relay or key service stays possible for the author and nobody else can run a competing hosted copy of the code without publishing theirs; client-side pieces (`beo-mozart-php`, the ESP firmware and API examples) under MIT so integrators are not burdened. `composer.json` says MIT today and the repository is already public, so earlier copies stay MIT; change it before the release tag and before taking outside contributions. A `composer licenses` check of the production dependencies shows only permissive licenses (MIT, Apache-2.0, BSD-3-Clause, CC-BY; `getid3` and `nette/*` are multi-licensed and usable under a permissive option), so AGPL is possible; re-run it before release and add a `THIRD_PARTY` notices file. **Bring your own keys:** each install creates its own Spotify (Development Mode allows 5 users per Client ID), Last.fm, Discogs, AcoustID and TheAudioDB credentials, and the docs walk through each. **Spotify is done** (settings card, `spotify-app-setup.md`); the other four are not. **Essentia ML models** stay a download on first start, with their license shown to the user (they are, to my knowledge, non-commercial; *verify*; Essentia itself is AGPL and runs as a separate container). Also: a README (what it is, which hardware works, install links, screenshots, the LAN-only note), `CONTRIBUTING`, a security policy (where to report), and a "not affiliated with Bang & Olufsen, Sonos or Philips" notice. Decide whether `remoment/mozart-driver` is published or stays in this repository.
8. **Hosted-service hooks** *[Should]* (the service itself is after 1.0): every external API client takes its **base URL and credentials from settings**, never constants, so a relay can later be swapped in; credentials never appear in logs, health output or the diagnostics bundle; a credential "source" (own key now, relay later) is one abstraction, not one `if` per service.

Done when: CI is green on SQLite and MariaDB with the warnings above, `docs/user/` covers every install method, the supported-hardware table is generated, the setup wizard warns on a non-private address, the budget numbers are written down per profile, and a bundle from a test install contains no secret, and the keyboard and TV checks for `/receiver` pass.

## 10. Manager (control of the running instance) and SMB shares

Status: **decided into 1.0 (hard requirement), design draft.** Decided 2026-10-27. Extends 9a: the "updater service" that was left for later is now part of 1.0, and the upgrade command stays as its CLI.

**What it is.** A small, separate service, the **ReMoment Manager**, that runs on the **host** next to the app, survives the app being broken, and can see and control the running install: its processes and containers, upgrades with a backup first, host health, and the SMB shares for the library and the drop directory. Design reference: **DevDash** (`~/Code/devdash`, the owner's own tool): a dependency-free service that drives `docker compose` through `execFile` with argument arrays, a job map with streamed output, state on disk, a thin CLI that is only a client of the same HTTP API, mutating endpoints that need a header and a Host allow-list, bound to localhost. Reuse its shape and lessons (logic lives in the server so the UI and CLI are identical; never build shell strings from request input; hide nothing that is merely unhealthy but show crashed and restarting containers).

**Runs everywhere (decided).** The manager is installed on every supported target: the **Raspberry Pi image** (preinstalled), the **Ansible playbook / Proxmox LXC** (a role installs it), and **plain compose** installs (an install script that adds the service). It runs on the host (a systemd unit; launchd on a Mac dev machine like DevDash), not in a container, because it must manage the host's Samba, mounts and Docker, and must stay up when the app container is down.

**Language (to decide).** DevDash is Node. For a Pi 2 (armv7) and for a public image, a **static Go binary** (amd64, arm64, armv7, no runtime) is the better fit and cheaper on 1 GB; reusing DevDash code argues for Node. Suggest: prototype the API and UI against DevDash's shape, ship Go if the Pi measurements in 9b say Node is too heavy.

**What it controls (an allow-list, not a shell):**
- **Status and control:** the ReMoment compose project's containers (app, Redis, MariaDB or SQLite volume, Mosquitto, Icecast, the analysis sidecar, the BeoLink stream container): state, crashed or restarting, memory and CPU, start, stop and restart one or all, tail logs. Inside the app container, the supervised processes (`queue`, `storage-queue`, `analysis-queue`, `scheduler`, `listeners`): status, restart, logs (through supervisor; scope A of the earlier draft is included).
- **Upgrade:** pull the new image tag, run `backup:run`, restart, migrate, check `/settings/health`, and **roll back** (previous tag plus `backup:restore`) when the check fails. This is the web version of `app:upgrade` (9a), which stays as the CLI.
- **Host:** disk, memory, temperature and throttling (Pi), uptime; run a backup now; download the diagnostics bundle (9b); Pi image only: hostname and Wi-Fi.
- **SMB** (below).
- **Not included:** a generic shell, arbitrary `docker` commands, or editing the app's code.

**Where the admin uses it.** (1) Inside the ReMoment web UI: a Manager section under Settings, **reached with the admin login** (the app calls the manager over localhost or a unix socket with a shared secret the installer generates; the Laravel app already runs with host networking in production, see [database.md](database.md)). No second account. (2) **When the app is down** the web UI is down too, so the manager also has its own minimal page and CLI on the LAN, protected by a **manager token** created at install time (shown once on the Pi console or by the Ansible run, and stored root-readable). Open: whether that token may be the admin password (it cannot verify it while the app is down) or must stay separate.

**Security.** The manager is root-equivalent (Docker socket, Samba, mounts). It binds to localhost or a unix socket by default; the LAN page is opt-in and shows the same "LAN only" warning as the rest (9b). Every mutating call needs the token and an `x-` header and a Host allow-list (as DevDash); `execFile` with argument arrays only; project and service names validated against the compose file; every action logged to an audit file; no secrets in responses or in the diagnostics bundle.

**SMB shares for the library and the drop directory: both (decided).** Samba runs on the **host** (decided), configured by the manager.
- **Serve:** the music library as a share (**read-only by default**) and the drop directory (`inbox`) read-write, so a PC copies albums into the drop share and `library:import` picks them up. Settings in the web UI: share names, which paths, guest or password access, a Samba user (separate from the admin login) with a stored password, and a warning that guest access is unauthenticated. The manager writes `smb.conf`, creates the Samba user, reloads `smbd`, and reports the state. The app never writes system configuration.
- **Mount:** a share on a NAS used as a storage location: host, share, user, password, mount point, options. The manager writes a root-only credentials file and the mount unit (so it is mounted before Docker starts the app), and the compose volume points at the mount. Credentials are stored on the host only (not in the app database, not in backups by default, never echoed). *Unverified:* the file indexer and analysis over SMB are slower and have no change notifications (scans only); a Pi on Wi-Fi over SMB may not keep up; an unavailable mount must make the storage location report unreachable, not hang the app.
- Both appear in the app as another storage-location kind next to local and S3 ([file-storage.md](file-storage.md)); the manager does the host side.

**Build order.** (1) The manager skeleton: API, token, status of containers and processes, logs, restart, the CLI, on one target (compose on a dev host). (2) The web UI section and the app-to-manager link. (3) Upgrade and rollback, with `backup:run` and health check. (4) Host stats. (5) Installers: Ansible role, Pi image, install script. (6) SMB serve. (7) SMB mount. (8) The manager's own LAN page and token.

**Decisions still needed:** (a) Go or Node (above); (b) may the manager token be the admin password; (c) is the manager updated by itself, by `app:upgrade`, or only by the OS package on the image; (d) which init system and paths on each target (systemd on the Pi and LXC; plain-compose hosts may not have systemd).

Done when: on the Pi image, the Proxmox/Ansible install and a plain compose install, the admin can see and restart each container and process from the web UI, upgrade with an automatic backup and a rollback, and see disk and temperature; with the app stopped, the manager's own page and CLI still work; with SMB serve on, a file copied into the drop share from another machine appears in the library after the import; with SMB mount configured, a NAS share is used as a storage location and an unavailable share degrades cleanly.

**Feasibility:** high for status, logs and restart (DevDash already does it); medium for upgrade and rollback (the rollback path must be tested); medium for SMB (Samba and cifs are routine, the host differences per target are the work); the main cost is the installers on three targets and the security review.

## Not in 1.0

- Accounts, profiles, per-user stats or any role model (permanent constraint).
- Re-streaming Deezer or Tidal audio to other speakers; a Tidal catalogue with its own OAuth login unless a playback path is verified.
- Qobuz (same pattern as Deezer once a device path exists).
- Ripping without an attached drive; tape/vinyl digitising.

## Risks

| Risk | Mitigation |
|---|---|
| Availability layer touches many queries | Keep `known` albums in their own table (decision 3); additive filter only; migration tested on a copy of the production database |
| Deezer/Tidal do not work on any owned device | Hardware probes first; ship read-only and say so |
| Beoplay V1 rejects the one-way key commands, or Philips HDMI switching is not possible via API | Cut to "apps, power, transport, volume" for that model; `supportedKeys()` lists only what works; document the gap |
| Spotify removes more endpoints | Audit in workstream 1; Spotify keeps working as a hosted library even with reduced metadata |
| Rip drive not available in the container | Opt-in profile; folder import remains the baseline |
| Scope creep in workstream 8 | Each item stands alone and can move to 1.1 |
| The app is blocked on web-only admin features | Workstream 8b item 1 is split per area (tokens, health, clients, presets, devices, radio, connections); ship them one by one, the app adds a screen per endpoint |
| An undocumented field changes under a client | The OpenAPI contract test (8b item 3); the app reads only what is documented |

## Decisions log

- Availability layer: albums and EPs only; wishlist household-wide; own table for missing albums; MusicBrainz is the authority (workstream 3).
- CD drive: on a helper machine (desktop or Pi) or the server; a rip agent registers like a client device (workstream 7).
- TV: device lab has a Beoplay V1 and a Philips Android TV; Mozart-platform BeoVision is out unless a unit appears (workstream 2).
- Alerts: MQTT first, in-page browser notifications next, background Web Push optional (workstream 8).
- Rip agent: a standalone script, not an artisan command (workstream 7).
- B&O TV control targets **all ASE TV devices** (the Beoplay V1 is the lab representative), not Mozart TVs. Mozart-platform B&O products that exist are soundbars; their TVs run webOS, so a BeoVision on Mozart is a future change with no hardware to test (workstream 2). Treat webOS TVs as a later driver.
- Background Web Push: 1.1 (workstream 8).
- TV control (state): phase 1 built for the V1; a source wakes a B&O TV; keys use B&O's one-way commands, pending a hardware probe (workstream 2).
- Mobile app support (workstream 8b). Decided 2026-10-26: multiroom presets are **open**; only the network settings endpoints move behind the token (rename stays open); the Spotify and Last.fm connect flows stay **fully on the web** (no OAuth return to the app); native push (APNs) is **not wanted now**; the API stays open by default and the token guards only what could be overwritten or broken by accident. Proposed: the iOS app is a normal client of the public API with the admin bearer token as its only identity; admin features it needs get token-gated JSON endpoints extracted from the Livewire components; every endpoint it uses is in `openapi.yaml` first; a playlist holds one library on every path; alerts get a feed endpoint, and background push stays in 1.1 (web only; no APNs).
- Home Assistant is in 1.0 (2026-10-26): MQTT discovery plus triggers and REST actions for automations built in HA; still no `media_player` entities for speakers; the server stays publish-only unless an MQTT command path is decided later (workstream 8a).
- Sub devices: a device connected to another device (converter behind a player, a Chromecast behind a TV, a changer); the link may itself be device-controlled. A sub device that cannot be controlled by itself is hidden from the grid and nested in the host's card while the link is active (the NL/ML converter keeps its own card), and a link can run actions (e.g. power on an ESP-controlled CD changer) when its input is selected, Cast built into a TV is a transport on that device, not a sub device. A sub device that starts playing can wake and switch its host (follow rule, default only from standby), which is how a Chromecast on the V1 wakes a TV without CEC (workstream 2a).

- Profiles (2026-10-26): Control, Standard and Full are a Must (workstream 1a); Control uses SQLite under the conditions listed there, Standard and Full use MariaDB; Meilisearch only as an option in Full.
- Distribution (2026-10-26): images on GHCR, multi-arch; supported methods are compose, Ansible and a Pi image (Should); Proxmox template 1.1; upgrades by command line and Ansible only; migrations squashed to one per table right before the release, with the two existing databases marked as migrated (workstream 9a).
- Public release (2026-10-26): the repository is public; the network is warn-only plus a documented reverse proxy; docs are tiered, in the repo first, a site later; English only; Pint warns in CI (workstream 9b).
- Accessibility (2026-10-26): keyboard, screen-reader basics and contrast are a Should; `/receiver` is also a TV display, so it gets a 10-foot, remote-friendly layout; no scan has been run yet (workstream 9b).
- Hosted service (2026-10-26): a paid remote relay and a service that removes the need for user keys are wanted **after 1.0**; for 1.0 users bring their own keys (Spotify, Last.fm, Discogs, AcoustID, TheAudioDB) and the code is built so a relay can replace them (9b item 8). Essentia models remain a first-start download with their license shown.
- Hardware: the lab has a Pi 2 and a Pi 3; the production library is about 800 albums (fixture size).

- Progress (2026-10-27): the card fix, Spotify audit and credentials card, and backup/restore are built (see Progress). Backup secrets: excluded by default, `--with-secrets` opts in; archives sit on the app's disk by default and must be copied off the machine (workstream 1).
- CD ripper (2026-10-27): built as a Tauri desktop app that uses the admin token and the upload API; supersedes "standalone script" and "registers like a client device" (workstream 7).

- Manager and shares (2026-10-27): a **hard requirement for 1.0** (workstream 10). A separate host service in the spirit of DevDash (`~/Code/devdash`) that controls containers and processes, upgrades with backup and rollback, and reports host health; installed on every target (Pi image, Proxmox/Ansible, plain compose). SMB: both serve (library read-only, drop directory read-write) and mount (a NAS share as a storage location), with Samba on the host, configured by the manager. Queue management and stability is 1b. This reverses "upgrades by command line only / updater later" (9a): the CLI stays, the manager adds the web path.

## Open questions

Workstream 2a: how a sub device reachable only through its host is shown (listed under 2a). New questions go here.

Workstream 8b: none open (decisions are in the decisions log). New questions go here.

Stability: (a) does the card fix hold over an hour with several devices; (b) restore a backup on a scratch MariaDB 11.4 and record the result; (c) where do nightly archives go off the machine (NAS, USB, cloud).

CD import: (d) a headless ripper for a Pi, or desktop only; (e) does CD import move up from Can slip now that the app exists.

Workstream 10: (a) Go or Node for the manager; (b) may the manager token be the admin password, or must it stay separate (the manager has to work when the app is down); (c) how does the manager itself get updated; (d) init system and paths on each target (plain-compose hosts may have no systemd); (e) the Pi image console and Wi-Fi setup: in the manager or the image.
