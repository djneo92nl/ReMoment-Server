# ReMoment Node (workstream 18): plan

Status (2026-11-21): **plan; N0 (skeleton), N1 (hello node), N3 (line-in, see [line-in.md](line-in.md)) and N2 (ripper, see [node-ripper.md](node-ripper.md)), the last two against fakes and unverified on hardware, built**: the server side (registration fields, `node_jobs`, job API, `node.offline` alert) and the Go binary in `node/` (register, approval wait, heartbeat, MQTT doorbell, `ping` job). Roles: `line-in` and `ripper` (ffmpeg and cyanrip run only against fakes in the tests). Decision confirmed by the owner 2026-11-14: **Go**. 1.1, nothing here is in 1.0. Roadmap: [v1-roadmap.md](v1-roadmap.md) section 18. What is not measured or tried on hardware is marked **unverified**.

A ReMoment Node is a small headless daemon on a Pi (or any Linux box) that does things the server cannot do itself because the hardware is elsewhere: read a CD drive, capture a line-in, later play offline or take a sync. It is a **client** of the server (it registers, is approved by the admin, holds a token) with **roles** the admin switches on, and it only ever does a short list of **fixed job types**.

## What it is not

- **Not the Manager.** [manager.md](manager.md) is a host service with root-equivalent power over one compose project (restart, upgrade, rollback), bound to 127.0.0.1, with its own token, that must work when the app is down. The Node is the opposite: no docker, no host control, a scoped token, talks to the server over the LAN. They can run on the same Pi and never share a process, a token or an API. The Node is not upgraded by the Manager in 1.1 (its own installer, phase N4); the Manager does not learn about nodes.
- **Not the Ripper desktop app** (`ripper/`, Tauri; README only). That app is a GUI for a person at a Mac, Windows or Linux desktop: it signs in with the admin login and bearer token (OS keychain) and uploads through `POST /api/admin/files`. It stays as it is, independent, with its own platform rules (it must reach Linux and Windows). The Node's `ripper` role is the headless, server-driven variant for a Pi with a drive. Both end in the same ingest pipeline (the file upload endpoint); they share no code now. If the desktop app ever drives a local node, that is a later question.
- **Not an account.** Nodes are devices. The no-accounts rule holds: a node is a `clients` row, its token is not an admin token, and nothing a node can reach is admin-gated.

## 1. Language and packaging

Floor: Pi 2 (armv7, 1 GB) and Pi 3 (armv7/arm64 userland, 1 GB), see [system-requirements.md](system-requirements.md). The node shares the box with a cyanrip/ffmpeg child that needs the CPU, so its own cost must be small.

| | Static Go binary | Node (like the Manager) |
|---|---|---|
| Install | one file, `GOOS=linux GOARCH=arm GOARM=7` cross-compiled in CI, no runtime on the Pi | needs a Node runtime that supports armv7; recent Node releases treat armv7 as a lower tier, so a pinned runtime has to be shipped or built (**unverified**) |
| Idle memory | a few MB (**unverified**, expected) | tens of MB resident (**unverified**) |
| MQTT, HTTP, JSON, child processes | stdlib for HTTP/JSON/exec; one small MQTT library (Eclipse Paho) | stdlib HTTP; MQTT needs an npm package, which breaks the Manager's "no dependencies" shape |
| Supervising ffmpeg/cyanrip with limits (`exec.Cmd`, cgroups via systemd) | easy | easy |
| Reuse in the repo | none (a third toolchain after PHP+Node and Rust) | Manager's code style and tests |
| Update | replace the file | replace the tree and runtime |

**Decision: build the Node as a static Go binary first.** It is the only option where "copy one file to a Pi 2" is true without verifying a runtime, and the node must stay small beside a ripper. Node (the language) stays the fallback if the owner does not want a third toolchain; the contract (below) is language-neutral, so the choice is reversible before N1 is merged. A Rust build (musl, sharing nothing with the desktop Ripper today) is equally possible and not preferred: no reuse that pays for it.

