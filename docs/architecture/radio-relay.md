# Radio relay (station → ffmpeg → Icecast → renderer)

**Status: plan, not built** (workstream 17 of [v1-roadmap.md](v1-roadmap.md), written 2026-10-30). Nothing below exists in code yet; what *was* measured on hardware is marked **verified**.

A renderer that can only fetch a plain-HTTP MP3 from the local network should still play any station in the library. The server fetches the station (any scheme, any codec, any redirect), converts it once to MP3 and serves it from the local Icecast; the renderer is given that address instead of the station's own.

## Why

Measured on the Philips Streamium MCi900 on 2026-10-30 (see the MCi900 section of [upnp-renderer.md](upnp-renderer.md)):

| What | Result | Station it breaks |
|---|---|---|
| Station by **name** over plain HTTP, MP3 (`http://listen.181fm.com/…mp3`) | the unit received the address and **stopped** (`CurrentURI` set, transport `STOPPED`) | 181.FM Energy 98 |
| The same stream by **IP address** (`http://216.235.84.38/…mp3`, same path) | **plays at once**; so the unit reaches the internet by address but evidently **cannot resolve names** (the cause is not confirmed: DNS setting, resolver; its own Internet radio also never played before and after the reset) | every station, by name |
| **HTTPS** with a redirect to another HTTPS host (`stream-relay-geo.ntslive.net` → `streams.radiomast.io`) | a 2008 firmware has no TLS; not tried on the unit, nothing there can follow it | NTS 1, NTS 2 and all NTS mixtapes |
| **AAC** (`icecast.radiofrance.fr/fip-hifi.aac`, 192 kbit/s) | the quality test shows AAC 256 as broken on the unit | FIP |
| Playlist and HLS addresses (`.pls`, `.m3u`, `.m3u8`) | not handled by the radio code today ([radio.md](radio.md): the search skips them) | stations added by playlist |

Some of this can be fixed on the device (DNS), but the rest (TLS, AAC) cannot, and the same limits hold for other old renderers. Only a relay fixes all of it, and it also makes a renderer independent of whatever the station does with redirects and moved streams.

**Verified, so the plan can rely on it:** the unit plays an HTTP/1.0 MP3 stream with `icy-*` headers by address (the 181.FM stream above), and MP3 at every tested bit rate over the unit's own UPnP path.

## Goals and non-goals

