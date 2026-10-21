# Spotify stream (librespot → Icecast)

Spotify on any speaker: the server runs a Spotify Connect receiver (librespot) whose audio is encoded to MP3 and served by Icecast, and a speaker without Spotify of its own plays that URL. Opt-in: `docker compose --profile spotify-stream up -d` plus `LIBRESPOT_ENABLED=true`.

## Flow

```
Spotify app ──Connect──▶ librespot (--backend pipe) ─PCM─▶ ffmpeg (MP3 320k) ─▶ Icecast :8000/spotify.mp3 ──HTTP──▶ speaker
                              │ --onevent (hook.sh)
                              └──────▶ POST /api/librespot/event ──▶ NowPlayingUpdated / ProgressUpdated / NowPlayingEnded
```

1. `POST /api/devices/{id}/spotify-stream` (or the card on `/settings/spotify`, MCP tool `spotify_stream`) tells the device to play the stream URL and remembers it as the target (`LibrespotEvents`, cache `librespot:target`, 12 h).
2. In the Spotify app the user picks the receiver named `LIBRESPOT_NAME` (default "ReMoment Stream").
3. librespot's `--onevent` hook posts each event; `track_changed` carries name, artists, album, covers and duration (kept in cache `librespot:track`), `playing` / `seeked` the position. Without a Spotify connection the server reports them as the target speaker's now playing (`sourceType` `spotify`, so history, artwork, MQTT and Last.fm work like any play); with Spotify connected and the receiver mapped to the speaker, Spotify's own listener already does that and the hook only sets the Icecast title (`/admin/metadata`), so nothing is reported twice.

## Always-on stream

librespot's pipe backend writes nothing while Spotify is idle, so ffmpeg would never connect to Icecast and the mount would not exist: a speaker told to play the stream got a 404. `docker/librespot/pcm-filler.py` sits between librespot and ffmpeg, copies the PCM at real-time pace and writes silence for every 20 ms in which librespot had nothing to give. The mount is therefore always live and a speaker can start the stream before a song plays. The cost is an encoder running around the clock (a little CPU; `LIBRESPOT_STREAM_BITRATE`, default 320, can go down to 192 on a Pi). When librespot or ffmpeg exits the pipeline ends and `run.sh` restarts it after 3 s.

## Per-track items (experimental, UPnP only)

The endless stream has one title (the ICY title) and one image: the Spotify logo set at start. A renderer reads art from the metadata of the item it plays, and cannot be told "the art changed" without restarting the item, so per-track art needs **one item per track**. With `LIBRESPOT_TRACKS=true` (Ansible `librespot_tracks`) the container does that next to the Icecast stream, which stays as it is.

```
librespot ─PCM─▶ pcm-filler.py ─▶ ffmpeg ─▶ Icecast            (the endless stream, as before)
                      │  cut at the track boundaries
                      └─▶ track_spool.py: one ffmpeg per track ─▶ /tracks/N.mp3 (+ /tracks/N.jpg) on TRACK_PORT (8010)
```

- **Exact boundaries.** `hook.sh` reports `track_changed` to the filler first (`POST 127.0.0.1:TRACK_PORT/_event`, answered with the item number `N`); the boundary is the bytes already read plus those still unread in the pipe (`FIONREAD`), so the tail of one track never lands in the next. Silence stays in the current item.
- **The item.** An MP3 with an ID3v2.3 tag written by `track_spool.py` itself (title, artist, album, duration, the cover as the front-cover picture): ffmpeg's MP3 muxer drops the whole tag when its output is a pipe. The cover is fetched from Spotify's https URL once and cut down to a 320 px baseline JPEG, served as `/tracks/N.jpg` over plain http, so a renderer that cannot do TLS can show it.
- **Served from the start.** `/tracks/N.mp3` is close-delimited (HTTP/1.0) and always starts at the track's first byte, even while it is still being encoded; a renderer that fetches the next item only when the previous one has drained its buffer (5-10 s after the next track began) still gets all of it. It ends when the next track begins. The last 4 are kept.
- **Handing it over** (`PlaysSpotifyItemsInterface`, UPnP driver): the hook event (`item` field) reaches `LibrespotEvents::handOverItem()`, which gives the item to the speaker, but only when the speaker plays the Spotify stream or an item right now (`playsSpotifyStream()`: PLAYING and a URL of ours loaded), so a speaker the user moved on is never taken over. The first track stops the silent stream and starts its own item (`SetAVTransportURI` with a DIDL carrying title, artist, album, cover URL and duration); every later one is queued behind the playing item with `SetNextAVTransportURI`, so a gapless renderer moves on by itself and the listener promotes it (`upnp:{id}:spotify_item`: playing and queued). A renderer that ignores the hint stops at the end of the track and the listener starts the queued item (one poll of delay; the whole track is still there). Pressing stop on the speaker ends the items.
- **Still true:** the speaker lags Spotify by its buffer (5-10 s), so a skip in Spotify reaches the speaker's display late, the same as the endless stream. Not covered: Sonos and B&O (each takes items differently; they keep the endless stream), pausing is silence in the current item.
- **Tests.** The container's Python (splitter, spool, the filler end to end with real ffmpeg): `python3 -m unittest discover -s docker/librespot/tests` (needs ffmpeg and ffprobe, not part of `composer test`). The PHP side: `tests/Feature/SpotifyTrackItemsTest.php`.
- **Unverified on hardware:** which renderers honour `SetNextAVTransportURI`, read the ID3 cover, or show the DIDL art; how the hand-over behaves when tracks are skipped quickly. This is a spike: try it on one renderer before relying on it.

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