The owner confirmed Go on 2026-11-14. A static `linux/arm` GOARM=7 build with the Paho MQTT client is about 7 MB stripped (measured on the build host; memory on a Pi 2 is still **unverified**).

Packaging: a systemd unit (`remoment-node.service`, `DynamicUser` or a `remoment` user in the `audio` and `cdrom` groups, `NoNewPrivileges`, `ProtectSystem=strict`, a writable state dir), a `.deb` for armhf and arm64 built in CI, a config file `/etc/remoment-node.json` (server address, hardware id, declared roles; JSON because the standard library reads it, no extra dependency) and a state dir with the token (mode 0600). Docker is not the primary form (drive and ALSA passthrough, mDNS); a container is possible later. *Unverified:* the `.deb` and unit on a stock Raspberry Pi OS Lite.

## 2. Registration and roles

It is the existing client registration ([client-devices.md](../api/client-devices.md)) plus three things: a `kind`, declared `roles` and `capabilities`.

```
node boot
  │  mDNS _remoment._tcp → server, GET /api/info (MQTT address)
  ▼
POST /api/clients/register
  { kind: "node", hardware_id: <machine-id>, firmware_version, build_number,
    roles: ["ripper", "line-in"], capabilities: { ripper: {…}, "line-in": {…} },
    metadata: { board, os } }
  ▼ ← { registration_token, pairing_code, status: "pending" }
  │  the node logs the pairing code (and shows it on an attached display or in `remoment-node status`)
  ▼ admin approves at /settings/clients and ticks which declared roles are enabled
  ▼
GET /api/clients/status/{registration_token}
  ← { status: "approved", type, kind: "node", roles: [enabled], api_token, devices }
  ▼ node stores the token, connects to MQTT, sends heartbeats
```

Decisions:

- **`kind` is separate from `type`.** `type` (`single`/`multi`) decides which *players* a client sees; widening that enum would also change a DB enum on two drivers (a CHECK on SQLite, a table rebuild) and mix two meanings. `clients.kind` is `client` (default; every existing row) or `node`. A node has no assigned devices unless the admin gives it some (the `player` role, later); it never gets the "all devices" rule of a `multi` client.
- **Declared is not enabled.** `roles` is what the node says it can do; `enabled_roles` is what the admin switched on (approval form and edit form). A node gets jobs only for enabled roles. Re-registering with fewer declared roles removes the dropped ones from `enabled_roles`; with more, they wait for the admin. This is the same "approval is the security gate" idea as for all clients, applied per role.
- **`kind` is fixed at first registration.** A known `hardware_id` re-registering with a different kind is `422 kind_mismatch`; the admin deletes the old row to change it.
- **Known roles** (a closed list in `App\Domain\Node\NodeRoles`, unknown names are `422`): `ripper`, `line-in`, `player`, `sync-target`. Only the first two exist in 1.1's phases N2/N3 (both built).
- **`capabilities`** is a JSON object keyed by role, informational for the UI and for the server's planning (e.g. `ripper`: `{ "drive": "/dev/sr0", "model": "…", "engine": "cyanrip 0.9.3" }`; `line-in`: `{ "inputs": [{ "id": "hw:1,0", "label": "USB audio", "rates": [44100, 48000] }] }`). It is never executed or trusted as a command; it is capped (16 KiB) and stored as sent.
- **Heartbeat** for a node is every **30 s** (clients: 5-15 minutes), with `status`: per role `{ state: idle|busy|error|absent, detail }`, `uptime_seconds`, `load`, `disk_free_bytes`. It is stored as `node_status` (the last report). The heartbeat answer carries the current `roles` (enabled), so a role switched on or off reaches the node within 30 s without MQTT.
- **Offline** = no heartbeat for 3 intervals (90 s) or the retained MQTT status `offline` (a last will). Later (N1): alert kind `node.offline` through the alert model ([alerts.md](alerts.md)) for nodes with at least one enabled role, resolved when it returns.

