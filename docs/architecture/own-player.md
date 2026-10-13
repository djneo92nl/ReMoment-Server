# Own Player (plan)

> **Status:** plan, nothing built. Written 2026-10-13.

Today ReMoment only controls other people's players. This plan gives it two playback paths of its own, in this order:

1. **Web player**: a browser tab is a ReMoment device that plays the server's own files.
2. **NetworkLink source**: the server streams its own files straight to B&O speakers, in sync across rooms, with no DLNA in between.

Later (not planned in detail): an ESP32 player, see the last section.

Both phases only play what the server has as a file (`track_files`, served by `GET /stream/{track}`, see `file-storage.md`). Spotify, radio and DLNA items stay with their own devices.

## Goals and non-goals

Goals:
- Library `Play` works on a web player and on B&O speakers without DLNA.
- Same API and MQTT contract as every device, so iOS, the ESP32 remote and the web UI work unchanged.
- Multiroom playback across B&O speakers that stays in sync.

Non-goals:
- No user accounts (shared admin only, see `CLAUDE.md`).
- No DSP, room correction or transcoding for third-party players.
- No Home Assistant entities for our players (see `home-assistant.md`: HA already has the speakers).
- Nothing for non-B&O speakers in phase 2. Sonos, Chromecast and AirPlay keep working as today.

## Phase 1: Web player

### Idea

A page on the server (`/player`) plays audio in the browser. To the rest of ReMoment it is a normal device: it shows up in `GET /api/devices`, publishes `remoment/player/{id}/…` and answers the usual endpoints. The server owns the queue; the browser only plays the current track and reports back.

### Design

- **Device**: new brand "ReMoment Player" with `WebPlayerDriver` (`app/Integrations/ReMoment/`). One device per registered player. Capabilities: `media_controls`, `volume_control`, `seek`, `queue`, `queue_jump`, `shuffle`, `repeat`, `library_playback`. No `source_*`, `power` or `multi_room` in v1.
- **Registration**: reuse the client flow (`POST /api/clients/register`, pairing code, admin approval). A client gets `kind = player` (new column, default `remote`); approving a player creates its device.
- **Server → browser (commands)**: MQTT over WebSocket, the broker the page already uses. Topic `remoment/webplayer/{deviceId}/command`: `{"op":"play","track":{id,url,…}}`, `pause`, `seek`, `volume`, `stop`. Not retained.
- **Browser → server (state)**: `PUT /api/players/{apiToken}/state` with `{state, position, duration, volume, ended}` on change and every few seconds. The server turns it into the normal events (`NowPlayingUpdated`, `ProgressUpdated`, `VolumeUpdated`, state), so MQTT topics, scrobbling and play history come for free. On `ended` the server advances the queue and sends the next `play`.
- **Audio**: an `<audio>` element on `/stream/{track}` (range requests give seeking). The browser decodes MP3, AAC and FLAC. Tracks it cannot decode (ALAC, DSD) are marked not playable on web players; the transcoded `?profile=low` file is used when it exists (proxy generation is not built yet).
- **Lock screen and media keys**: Media Session API (title, artist, artwork, play/pause/next/previous/seek).
- **Autoplay**: browsers need one user gesture. The page shows a "Start player" button once per session. A player that was told to play before that stays `paused` with a reason.
- **Availability**: no heartbeat for 20 s → `unreachable`, same as other devices.

### Sync

None in v1: two web players, or a web player and a speaker, are not aligned. A later option is server-timed starts (clock offset measured over MQTT ping), good for roughly tens of milliseconds, which is enough for neighbouring rooms but not for stereo pairs.

### Reuse for the planned `web/` client

`/player` is built as its own page with its own JS module, using only public API and MQTT, so it can be lifted into the standalone `web/` client later.

### Steps

1. `clients.kind` migration, registration and approval create the device (tests).
2. `WebPlayerDriver`, command publisher, state endpoint, queue advance (tests with a fake MQTT).
3. `/player` page: register or pair, start button, playback, Media Session.
4. OpenAPI update (`/players/{apiToken}/state`, `kind`), then docs in `docs/api/` and `CLAUDE.md`.
5. iOS `Models.swift` and ESP32 only if they should list player kinds; otherwise they see just another device.

### Risks

- iOS Safari suspends background audio unless the page is a home-screen web app; test early.
- Several tabs of one player: one wins by token, others are told to stop.
- Large FLAC over WiFi: rely on range requests and browser buffering.

## Phase 2: NetworkLink source

### Idea

B&O speakers talk to each other with NetworkLink: XMPP-like control (beocore, port 5222) plus FPS audio over UDP, timestamped against the speakers' PTP clock. A project we can reuse, `~/Code/ESP-Multi/beo` (Apache 2.0, by Tobias Laursen), implements the **source** side: the machine plays a stream, speakers join as listeners, and the stream stays in sync. We use that so ReMoment can be the source.

Its `beo.networklink` package is protocol-only (no serial port, no HTTP server) and has the source role (`SourceRole`, `FpsSender`, `PtpSlaveClock`, `Fanout`) plus mDNS discovery. In its own architecture doc this is use case 5, "planned", and use case 2 (source to speakers) is marked verified against a BeoSound 9000.

### Architecture

```
ReMoment server (PHP)                       netlink sidecar (Python, host network)
 ├─ LibraryPlayback  ──REST──────────────▶  ├─ decode: ffmpeg → 44.1 kHz s16 stereo PCM
 ├─ queue, play history, MQTT  ◀──events──  ├─ AudioSource → beo.networklink SourceRole/FpsSender
 └─ existing ASE / Mozart drivers           ├─ PtpSlaveClock (follows the speakers' clock)
        (volume, power, sources: unchanged) └─ discovery: which speakers are joinable
```

