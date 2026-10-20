# Spotify stream (librespot → Icecast)

Spotify on any speaker: the server runs a Spotify Connect receiver (librespot) whose audio is encoded to MP3 and served by Icecast, and a speaker without Spotify of its own plays that URL. Opt-in: `docker compose --profile spotify-stream up -d` plus `LIBRESPOT_ENABLED=true`.

## Flow

```
Spotify app ──Connect──▶ librespot (--backend pipe) ─PCM─▶ ffmpeg (MP3 320k) ─▶ Icecast :8000/spotify.mp3 ──HTTP──▶ speaker
                              │ --onevent (hook.sh)
                              └──────▶ POST /api/librespot/event ──▶ NowPlayingUpdated / ProgressUpdated / NowPlayingEnded
```

1. `POST /api/devices/{id}/spotify-stream` (or the card on `/settings/spotify-connect`, MCP tool `spotify_stream`) tells the device to play the stream URL and remembers it as the target (`LibrespotEvents`, cache `librespot:target`, 12 h).
2. In the Spotify app the user picks the receiver named `LIBRESPOT_NAME` (default "ReMoment Stream").
3. librespot's `--onevent` hook posts each event; `track_changed` carries name, artists, album, covers and duration (kept in cache `librespot:track`), `playing` / `seeked` the position. Without a Spotify connection the server reports them as the target speaker's now playing (`sourceType` `spotify`, so history, artwork, MQTT and Last.fm work like any play); with Spotify connected and the receiver mapped to the speaker, Spotify's own listener already does that and the hook only sets the Icecast title (`/admin/metadata`), so nothing is reported twice.

## Always-on stream

librespot's pipe backend writes nothing while Spotify is idle, so ffmpeg would never connect to Icecast and the mount would not exist: a speaker told to play the stream got a 404. `docker/librespot/pcm-filler.py` sits between librespot and ffmpeg, copies the PCM at real-time pace and writes silence for every 20 ms in which librespot had nothing to give. The mount is therefore always live and a speaker can start the stream before a song plays. The cost is an encoder running around the clock (a little CPU; `LIBRESPOT_STREAM_BITRATE`, default 320, can go down to 192 on a Pi). When librespot or ffmpeg exits the pipeline ends and `run.sh` restarts it after 3 s.

## Pieces

| What | Where |
|------|-------|
| Containers | `icecast` and `librespot` in `compose.yaml` / `docker-compose.prod.yml` (profile `spotify-stream`), `docker/icecast/`, `docker/librespot/` |
| Config | `config/librespot.php`; env `LIBRESPOT_ENABLED`, `LIBRESPOT_NAME`, `LIBRESPOT_EVENT_SECRET`, `ICECAST_PORT`, `ICECAST_MOUNT`, `ICECAST_SOURCE_PASSWORD`, `ICECAST_ADMIN_USER/PASSWORD`, `ICECAST_INTERNAL_URL` |
| Stream URL | `Domain\Spotify\LibrespotStream::url()`: the host of `StreamUrl::base()` (so `STREAM_BASE_URL` applies) on `ICECAST_PORT` |
| Driver contract | `PlayStreamUrlInterface::playStreamUrl($url, $title, $art)` → capability `stream_url` |
| Endpoints | `GET /api/spotify-stream`, `POST /api/devices/{id}/spotify-stream` (422 when off or unsupported, 503/502 as elsewhere), `POST /api/librespot/event` (header `X-Librespot-Secret`) |

## Per platform

| Driver | How the stream is played |
|--------|--------------------------|
| Generic UPnP | Stop, `SetAVTransportURI` with DIDL-Lite (`audio/mpeg`), Play. Clears the "playing" cache so the listener never advances the server queue after the stream |
| Sonos | `x-rincon-mp3radio://` URI through `useStream()` |
| B&O ASE | like a DLNA stream (`PlayQueue?instantplay`); **unverified on hardware** |
| Mozart, Spotify | not supported |

## Notes and limits

- Delay: ffmpeg, Icecast's burst and the speaker's buffer add roughly 5–10 s. Pause/skip in Spotify reach the speaker late; the stream only carries silence while paused.
- Routing: pressing "Play stream" maps the receiver's Spotify Connect name to that speaker (`LibrespotStream::routeTo()`, the same `spotify_connect_name` meta as `/settings/spotify-connect`, one speaker at a time). With Spotify connected, the speaker and the Spotify virtual device then behave as one (`SpotifyRouting`): one card, and pause/next/seek/shuffle/queue go to Spotify instead of stalling the stream. Volume stays with the speaker. Pausing keeps the merge: the speaker keeps `playing` the silent stream, which the generic rule ("routing ends when the speaker plays something by itself") would read as another source, so `SpotifyRouting::speakerPlaysByItself()` asks the speaker what it has loaded (`ReportsStreamUrlInterface::loadedStreamUrl()`: UPnP renderer position info, Sonos stream URI) and counts it only when that is no longer the stream (Icecast port and mount compared, not the host). A driver that cannot tell (B&O) is assumed to still play the stream, so something started on a B&O speaker while Spotify is paused stays merged until Spotify's session ends. Without Spotify connected, pause on the speaker only stalls the stream, and the hook is the only now-playing source.
- Docker Desktop does not pass mDNS: Spotify only finds librespot when the stack runs on a Linux host (prod compose uses `network_mode: host` for librespot). The librespot image builds the binary with cargo (a few minutes on first build).
- A speaker needs to resolve the stream host: many cannot resolve `.local`, so set `STREAM_BASE_URL` (Ansible `stream_base_url`) to the server's IP.
- The hook secret and Icecast passwords default to `remoment`: set your own when the LAN is not trusted.
- Unverified: B&O playback of the stream, Sonos behaviour with Icecast's ICY headers, and the librespot build features on arm64 (Pi).
- Not built: Snapcast for synced multi-room (Icecast serves one stream; multiroom of speakers that play it is up to their own grouping).
