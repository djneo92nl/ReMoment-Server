# ReMoment Player protocol

A player (browser tab, app, ESP32) plays the server's own files and shows up as a normal device: it is in `GET /api/devices`, publishes the usual `remoment/player/{id}/…` topics and answers every device endpoint (`play`, `pause`, `next`, `seek`, `volume`, `queue`, `library/play-*`). The server owns the queue; the player plays the one track it is told to.

Only tracks with a file of the server's own (`GET /stream/{track}`) and a MIME type the player reported are playable on it.

## Register

`POST /api/players/register`

```json
{ "token": "…", "name": "Kitchen browser", "kind": "web", "capabilities": ["seek","volume_control"], "formats": ["audio/mpeg","audio/flac"] }
```

`kind`: `web` | `ios` | `esp32` (display only). `token` is omitted the first time; send it afterwards (it identifies the device, keep it in `localStorage`, keychain or flash). `capabilities` lists the optional controls the player supports out of the box (`seek`, `queue`, `queue_jump`, `shuffle`, `repeat`, `volume_control`; default all); `media_controls` and `library_playback` are always on. `formats` are the MIME types it decodes (default: mp3, aac, flac, ogg, wav).

Response `201`: `{ player_token, device_id, command_topic, last_command_id, mqtt: { host, port, ws_port } }`. Start polling commands after `last_command_id`, so a reloaded player does not replay old ones. `player_token` is secret: it is the only credential of the player endpoints.

## Taking over an existing player

`GET /api/players` lists the players (`{ data: [{ id, name, kind, state }] }`). A client that has no token yet can send `adopt_device_id` with the register call: it gets a token of its own for that device and the device keeps its name, kind and formats (the other fields are ignored). Every token of a device works, so the browsers share it; nothing stops two of them playing at once. An id that is no player registers a new one. The `/player` page asks this once, when the browser has no token.

## Commands (server → player)

MQTT topic `remoment/playercmd/{device_id}` (QoS 1, not retained), or poll `GET /api/players/{token}/commands?since={last id}` (`wait` up to 25 s long-polls). Both deliver `{ "id": 12, "op": …}`; ids rise per player, the last 50 are kept for 5 minutes. Ignore ids you have seen.

| op | fields |
|---|---|
| `play` | `track: { id, url, name, artist, album, duration, images }`; `url` and `images[0]` are paths (`/stream/42`, `/storage/artwork/…/320.jpg`), prefix the server's address; `images` is empty until the cover is processed |
| `resume`, `pause`, `stop` | |
| `seek` | `position` (seconds) |
| `volume` | `volume` (0–100) |
| `mute` | `muted` (bool) |

`next`, `previous`, queue jumps, shuffle and repeat are resolved by the server into `play`/`stop`.

## State (player → server)

`PUT /api/players/{token}/state`, on every change and every ~5 s (this is the heartbeat; no report for 10 s makes the device `unreachable`):

```json
{ "state": "playing", "track_id": 42, "position": 63, "duration": 213, "volume": 80, "muted": false }
```

`state`: `playing` | `paused` | `stopped`. Send `"ended": true` when the track finishes; the server then sends the next `play` (or `stop` at the end of the queue). The server turns reports into the normal events, so MQTT, history and scrobbling work as for other devices. Unknown token → `404`.

## Web player

`/player` is the browser implementation (`public/js/player.js`): `<audio>` on `/stream/{id}`, Media Session for lock screen and media keys, one "Start player" tap for autoplay. Playback lasts while the tab is open; pin the player in the bar to control it from every page. Not done yet: a second tab with the same token does not stop the first; no sync between players.
