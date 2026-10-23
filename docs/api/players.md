# ReMoment Player protocol

A player (browser tab, app, ESP32) plays the server's own files and shows up as a normal device: it is in `GET /api/devices`, publishes the usual `remoment/player/{id}/…` topics and answers every device endpoint (`play`, `pause`, `next`, `seek`, `volume`, `queue`, `library/play-*`). The server owns the queue; the player plays the one track it is told to.

Only tracks with a file of the server's own (`GET /stream/{track}`) are playable on it. A track whose file the player cannot decode is sent as a **proxy**, a smaller MP3 the server makes on demand (see *Proxies*); a player that decodes nothing the server can make is not offered that track.

## Register

`POST /api/players/register`

```json
{ "token": "…", "name": "Kitchen browser", "kind": "web", "capabilities": ["seek","volume_control"], "formats": ["audio/mpeg","audio/flac"], "profile": "mp3-lo" }
```

`kind`: `web` | `ios` | `esp32` | `retro` (display only; `retro` is for old handhelds and computers). `token` is omitted the first time; send it afterwards (it identifies the device, keep it in `localStorage`, keychain or flash). `capabilities` lists the optional controls the player supports out of the box (`seek`, `queue`, `queue_jump`, `shuffle`, `repeat`, `volume_control`; default all); `media_controls` and `library_playback` are always on. `formats` are the MIME types it decodes (default: mp3, aac, flac, ogg, wav). `profile` (optional) is a proxy profile (`mp3-lo`, `mp3-mid`; see *Proxies*) the player wants for **every** track, even one it could decode, for example a handheld with a slow link or a small MP3 decoder; an unknown name is ignored.

Response `201`: `{ player_token, device_id, command_topic, last_command_id, mqtt: { host, port, ws_port } }`. Start polling commands after `last_command_id`, so a reloaded player does not replay old ones. `player_token` is secret: it is the only credential of the player endpoints.

## Taking over an existing player

`GET /api/players` lists the players (`{ data: [{ id, name, kind, state }] }`). A client that has no token yet can send `adopt_device_id` with the register call: it gets a token of its own for that device and the device keeps its name, kind and formats (the other fields are ignored). Every token of a device works, so the browsers share it; nothing stops two of them playing at once. An id that is no player registers a new one. The `/player` page asks this once, when the browser has no token.

## Commands (server → player)

MQTT topic `remoment/playercmd/{device_id}` (QoS 1, not retained), or poll `GET /api/players/{token}/commands?since={last id}` (`wait` up to 25 s long-polls). Both deliver `{ "id": 12, "op": …}`; ids rise per player, the last 50 are kept for 5 minutes. Ignore ids you have seen.

| op | fields |
|---|---|
| `play` | `track: { id, url, profile, mime, name, artist, album, duration, images }`; `url` and `images[0]` are paths (`/stream/42`, `/stream/42?profile=mp3-lo`, `/storage/artwork/…/320.jpg`), prefix the server's address; `profile` is the proxy profile of `url` (null for the original) and `mime` the MIME type of what `url` serves; `images` is empty until the cover is processed; `lite` is the base path of the tiny covers (`/api/artwork/{hash}`, see *Lite artwork*), null until then |
| `resume`, `pause`, `stop` | |
| `seek` | `position` (seconds) |
| `volume` | `volume` (0–100) |
| `mute` | `muted` (bool) |

A live stream (a radio station, or the Spotify/Icecast stream) arrives as a `play` too, with `track.live: true`, `id: null`, `url` the absolute stream URL, `duration: null`. The player plays it endlessly: no seek, no position, and it reports `track_id: null`; `resume` after a pause reconnects to the live edge. A stream that ends or errors is reported as `ended: true`; the server then stops (it never advances the queue). The capabilities `radio_control` and `stream_url` are optional like `seek`: a player lists them at registration to get `GET/POST /api/devices/{id}/radio…` and the Spotify stream. For a station the server publishes the now playing (station + ICY title, `StreamTitles`) when the player reports state; the Spotify stream reports its own. A browser blocks an `http://` stream on an `https://` page (mixed content), so serve the server over http or use https stream URLs.

`next`, `previous`, queue jumps, shuffle and repeat are resolved by the server into `play`/`stop`.

## State (player → server)

`PUT /api/players/{token}/state`, on every change and every ~5 s (this is the heartbeat; no report for 10 s makes the device `unreachable`):

```json
{ "state": "playing", "track_id": 42, "position": 63, "duration": 213, "volume": 80, "muted": false }
```

`state`: `playing` | `paused` | `stopped`. Send `"ended": true` when the track finishes; the server then sends the next `play` (or `stop` at the end of the queue). The server turns reports into the normal events, so MQTT, history and scrobbling work as for other devices. Unknown token → `404`.

## Proxies

`GET /stream/{track}?profile=mp3-lo` serves a smaller copy of the file. Profiles are in `config/proxies.php`: `mp3-lo` (mono, 22.05 kHz, 48 kbit/s, for handhelds and microcontrollers) and `mp3-mid` (stereo, 44.1 kHz, 128 kbit/s). The server picks the source for a player when it sends `play`: the profile the player registered with, else the original when the player decodes it, else the first profile whose format it decodes.

Proxies are made on demand (ffmpeg, queue `default`), not ahead of time, and kept on the private disk until `PROXIES_MAX_MB` (default 2048) is exceeded, oldest first. When a track's proxy does not exist yet, the server sends `pause`, makes it, and sends the `play` when it is there; a player just keeps handling commands. If it cannot be made, the player gets `stop`. A plain `GET /stream/{track}?profile=…` for a proxy that is not ready yet answers with the original and queues the proxy, so DLNA renderers never get an error. Range requests work on proxies.

## Lite artwork

For screens that cannot decode a 320 px JPEG: `GET /api/artwork/{hash}/{size}.{format}[?colors=N]`, unauthenticated, where `{hash}` is the `hash` of a library item's artwork or the last path segment of a `play` command's `lite`.

| Part | Values |
|---|---|
| `size` | 32, 48, 64, 96, 120, 160 (square pixels) |
| `format` | `gif` (palette), `bmp` (uncompressed 8-bit palette), `pgm` (binary P5 greyscale) |
| `colors` | 2, 4, 16 (default), 64, 256: palette size, or grey levels for a PGM |

Anything else is `404` (size, format) or `422` (colors). `404` also means the cover is not processed yet: show a placeholder and ask again on the next track. Images are cut from the processed 320 px cover on the first request and kept, so a new combination costs one resize; they are served with `Cache-Control: max-age=86400`.

## Web player

`/player` is the browser implementation (`public/js/player.js`): `<audio>` on `/stream/{id}`, Media Session for lock screen and media keys, one click anywhere on the page to allow audio (asked right at load; a browser that already allows autoplay for the site never asks). Playback lasts while the tab is open; pin the player in the bar to control it from every page. Not done yet: a second tab with the same token does not stop the first; no sync between players.
