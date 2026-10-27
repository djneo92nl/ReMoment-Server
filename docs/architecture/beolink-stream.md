# BeoLink stream (B&O multiroom → Icecast)

"Pseudo multiroom": what a Bang & Olufsen source plays (B&O Radio, a Beoplay M3/M5 line-in, a DLNA/FLAC stream through an M3/M5) on a speaker outside the B&O ecosystem, a browser or the iOS app. A receiver container joins the source's multiroom experience as a listener, its PCM is encoded to MP3 and served by the same Icecast as the [Spotify stream](spotify-stream.md), on its own mount. Opt-in: `docker compose --profile beolink-stream up -d` plus `BEOLINK_ENABLED=true`.

The receiver is [djerik/beo](https://github.com/djerik/beo) (Apache-2.0, pinned commit in `docker/beolink/Dockerfile`), the same protocol work the ESP32 listener PoC (`~/Code/ESP-Multi`) is derived from. Keep its NOTICE when redistributing.

## Flow

```
B&O source ──NetworkLink/FPS (UDP/TCP 1234)──▶ receiver.py (beo-adapter, plain speaker)
                                                  │ StreamPlayback: PCM, 48 kHz resampled to 44.1 kHz
                                                  ▼
                              pcm-filler.py (shared with librespot) ─▶ ffmpeg MP3 ─▶ Icecast /beolink.mp3 ──HTTP──▶ speaker / browser / app

ReMoment ──POST /api/v1/beolink/join/<jid> ──▶ receiver's Mozart API (:8081)  ("join this B&O device's experience")
```

1. The receiver announces itself over mDNS as a B&O product ("ReMoment Stream", `BEOLINK_NAME`), so it also shows up in the B&O app as a speaker to add to a source. `receiver.py` is beo-adapter with `--machine none` (no Master Link bus) and `StreamSpeaker` (`stream_playback.py`) in place of the sound card.
2. ReMoment joins it to a B&O device by that device's multiroom id (`ase_jid` / `mozart_jid` device meta, `BeolinkStream::listenTo()`), through the Mozart API beo already serves. The join is asynchronous (202 at once); a join that fails shows up as a silent stream.
3. `pcm-filler.py` writes silence while nothing arrives, so the mount always exists and a speaker can be pointed at it before a source plays.
4. Any number of players can pull the mount at once; ReMoment tracks them in the cache (`beolink:targets`) and tells the receiver to leave the source when the last one stops (`BeolinkStream::removeTarget()`).

## In ReMoment

- **Multiroom picker** (device card, player bar): while a B&O device plays and its multiroom id is known, a "Stream to" section lists devices of other brands with `stream_url` (UPnP, Sonos); the toggle joins the receiver to the host and starts the stream there (`ManagesMultiroom::streamTo()` / `stopStreamTo()`). The label says it is not in sync.
- **Settings** (`/settings/beolink`, `SettingsController::beolink`, admin): receiver status (probe of its Mozart API: running, name, B&O products heard over mDNS), the stream URL, a picker of the B&O source and the devices that can play the stream, and "make the receiver leave". While the stream is off it shows how to switch it on.
- **API**: `GET /api/beolink-stream`, `POST|DELETE /api/devices/{id}/beolink-stream` (`Api\BeolinkStreamController`), MCP tool `beolink_stream`. Clients (web, iOS) can play `url` themselves.
- **Config**: `config/beolink.php`; env `BEOLINK_ENABLED`, `BEOLINK_NAME`, `BEOLINK_API_URL` (default `http://127.0.0.1:8081`; `http://beolink:8081` in the dev compose), `BEOLINK_ENROL`, `BEOLINK_STREAM_BITRATE`, `ICECAST_BEOLINK_MOUNT`; Icecast settings are shared with the Spotify stream (`ICECAST_PORT`, passwords).

## Limits

- **Only unprotected sources.** The Moment's line-in is encrypted (measured in ESP-Multi); the receiver does not decrypt it and a join to it carries noise. B&O Radio and the M3/M5 line-ins are plain PCM.
- **Delay and sync**: 5-10 s behind the B&O speakers, no PTP: it is a separate listening path, not a member of the B&O group. One receiver = one source at a time.
- **Enrolment**: a source accepts listeners that are in the network's shared configuration. `BEOLINK_ENROL=true` lets the receiver add its entry once, which changes a document every B&O product holds: off by default, set it deliberately.
- **mDNS**: like librespot it needs the Linux host network (prod compose); Docker Desktop does not pass mDNS, so on a Mac the receiver only starts and answers.
- Pause: the source's pause marker is not acted on; the stream carries what arrives (silence).

## Status

| | |
|---|---|
| Container builds, starts, answers `join` with 202, `StreamSpeaker` replaces the sound card | **verified** (Docker, no B&O product on that network) |
| `StreamPlayback` (44.1 kHz straight, 48 kHz via ffmpeg resampler, drop-oldest queue) | **host-tested** (`python3 -m unittest discover -s docker/beolink/tests`) |
| PHP side: URL, join/leave bookkeeping, endpoints, picker section | **tested** (`tests/Feature/BeolinkStreamTest.php`, `BeolinkMultiroomTest.php`) |
| Receiver joining a real M3/M5 and the stream playing on a speaker, web and iOS | **unverified** |
| Whether `ase_jid` equals the JID the receiver's peer directory hears over mDNS | **unverified**; if not, the join answers "nothing by that jid has been heard" in the receiver log |
| Enrolment on the author's network | **unverified** (ESP-Multi did it once for the ESP32) |