The pairing code, token generation and approval UI are unchanged. The token design note applies: `api_token` is plaintext and rotatable. For node routes the token is sent as `Authorization: Bearer`, not in the URL (URLs end up in logs); the client routes keep the URL form.

## 3. Command channel

**Decision: REST is authoritative, MQTT is the doorbell.** Reason: the broker is open (`allow_anonymous true`, [live-updates.md](live-updates.md); browsers and ESP32 clients subscribe anonymously, the server only publishes). On an open broker anyone on the LAN can publish to `remoment/node/{id}/cmd/*`. So a command received over MQTT must never carry anything a node acts on by itself.

```
server ── publishes ──▶ remoment/node/{id}/cmd/job     { "job_id": "01J…" }      not retained: "you have work"
node   ── GET  /api/nodes/job  (Bearer node token)  ──▶ the job: type, params (validated), lease, deadline
node   ── POST /api/nodes/jobs/{job}/accept|progress|result ──▶ state change (REST, authoritative)
node   ── publishes ──▶ remoment/node/{id}/status                {…}    retained, last will "offline"
node   ── publishes ──▶ remoment/node/{id}/progress/{job_id}     {…}    not retained, live UI only
node   ── publishes ──▶ remoment/node/{id}/ack/{job_id}          {…}    not retained, mirror of accept/result for live UI
node   ── publishes ──▶ remoment/node/{id}/disc                  {…}    retained (ripper role): empty, present, ripping
```