- The sidecar is a separate process because `beo` is Python and has threads and raw UDP/multicast needs. It lives in its own repo (`~/Code/ReMomentNetLink`, symlinked as `netlink/`, per the "adding a client" steps in the umbrella `CLAUDE.md`).
- It must run with host networking (PTP multicast, mDNS, port 5222), as a `compose.yaml` service or on the box with Avahi, like the mDNS advertisement.
- **The server stays the owner of everything user-visible**: queue, now playing, history, MQTT. The sidecar plays what it is told and reports position and track changes.

### Sidecar API (internal, server only)

| Endpoint | Purpose |
|---|---|
| `GET /peers` | speakers seen over mDNS: jid, name, IP, product type |
| `POST /sessions` | start: `{listeners:[jid…], queue:[{id,url,title,artist,album,duration}], index}` |
| `POST /sessions/{id}/{play,pause,next,previous,stop}` | transport |
| `PUT /sessions/{id}/seek`, `/volume` | seek; volume via the listeners' renderer volume |
| `PUT /sessions/{id}/queue` | replace or append (the server owns the queue; this keeps decode ahead gapless) |
| `POST /sessions/{id}/listeners`, `DELETE …/{jid}` | join or leave a room mid-play |
| events (HTTP callback or MQTT `remoment/netlink/…`) | track changed, progress, ended, listener lost |

### Server side

- `NetworkLinkStreamer` (HTTP client to the sidecar) in `app/Integrations/Common/` or a package.
- Which devices qualify: ASE or Mozart devices whose `device_meta` has a NetworkLink jid (`multiroom_meta_keys` already store JIDs). New capability `native_stream`, listed only when the sidecar is enabled and reachable.
- `LibraryPlayback` chooses the path: a track with a local file and a device with `native_stream` → sidecar; otherwise DLNA as today. A setting switches it off per device.
- Same delegation pattern as `SpotifyRouting`: transport, seek and queue go to the sidecar while it plays to that device; volume, power, sources and multiroom stay with the speaker's own driver.
- Now playing is also pushed to the speakers through NetworkLink (`NOW_PLAYING`), so the B&O app shows the track.
- Multiroom: playing to a group = one session with several listeners, so all of them are in sync.
- New settings: sidecar URL, enable switch, default format policy.

### Audio path

- ffmpeg decodes the track (local path or `/stream` URL) to raw 44.1 kHz s16 stereo, the format `beo` sends; other sample rates are resampled. This is lossy for hi-res files; note it in the UI.
- The next track is decoded ahead and spliced into the same PCM stream, so tracks are gapless and the speakers never see a stream change.
- ReplayGain, if wanted, is a later option in the decode step.

### Steps

0. **Spike (no ReMoment changes, 1–2 days)**: run `beo`'s `tone_to_speaker` example, then a file through ffmpeg as an `AudioSource`, against one real speaker and then two. Measure: PTP lock, start latency, drift over 30 minutes, gapless splice, sync between two speakers. **Go or no go** on this result.
1. Sidecar repo: session manager, decode, discovery, API, tests with `beo`'s loopback.
2. Server glue: `NetworkLinkStreamer`, capability, `LibraryPlayback` routing, events to MQTT, tests with a fake sidecar.
3. Multi-listener groups, join/leave, volume, error recovery (speaker drops, sidecar restarts).
4. Docker/compose service, docs (`docs/architecture/networklink.md`, `CLAUDE.md`, API docs), settings UI.

### Risks and open points

- **Undocumented protocol.** It is reverse engineered; firmware updates can break it. Pin versions and run the spike regularly on real hardware.
- **Narrow verification.** `beo` verified the source role against a BeoSound 9000 setup and one product identity; our source has to present a recognised product identity (the project measured that a wrong type value killed the connection). Which speaker models accept us is unknown until the spike.
- **PTP.** The clock is read from the speakers; with none active, `beo` falls back to an offset measured once from a listener's log. Check the cold-start case in the spike.
- **Docker.** Host networking and multicast; a Docker Desktop on macOS setup will not work for development, so use a Linux host or the Proxmox LXC.
- **Port conflict.** Port 5222 is owned per host, so the sidecar cannot share a host with a `beo-adapter` Pi service.
- **License and relationship.** Apache 2.0 with a NOTICE that must be kept; changed files must be marked. The project is not ours: ask the author before depending on it, and consume it as a dependency, not as copied code. Its readme also states it is unaffiliated with Bang & Olufsen; keep the same position.
- **Scope.** Speakers only, files only. Radio could later use the same decode path; DSP and non-B&O targets are out.

## Later: ESP32 player

Not part of this plan, only the shape so nothing built now blocks it:

- A board with an I2S DAC pulling `GET /stream/{track}` over HTTP, controlled like a web player (same driver idea, command topic, state endpoint). The existing ESP32 remote could become its screen.
- Sync over WiFi would need server-timestamped buffering (Snapcast-style). Joining NetworkLink groups needs a C++ port of the FPS receiver and a PTP slave; WiFi has no hardware timestamps, so this is a research task. A Pi running `beo`'s listener role is the low-risk alternative.

## Order and decision points

1. Phase 2 spike (cheap, decides whether phase 2 is real).
2. Phase 1 web player (steps 1–4).
3. Phase 2 steps 1–4, if the spike passed.
4. Decide on the ESP32 player with real results from both.

## Open questions

1. Will you contact the author of `beo` first? It decides whether the sidecar depends on it or on a fork.
2. Which speakers do you have for the spike (models, how many)?
3. Is lossy 44.1 kHz acceptable for hi-res files in the NetworkLink path?
4. Should a web player register through the same approval flow, or is a per-browser pairing code on the `/player` page enough?
5. Should the sidecar run in `compose.yaml` on the server host, or on a separate Linux box?
