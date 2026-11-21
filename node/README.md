# ReMoment Node

Headless agent for a Pi or another Linux box (workstream 18; plan and decisions in `docs/architecture/node-agent.md`).

Status: **phase N1 (hello node) plus the N3 line-in and N2 ripper roles (against fakes, unverified on hardware).** It registers as a `node` client, waits for the admin to approve it in Settings › Clients, sends a heartbeat every 30 s, listens for the MQTT doorbell and runs fixed job types fetched over REST. Job types: `ping`, for the `sync-target` role `sync.scan` / `sync.claim` / `sync.apply` (see below), for the `line-in` role `line_in.start` / `line_in.stop` and for the `ripper` role `ripper.status` / `ripper.rip` (see below). Go, stdlib plus the Paho MQTT client, no cgo.

```bash
cd node
make test            # go vet + go test
make build           # dist/remoment-node-linux-{armv7,arm64,amd64}
REMOMENT_NODE_SERVER=http://remoment.local REMOMENT_NODE_STATE_DIR=/tmp/node go run .
```

Config: JSON file (`-config`, default `/etc/remoment-node.json`, see `dist/remoment-node.example.json`), overridden by `REMOMENT_NODE_SERVER`, `REMOMENT_NODE_STATE_DIR`, `REMOMENT_NODE_HARDWARE_ID` (default `/etc/machine-id`), `REMOMENT_NODE_ROLES` (comma separated), `REMOMENT_NODE_NO_MQTT=1`. The server address is configured (no mDNS discovery yet). The token is kept in `<state_dir>/token`, mode 0600. If the server says the token is unknown (revoked), the node exits with status 2; delete the token file to register again.

`dist/remoment-node.service` is an example systemd unit (dynamic user, confined, 64 MB limit). Unverified on a real Pi.

**Line-in role.** Declare `"roles": ["line-in"]` and set the capture device in the config (never in a job):

```json
{ "server": "http://remoment.local", "roles": ["line-in"],
  "line_in": { "device": "hw:1,0", "label": "USB audio", "bitrate": 128, "nice": 10 } }
```

(`REMOMENT_NODE_LINEIN_DEVICE` overrides the device; optional `ffmpeg` path, `rate`, `icecast_host`, default the host of `server`.) Needs `ffmpeg` with ALSA and libmp3lame on the node, and the node user in the `audio` group. After the admin switches the role on, the server starts and stops the capture with `line_in.start` / `line_in.stop`: `nice ffmpeg` pushes MP3 to the server's Icecast mount `linein-<node id>.mp3`, restarted with backoff while wanted, its state in the heartbeat. The command is built by a pure, unit-tested function (`captureCommand`). Latency 2 to 5 s. Unverified on hardware. See `docs/architecture/line-in.md`.

**Ripper role.** Declare `"roles": ["ripper"]` and set the drive in the config (never in a job):

```json
{ "server": "http://remoment.local", "roles": ["ripper"],
  "ripper": { "device": "/dev/sr0", "cyanrip": "cyanrip", "temp_dir": "/var/lib/remoment-node/rips", "nice": 15, "offset": 6, "retries": 5 } }
```

(`REMOMENT_NODE_RIPPER_DEVICE` overrides the device; `temp_dir` defaults to `<state_dir>/rips`; `offset` is the drive's AccurateRip read offset, left out when null.) Needs `cyanrip` on the node, the node user in the `cdrom` group and, under systemd, the drop-in `dist/remoment-node.service.d/ripper.conf` (the base unit hides devices and caps memory at 64 MB). The node reads the TOC of the disc itself (Linux CD-ROM ioctls) and computes the MusicBrainz disc id for `ripper.status`; `ripper.rip` runs `ionice -c 3 nice cyanrip` in `<temp_dir>/rip-<job id>`, reports progress every 2 s, uploads each FLAC to `POST /api/nodes/files` with the node's own token, and removes the directory. One rip at a time; a cancel from the admin kills cyanrip. It never ejects the disc. The command is built by a pure, unit-tested function (`ripArgs`); the tests use a fake cyanrip and a fake drive. Nothing has run with a real drive or a real cyanrip. See `docs/architecture/node-ripper.md`.

Try it: register, approve in the web UI, then create a job:

```bash
curl -s -X POST http://remoment.local/api/admin/nodes/<id>/jobs -H "Authorization: Bearer <admin token>" \
  -H 'Content-Type: application/json' -d '{"type":"ping","params":{"message":"hi"}}'
```

**Sync-target role.** Declare `"roles": ["sync-target"]` and set the volume in the config (never in a job):

```json
{ "server": "http://remoment.local", "roles": ["sync-target"], "sync": { "volume_path": "/media/usb/STICK" } }
```

(`REMOMENT_NODE_SYNC_PATH` overrides the path.) The node keeps that one volume filled with what the server plans for the target bound to it (Tools › Sync on the server): `sync.scan` reads the drive (marker, filesystem, free space), `sync.claim` writes `.remoment-sync/target.json` on a drive that has none (never before the admin presses "Use this drive"; nothing is formatted), `sync.apply` downloads what is added or replaced into `.remoment-sync/tmp`, renames it into place, renames moved files, deletes removed ones and writes `.remoment-sync/manifest.json` atomically as it goes. The engine (`internal/syncer`) checks every path itself, only ever touches files of its own manifest, never overwrites a file it does not own, leaves a file the user changed alone, refuses a drive of another server or target, skips mass deletion without a yes and stops when the drive is full. The node is write-only: wait until the job is done, then remove the drive yourself. The service user needs write access to the mount (the unit's `ProtectSystem=strict` needs the path in `ReadWritePaths`). Built against fakes, unverified on a real stick. See `docs/architecture/offline-sync.md` and `docs/api/sync.md`.