- A forged `cmd/job` makes a node ask the server for jobs it has; it gets nothing it should not. A forged `status/progress/ack/disc` message can only mislead a live view, never change a job (state changes are REST with the node's token). The Livewire views refresh from the database on those pings, like every other live view.
- **REST fallback = the same endpoints, polled.** A node that cannot reach the broker polls `GET /api/nodes/job` every 10 s (every 2 s while a job is running and it has progress to show). So MQTT down degrades latency, not function.
- **The server keeps publishing only**; the node is the new subscriber. A node subscribes only to its own `remoment/node/{id}/cmd/#`.
- **Mosquitto ACLs.** Phase N1 ships with the open broker as is, because the design above is safe on it. The hardening, a separate step (N4, **unverified**, because it changes how the server and every client connect): authenticated nodes, per-node users in a password file the server regenerates on approval, and a `pattern` ACL
  ```
  pattern read  remoment/node/%u/cmd/#
  pattern write remoment/node/%u/status
  pattern write remoment/node/%u/progress/#
  pattern write remoment/node/%u/ack/#
  pattern write remoment/node/%u/disc
  ```
  with anonymous users reduced to read-only on `remoment/#` and the server as its own writer. The ACL work is gated on the user wanting an authenticated broker at all; nothing in N1-N3 needs it.

## 4. Job lifecycle

A job is one row in `node_jobs` (N1, not in the skeleton): `id` (ULID), `client_id`, `role`, `type`, `params` (json), `state`, `idempotency_key` (unique per client), `lease_until`, `deadline_at`, `result` (json), `error`, timestamps. Created by the admin (web, API, MCP; the admin API route `POST /api/admin/nodes/{id}/jobs`), never by a node.

```
queued ──nudge──▶ queued ──accept──▶ accepted ──▶ running ──▶ succeeded | failed
   │                 │                    │            │
   └─ expired        └─ cancelled         └─ lost      └─ cancelled (cancel is a job state the node reads on progress and obeys)
```

- **Fixed types only**, one validator each on the server (on create) and in the node (on accept): `ping` (N1, echo), `ripper.status`, `ripper.rip` (ripper; built, named with a dot; no eject), cancel is the job cancel, `line_in.start`, `line_in.stop` (line-in; built, named with an underscore). No `exec`, no shell, no free-form command or path; params are typed (a release MBID, a drive id from the node's own `capabilities`, a mount name the server issued). A type the node does not know is `failed: unknown_type`.
- **Idempotency.** Creating a job with an `idempotency_key` that exists returns the existing job (a double click in the UI does not rip twice). `accept` is an atomic compare-and-set from `queued` to `accepted` with a lease (`lease_until` = now + 60 s, renewed by every progress or heartbeat); a second accept is `409`. `result` is idempotent per job id (a retry after a lost response is a `200` with the stored result).
- **Timeouts.** `deadline_at` per type decides `queued` to `expired` (`ping` 30 s, `disc.probe` 30 s, `rip.start` 24 h: a rip is a person's wish, not a transient command, `linein.start` 2 min). A job whose lease runs out without renewal is `lost` (not retried automatically for `rip.start`: a half-ripped disc is the user's decision; the UI offers "Run again").
- **Node offline.** Jobs stay `queued` until their deadline; the UI shows the node as offline and the job as waiting. When the node returns it fetches queued jobs in order. A job `accepted` or `running` when the node restarts is reported by the node as `failed: interrupted` (it keeps no job state on disk except the rip's temp dir, which it cleans). One running job per role at a time (rip yields to the other roles' limits, the rule of workstream 7: one `rip`, one `analysis` job each, the rip yields to listeners).
- **Results go over REST**, including the rip log and the upload receipt. Large payloads (the audio) go to the node-scoped upload (below), never in a job result.

## 5. Security

- **Token scope.** A node's `api_token` works for `/api/clients/{token}/*` and the node routes (`/api/nodes/*`, Bearer). It cannot reach `admin.token` routes. The upload for the ripper is a **node route** (`POST /api/nodes/files`, built: only while the node has an `accepted` or `running`, not cancelled `ripper.rip` job; the same ingest and limits as the admin upload, `App\Domain\Storage\FileUploads`) so the node never holds an admin token. *Unverified:* the existing ingest takes a `release_mbid` hint so the server, not the node, does MusicBrainz tagging; if it does not, N2 adds it. This keeps tagging in one place (the server has the MusicBrainz client; the Ripper app has its own) and the node dumb.
- **No shell, no free paths.** Fixed job types, typed params, child processes run through `exec.Command` with argument arrays; drive and capture device come from the node's own probed list, never from a job param; output goes only to the node's state dir.
- **systemd confinement** as above; the node user has the `cdrom`/`audio` groups only.
- **Revocation** is the existing one: the admin regenerates the token (the node's next call is `401`; it stops and logs; it re-registers only if the token file is deleted) or deletes the client.
- **Network.** Plain HTTP on the LAN like the rest of the API (the [network-exposure.md](network-exposure.md) warning covers a public server). Icecast source credentials for line-in are per mount, generated by the server, handed to the node in the claimed job (REST), never over MQTT.
- Everything a node reports is **data**: the UI escapes it, `capabilities` and `node_status` are never executed or used in a path or command.

## 6. Roles

### `ripper` (18.1)

- Engine: **cyanrip** (secure rip, AccurateRip, disc ID, FLAC and a log in one tool), chosen over whipper for packaging weight (whipper is Python with many native dependencies); **unverified** that cyanrip builds and runs well on Pi 2/3 armv7 and how long a disc takes beside analysis.
- State machine on the node: `absent` (no drive), `empty`, `present` (disc ID read: `disc.probe`), `ripping`, `done`/`error`. Published retained on `…/disc` and in the heartbeat's `status`.
- Flow: the node reports a disc ID; the server looks up candidate releases (MusicBrainz, already in the server); the **"Rip this disc"** card (on `/library/collection` and `/settings/storage`, later the apps) shows them; the admin confirms the release (or a barcode, matching a physical item); the server creates `rip.start { release_mbid, disc_id, eject: bool }`; the node rips to its temp dir with progress per track, uploads each FLAC (and the log, cue sheet) through the node upload, reports `result { tracks, accuraterip, log_ref }`; the server links the physical item (`ripped_at`, `file_id`), keeps the log for library health ([library-health.md](library-health.md)) and flags an unconfident rip.
- Drive and CPU limits as in section 4. *Unverified:* drive passthrough where the node sits in a container or LXC (not needed for the systemd form).
- Platform rule: the drive read and the ripper child sit behind a small interface so a Windows or macOS node is possible later; the first and only target is Linux.

### `line-in` (18.2)

- The node captures an ALSA input chosen from its probed list and runs `ffmpeg` (MP3 128 kbit/s, 44.1 kHz) **pushing to the server's Icecast** on a per-node mount (`/linein-{node}.mp3`), the pattern of [beolink-stream.md](beolink-stream.md) and [radio-relay.md](radio-relay.md): one upstream, fan-out in Icecast, speakers pull from the server's address (an IP, for renderers without DNS). Chosen over serving from the node (ffmpeg `-listen` serves one client, no fan-out, speakers would need the node's address).
- **Built 2026-11-15, see [line-in.md](line-in.md):** jobs `line_in.start { mount }` / `line_in.stop`; the input is the node's own config, not a param; the Icecast port and password are added when the node fetches the job; idle stop by the server. Original plan: jobs `linein.start { input }` / `linein.stop`; the server exposes it as a source like the Spotify and BeoLink streams (`stream_url` targets; latency 2-5 s, said in the docs). Now playing: fingerprint with the existing Chromaprint/AcoustID on a short capture window, scrobble through the usual path; a separate step after the stream works.
- Silence handling: ffmpeg runs while the job runs; the server stops the job when the last listener leaves (like `removeTarget()`), idle stop after a limit.
- *Unverified:* Icecast per-mount source credentials in the current `docker/icecast` config; capture glitches on a Pi 2 USB audio class device; whether the mount stays up when the source drops.

### `player`, `sync-target` (18.3, 18.4)

Not planned here. They reuse the registration, role switch, job and upload machinery and need their own plan (the sync manifest protocol, offline library). Declared roles `player` and `sync-target` are accepted and stored so the data model does not change later, and the admin UI will not offer jobs for them.

## 7. Phased build, with done-criteria

| Phase | Content | Done when |
|---|---|---|
| **N0 skeleton (built 2026-11-14)** | `clients.kind/roles/enabled_roles/capabilities/node_status`; register and heartbeat accept them; status and heartbeat answers carry enabled roles; `/settings/clients` badge, roles, enable roles; docs and OpenAPI | tests pass; a client that sends none of the new fields behaves exactly as before; no node binary exists |
| **N1 hello node (built 2026-11-14; Pi measurements, `.deb` and the unit on a real Pi still open)** | Go binary: registration, token store, 30 s heartbeat, MQTT status with last will, `node_jobs` and the job API, `ping` job, `node.offline` alert, systemd unit, `.deb` | a node registers and is approved; `kill -9` makes it offline in under 90 s (alert raised); a `ping` job runs end to end; one created while it is offline expires; a double accept is `409`; idle RSS and CPU on a real Pi 2 are measured and written here |
| **N2 ripper (built 2026-11-21 against fakes; drive, cyanrip and Pi rip measurements still open, see [node-ripper.md](node-ripper.md))** | built as `ripper.status` and `ripper.rip` (cancel is the job cancel; `disc.eject` is not built: the node is write-only), the node upload `POST /api/nodes/files` (Bearer node token, only during the node's own rip; the release tags come from the server's release in the job, not a hint on the upload), "Rip this disc" card, physical-item link, log kept on the job | with a drive: insert a known CD, confirm the release, FLAC lands tagged in the library with a log and `ripped_at`; no drive: the card says so and nothing else changes; rip plus playback on a Pi 3 measured |
| **N3 line-in (built 2026-11-15 against fakes; hardware, latency and Pi load still open)** | `linein.start/stop`, Icecast mount, source in the UI/API/MCP, idle stop | a turntable on a USB interface plays on a DLNA speaker through the server; latency measured and documented; fingerprint now playing optional |
| **N4 hardening and packaging** | optional Mosquitto auth and ACLs, token via header only, self-update story, Windows/macOS decision | broker with auth works with the server, browsers and ESP32 clients, or the step is dropped with a note |
| **N5 player / sync-target** | own plan after Sync v1 (18.3) | n/a |

## Decisions and open points

| | |
|---|---|
| Language | Go static binary first, Node as fallback; **owner to confirm** |
| Relation to Manager | separate processes, tokens and APIs; may share a Pi |
| Relation to Ripper desktop | independent; same ingest; no shared code |
| `type` enum | untouched; `kind` is a new column |
| Command channel | REST authoritative, MQTT doorbell and live view only; open broker stays |
| Mosquitto ACLs | optional hardening phase N4, unverified |
| Tagging | server side, from a release MBID hint **(to check against the ingest endpoint)** |
| Rip engine | cyanrip (armv7 build unverified) |
| Line-in transport | node pushes to the server's Icecast per-mount |
| Unverified | Go/Node memory on a Pi 2, `.deb` on Raspberry Pi OS, cyanrip on armv7, Icecast per-mount credentials, drive passthrough in containers, broker auth impact on existing clients |

## N1 as built

- **Server:** `node_jobs` (ULID id, state, lease, deadline, idempotency key, result), `App\Domain\Node\NodeJobs` (the closed `TYPES` list with role, expiry and param rules; `create`, `next`, `accept` as an atomic compare-and-set, `progress` renewing a 60 s lease, `finish` idempotent, `cancel`, `sweep` for `expired` and `lost`), `Api\NodeController` (Bearer node token: `GET /api/nodes/job`, `POST /api/nodes/jobs/{id}/accept|progress|result`) and `Api\AdminNodeJobController` (admin token: list, create, cancel under `/api/admin/nodes/{id}/jobs`). The only type is `ping`. The MQTT nudge is `remoment/node/{id}/cmd/job` with `{ "job_id" }` only, QoS 1, not retained; a broker that is down is logged and ignored. `alerts:check` raises `node.offline` (warning) for an approved node with a role switched on and no sign of life for `ALERTS_NODE_OFFLINE_SECONDS` (90); a heartbeat or a job poll counts. A node token is not an admin token and a plain client's token is not a node token (both `401`).
- **Node** (`node/`, Go): registers with `kind: node`, logs the pairing code, polls for approval (15 s), stores `<state_dir>/token` (0600), sends the heartbeat every 30 s with role states, uptime, load and free disk, polls `GET /api/nodes/job` every 10 s and at once when the doorbell rings, runs one job at a time, retries the result five times (the server keeps the first). It connects to the broker given by `GET /api/info` with a retained last will `remoment/node/{id}/status` `{state: offline}` and publishes `online`; it subscribes only to its own `cmd/#` and never reads the payload. An unknown token ends the process with status 2 (the systemd unit does not restart on 2). The handler table is closed: an unknown type fails with `unknown_type`, and nothing in a job is executed or used as a path.
- **Not built in N1:** per-job progress and ack topics (`progress/{job}`, `ack/{job}`, `disc`), a `rip`/`linein` type, the node-scoped upload, the `.deb`, mDNS discovery of the server (the server address is configured), Mosquitto auth.
- **Tests:** PHP `NodeClientTest`, `NodeJobsTest` (lifecycle, idempotency, 409 on a double accept, expiry, lost, cancel, token scope, the alert); Go `node_test.go` against a fake server (registration body, private token file, `ping`, refusal of an unknown type, revoked token). **Unverified:** a real node against a real server and broker, on a Pi.

## N2 ripper as built (2026-11-21)

See [node-ripper.md](node-ripper.md). Differences from the plan above: the job types are `ripper.status` (TOC, MusicBrainz disc id, no disc / data disc reported as a result, not an error) and `ripper.rip`; the node does **not** receive a release hint for the server to tag with, it receives the server's release (`meta`) and writes the tags itself with `cyanrip -N`, the server linking the physical item from the job as well; a rip is two job rows and no table of its own (progress and uploaded file ids in the cache, outcome in `result`; no migration); the progress route now takes an optional `progress` object; `ripper.eject`, the retained `…/disc` topic and a resumable upload inside one file are not built (resume is per file). Unverified: cyanrip's flags and output on a real build, the TOC ioctls on a real drive, everything on a Pi.