- **Goal:** any station of the library plays on any renderer that has `stream_url` and can decode MP3 over HTTP, with the same now playing and history as today.
- **Goal:** works with a renderer that cannot resolve names (the address handed out is the server's IP) and one that cannot do TLS.
- **Non-goals:** synchronised multiroom (each renderer buffers separately, 5-15 s behind live), recording or time-shifting, serving the stream outside the household, video, and replacing the native radio of Sonos (TuneIn) and B&O (BeoRadio), which stay preferred where they exist (native identifiers win, see the Radio section of CLAUDE.md).

## Design

```
station URL ──(HTTPS, AAC, HLS, redirects, ICY)──▶ ffmpeg ──MP3 128k──▶ Icecast  /relay-{station}.mp3 ──HTTP──▶ renderer(s)
   ▲                                                  ▲                      │ status-json (live? listeners?)
   │ StreamTitles (ICY/NTS) ──title──▶ Icecast metadata                      │
   └──────────── radio:relay (manager, supervised) starts and stops the ffmpeg children from cache demand ◀── UPnP driver "play this station"
```

**Decisions**

1. **The audio never passes through PHP.** A streaming response would hold a worker for as long as someone listens (`artisan serve` is single-threaded in development, FrankenPHP workers in production). ffmpeg pushes to Icecast, the pattern of the [Spotify stream](spotify-stream.md) and the [BeoLink stream](beolink-stream.md); Icecast also fans one upstream out to any number of renderers, so a station costs one upstream connection however many speakers play it.
2. **On demand, one ffmpeg per station.** Nothing runs until a device is told to play a relayed station, and a station stops when nobody asked for it for `RADIO_RELAY_IDLE_SECONDS` (60) and Icecast reports no listeners. (The Spotify stream runs round the clock because its mount must exist before the first listener; a station mount can be waited for, so it need not.)
3. **MP3, 44.1 kHz, stereo, 128 kbit/s CBR by default** (`RADIO_RELAY_BITRATE`): it decodes on everything, including 2008 firmware. A station that is already MP3 is still re-encoded: one rule, one failure mode, and the renderer gets a constant format whatever the station does.
4. **A supervised manager, not queue jobs.** `php artisan radio:relay` runs next to `device:listen` (a supervisor program in the app container, which already has ffmpeg for [proxies](own-player.md)). It owns the ffmpeg children (Symfony `Process`), restarts one that dies with a backoff, and reads what is wanted from the cache. A queue job per stream would occupy a worker for hours.
5. **Demand through the cache.** `RadioRelay::want($station, $device)` stores `radio:relay:want:{station}` (last request time, device ids); the manager polls it every second. The UPnP driver calls it when it plays a station, and the device listener refreshes it while that station plays (the same idea as `StreamTitles::touch()`).
6. **Whether a relay is needed is decided per device and station** (`RelayDecision`):
   - device meta `radio_relay`: `auto` (default), `always` or `never`;
   - `auto` relays when the station's final URL (after redirects) is HTTPS, when its codec is one the device denies (`upnp_limits.deny_codecs`, the same limits that choose proxies for library files, see [upnp-renderer.md](upnp-renderer.md)), when the address is a playlist or HLS, or when the device cannot resolve names (`upnp_limits.no_dns`);
   - `streamium:enable` sets `always` for the MCi900 (it cannot resolve names, so every station by name fails).
7. **The relay address is built like every stream address:** `StreamUrl::base()` host (so `STREAM_BASE_URL` applies; an IP for a renderer without DNS) on `ICECAST_PORT`, mount `relay-{station id}.mp3` (flat name, no slash: Icecast mount names with a directory are allowed but a flat one is the safer assumption on old players).
8. **Wait for the mount before telling the renderer.** The mount appears once ffmpeg has connected upstream and pushed its first frames (a few seconds). The driver asks `RadioRelay::ensureLive($station, 15)` (polls Icecast's `status-json.xsl`) and falls back to the station's own address when it never appears, as `StreamProfile::playUrl()` falls back to the original for a proxy that cannot be made.
9. **Titles.** `StreamTitles` already follows the station's own ICY/NTS titles for the now playing and history, independent of any device ([radio.md](radio.md)); the relay does not change that. The manager also pushes each new title to the mount's Icecast metadata (`/admin/metadata?mode=updinfo`, as the Spotify hook does), so a renderer that shows ICY titles shows them. Whether the MCi900 does is **unverified**.
10. **Resources are bounded.** `RADIO_RELAY_MAX` concurrent relays (default 4). Over the limit the longest idle one is stopped; when none is idle the play request answers `422 relay_busy` (message names the limit). CPU per stream on a Pi is to be **measured, not assumed** (see Phases).

## Pieces (planned)

| What | Where |
|---|---|
| Manager | `App\Console\Commands\Radio\RelayStations` (`radio:relay`), `App\Domain\Radio\Relay\RelayManager` |
| Facade used by drivers | `App\Domain\Radio\Relay\RadioRelay`: `needed(Device, RadioStation)`, `want()`, `urlFor()`, `ensureLive()` |
| Decision | `App\Domain\Radio\Relay\RelayDecision` (final URL scheme, content type and codec from `StreamChecker`, kept as station meta `stream_scheme`, `stream_codec` by `radio:refresh` and probed once at first play with a ranged GET) |
| ffmpeg command | `RelayCommand::build($url, $mount)`: `-nostdin -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 10`, `-vn -map_metadata -1 -ac 2 -ar 44100 -c:a libmp3lame -b:a 128k -f mp3 -content_type audio/mpeg icecast://source:{pw}@{host}/relay-{id}.mp3`; an HLS input is native to ffmpeg, a `.pls`/`.m3u` is resolved to its first entry first |
| Icecast client | `IcecastStatus` (`status-json.xsl`: mount live, listener count; `admin/metadata`) |
| Compose | the existing `icecast` service gets its own profile `radio-relay` (today it sits in `spotify-stream`); settings `RADIO_RELAY_ENABLED`, `RADIO_RELAY_BITRATE`, `RADIO_RELAY_MAX`, `RADIO_RELAY_IDLE_SECONDS`; the Icecast passwords already default to `remoment` and must be changed on a shared network |
| Config | `config/radio_relay.php` |
| Status | a row on `/settings/health`, an alert (`Alerts::raise('radio_relay', $stationId, …)`, [alerts.md](alerts.md)) when a station's ffmpeg keeps failing, resolved when it recovers |
| API | `POST /api/devices/{id}/radio/{station}` answers as today plus `"relay": true`; `GET /api/radio/relays` lists the running relays (station, since, listeners, state) |

## Phases

1. **17.1 Relay core.** `RelayCommand`, `RelayManager`, `radio:relay`, `IcecastStatus`, config, the compose profile and supervisor program. Tested with a fake process (the command line, restart with backoff, idle stop, the limit). Done when, from the command line, an HTTP MP3 station, an HTTPS-with-redirect station (NTS 1) and an AAC station (FIP) each become a live mount that `curl` and a media player can play.
2. **17.2 Decision and driver integration.** `RelayDecision`, `radio_relay` meta, `RadioRelay::ensureLive`, the generic UPnP driver's station playback uses it, `streamium:enable` sets `always`. Tested with fakes. Done when 181.FM, NTS 1 and FIP all play on the MCi900 from the web UI and the API, and a station the renderer can play by itself (an HTTP MP3 on a renderer with DNS) is still sent direct.
3. **17.3 Titles and idle.** Titles pushed to Icecast, the listener keeps the demand alive while a station plays, idle stop verified with a real renderer, a badge on the playing card.
4. **17.4 Operations.** Alerts, `/api/radio/relays`, the settings status, restart behaviour after a crash and after a container restart, a **Pi 3 measurement** (CPU and memory with one and with four relays; the limit default is set from it), docs (this page, [radio.md](radio.md), the device-drivers guide, CLAUDE.md, the OpenAPI field `relay`).

## Risks and open questions

- ffmpeg's reconnect behaviour on ICY streams that drop, and across redirects that change host (NTS): *unverified*, part of 17.1's done-criterion.
- Terms of the stations: the relay serves the household network only and keeps **one** upstream connection per station however many speakers listen, which is lighter than each speaker connecting itself. NTS stays behind its existing opt-in (`radio_nts_enabled`, [radio.md](radio.md)); the relay does not widen it. Serving the mounts outside the LAN is not supported (Icecast stays unpublished beyond the LAN, and the manager docs say to change the passwords).
- Docker Desktop on a Mac publishes the Icecast port like any other, but a renderer on another subnet needs a route to it (the same condition as the existing `/stream` address); *verify* on the author's network in 17.1.
- Which renderers show ICY titles from the relayed mount, and whether the MCi900 does: *unverified*.
- Sonos and B&O keep their native radio; a stream they cannot play (HTTPS-only, for instance) could use the relay too. Out of scope until someone needs it.
- Not covered: pause (a live relay has nothing to pause: the renderer's buffer only), seek, and sync between speakers.

## Related

[upnp-renderer.md](upnp-renderer.md) (the MCi900 findings above), [philips-streamium.md](philips-streamium.md), [radio.md](radio.md), [spotify-stream.md](spotify-stream.md), [beolink-stream.md](beolink-stream.md), [own-player.md](own-player.md) (proxies).
