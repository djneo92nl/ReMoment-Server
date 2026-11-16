# ReMoment Node

Headless agent for a Pi or another Linux box (workstream 18; plan and decisions in `docs/architecture/node-agent.md`).

Status: **phase N1 (hello node) plus the N3 line-in role (against fakes, unverified on hardware).** It registers as a `node` client, waits for the admin to approve it in Settings › Clients, sends a heartbeat every 30 s, listens for the MQTT doorbell and runs fixed job types fetched over REST. Job types: `ping`, and for the `line-in` role `line_in.start` / `line_in.stop` (see below). No ripper yet. Go, stdlib plus the Paho MQTT client, no cgo.

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

Try it: register, approve in the web UI, then create a job:

```bash
curl -s -X POST http://remoment.local/api/admin/nodes/<id>/jobs -H "Authorization: Bearer <admin token>" \
  -H 'Content-Type: application/json' -d '{"type":"ping","params":{"message":"hi"}}'
```
