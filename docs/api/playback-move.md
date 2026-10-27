# Move playback to another device

`POST /api/devices/{id}/move` with `{ "to_device_id": 2, "stop_source": true }` makes what plays on device `{id}` continue on device 2. Capability `move_playback` on the source: listed while something movable plays (decided from the cache only, so it is cheap in device lists; the move itself can still be refused). Code: `App\Domain\Playback` (`PlaybackCapture`, `PlaybackMover`), controller `DeviceController::move`, MCP tool `move_playback`.

Response: `{ "status": "ok", "to": "Bedroom", "kind": "library|radio|spotify", "queued": 12, "skipped": 1, "position": 42 }`.

## What moves

| Playing on the source | On the target |
|---|---|
| Library tracks (own files, DLNA) | The queue from the current track on, started with `LibraryPlayback::playTracks`; tracks the target can't play are `skipped`. Once the target reports `playing`, it seeks to `position` (whole seconds, `playback.move_wait_seconds`, default 10, is how long to wait). A paused source leaves the target paused. |
| Radio | The station (a stream that is no station of ours is refused). No position. |
| Spotify (routed to the speaker, or the Spotify device) | Spotify's own transfer (`changeMyDevice`): queue and position come along. The target must be mapped to a Spotify Connect name (`/settings/spotify-connect`) and Spotify must see it, else `not_playable` / `driver_error`. The source is not stopped, Spotify takes playback away itself. |
| Line-in, TV, CD, Bluetooth, AirPlay/Cast, any input source | **Refused**, `not_movable`: the audio only exists on that device. |

Where the queue comes from: a device with a server-owned queue (generic UPnP, ReMoment player) has it in `DeviceQueue`, whole. A device with its own queue (Sonos, ASE) gives the current track by identity (`LibraryIdentity::lookupTrack`) and the rest from `getUpNext()` mapped back to the library (`DlnaTrackLookup`); entries that don't map are dropped. Mozart has no queue read, so only the current track moves.

The source is stopped only after the target started: a move that fails leaves it playing. `stop_source: false` keeps both playing.

## Errors

`422 not_movable` (nothing playing, a local input, not in the library, same device), `422 not_playable` (the target can't play it), `422` validation, `503 unreachable` (source or target), `502 driver_error`.

## Limits

Position is the last one the listener reported (about a second old) and seeks are whole seconds, so expect to land within a second or two. If the target was already playing something, the seek can run before its new track starts; it is best effort.
