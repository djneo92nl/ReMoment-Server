# Line-in as a stream source (workstream 18.2, N3)

Status (2026-11-15): **built against fakes, unverified on hardware.** A ReMoment Node with the `line-in` role captures an ALSA input and the server offers it as a source any speaker with `stream_url` can play. Nothing here has run with a real sound card, a real node or a real Icecast.

```
turntable ─▶ USB audio ─ALSA─▶ ffmpeg (nice, MP3 128k) ─icecast://─▶ Icecast :8000/linein-{node id}.mp3 ──HTTP──▶ speaker
                  node (Go, jobs line_in.start / line_in.stop)                  ▲ server: GET /api/line-ins, POST /api/devices/{id}/line-in
```

**Latency is 2 to 5 s** (ffmpeg, Icecast's burst, the speaker's buffer): fine for a turntable or a CD player, **not for TV** or anything that must stay in sync with video.

## Flow

1. The admin switches the `line-in` role on at `/settings/clients` (the node declared it and, if a capture device is configured, an input in its `capabilities`).
2. A client asks `POST /api/devices/{id}/line-in` (`node_id` when more than one node has a line-in), the device page card, or the MCP tool `line_in`.
3. The server refuses an offline node (`503 node_offline`), creates a `line_in.start { mount }` job unless the mount is already live or a start job is still open (**one encode per line-in**), nudges the node over MQTT (doorbell only) and waits up to `LINE_IN_LIVE_WAIT_SECONDS` (20) for the mount to appear in Icecast's `status-json.xsl`. Not live in time: `502 line_in_not_live`, the speaker is not touched.
4. When the node fetches the job the server adds the Icecast port and the source password (`radio_relay.icecast.*`) **at delivery**, over REST with the node's Bearer token: they are never stored in `node_jobs`, never listed by the admin API, never sent over MQTT.
5. The node runs `nice -n 10 ffmpeg -f alsa -i <device> … icecast://source:…@<host>:<port>/linein-N.mp3`, supervised: restart with backoff (2, 4, 8, 16, 30 s; the counter resets after a minute of running), the state reported in the 30 s heartbeat (`roles.line-in`: `busy` capturing, `error` with the last ffmpeg stderr, `idle`, `absent` without a configured input).
6. The server tells the speaker to play `http://<STREAM_BASE_URL host>:<ICECAST_PORT>/linein-N.mp3` through the existing `PlayStreamUrlInterface` (UPnP, Sonos, ASE unverified), title **"Line-in"** and the generated line-in logo. That fixed title is all the now playing there is: no fingerprinting yet.
7. **Idle stop.** The scheduled task `line-in-sweep` (every minute, only while something is wanted, profile feature `line_in`) stops a line-in whose live mount has had no Icecast listener for `LINE_IN_IDLE_SECONDS` (120), or whose mount never came up that long after it was asked for: it creates `line_in.stop` and forgets the demand. A listener who returns resets the clock.

## Security

- The capture device is the node's own config (`line_in.device` or `REMOMENT_NODE_LINEIN_DEVICE`), **never** a job param; a job only carries the server-issued mount (`linein-<digits>.mp3`, checked by a regex on both sides), the Icecast port and the password. The Icecast host is the node's config (`line_in.icecast_host`) or the host of its `server` address, never from a job.
- The command is an argument array (no shell); device, mount, host and password are validated by a pure function (`captureCommand` in `node/linein.go`) before anything runs; the password is URL-escaped and redacted in logs.
- The Icecast source password is the shared one of the existing container (`ICECAST_SOURCE_PASSWORD`, default `remoment`: set your own). Per-mount credentials in `icecast.xml` are not built.

## Where things are

| What | Where |
|------|-------|
| Server domain | `App\Domain\LineIn\LineIns` (nodes, mount, url, `describe`, `ensureLive`, `stop`, `sweep`), `config/line_in.php`, Icecast settings in `config/radio_relay.php` |
| Job types | `line_in.start { mount }`, `line_in.stop` in `App\Domain\Node\NodeJobs::TYPES` (role `line-in`, 2 min to be accepted); `NodeJobs::deliver()` adds the credentials |
| API | `GET /api/line-ins`, `POST /api/devices/{id}/line-in` (`listLineIns`, `playLineIn` in `docs/api/openapi.yaml`); MCP `line_in` |
| Web | the **Line-in** card on `/devices/{id}` for devices with `stream_url`, shown while a node has the role on |
| Node | `node/linein.go` (config, `captureCommand`, supervisor, job handlers), `line_in` in the node config, see `node/README.md` |
| Tests | PHP `tests/Feature/LineInTest.php` (fake Icecast through `Http::fake`, `FakeStreamDriver`); Go `node/linein_test.go` (no ffmpeg, no audio) |

`GET /api/line-ins` answers `{ data: [{ id, name, node_name, title, mount, url, node_online, node_state, detail, inputs, live, listeners, device_ids }] }`. Errors of the play call: `422 unsupported` (no node has the role on, or the device has no `stream_url`), `422 invalid` (several line-ins and no `node_id`, or an unknown one), `503 node_offline` / `unreachable`, `502 line_in_not_live`.

## Needs

The Icecast container (compose profile `radio-relay` or `spotify-stream`) must run on the server, and the node must reach its port. A speaker must reach the server's address: set `STREAM_BASE_URL` to the IP.

## Not built, unverified

- **Unverified on hardware:** ffmpeg's ALSA capture on a Pi 2/3 with a USB audio class device (glitches, xruns), CPU of one encode beside the node, the real latency, how each renderer treats the stream, whether the mount survives a source that drops and returns, the `nice` effect.
- Not built: stopping from the UI or API (only the idle stop), fingerprint now playing (a short capture window through Chromaprint and AcoustID, then scrobble), several inputs per node, silence detection, per-mount Icecast credentials, a `.deb` that sets up the `audio` group.
