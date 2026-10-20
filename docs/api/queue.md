# Queue editing

Add, play next, remove, move and clear on a device's queue. Reading the queue (`GET /api/devices/{id}/queue`) and jumping (`POST …/queue/jump`) are in `CLAUDE.md`; this page is about changing it. Each action is its own capability, because platforms differ:

| Capability | Endpoint | Sonos | UPnP / ReMoment | B&O ASE | Spotify | Mozart |
|---|---|---|---|---|---|---|
| `queue_add` | `POST /queue` | yes | yes | yes | yes (end of Spotify's queue) | yes |
| `queue_next` | `POST /queue` with `next: true` | yes | yes | yes, unverified | no | yes, unverified |
| `queue_remove` | `DELETE /queue/{position}` | yes | yes | yes | no | no |
| `queue_move` | `PUT /queue/{position}` | yes | yes | unverified, not to the end | no | no |
| `queue_clear` | `DELETE /queue` | yes | yes | yes | no | yes |

Check `capabilities` first: an action the device lacks is `422 unsupported`. While Spotify is routed to a speaker the five contracts are delegated like the other queue ones (`SpotifyRouting::DELEGATED_CONTRACTS`), except for a Sonos, which keeps its own driver.

## Endpoints

```
POST   /api/devices/{id}/queue              { "type": "album", "id": 12, "next": false }   → { "status": "ok", "next": false, "tracks": 10 }
DELETE /api/devices/{id}/queue/{position}   ?expect=<uri>                                  → { "status": "ok", "position": 2 }
PUT    /api/devices/{id}/queue/{position}   { "to": 1, "expect": "<uri>" }                 → { "status": "ok", "position": 1 }
DELETE /api/devices/{id}/queue                                                             → { "status": "ok" }
```

- `type` is `track` | `album` | `artist` | `playlist`; an unknown `id` is a `422` validation error. Albums go in album order, playlists in playlist order, artists album by album. `tracks` is how many tracks the item holds, not how many the device could play: a driver queues the ones it can play (a DLNA stream, a Spotify URI) and skips the rest, and none playable is `422 invalid`.
- `position` is an up-next position: 1 is the first entry of `GET …/queue`. `to` is the position the entry ends up at.
- **`expect`** is the `uri` the client saw at that position. The queue can change between the read and the edit (a track ended, another client edited), so a driver that finds another uri refuses with `422 invalid` ("The queue has changed") instead of editing the wrong entry; the client re-reads. Optional, but every UI sends it.
- Adding to a queue nothing plays from (a UPnP renderer or Sonos that is idle or on radio) is `422 invalid`: start playback first.
- `clear` empties everything after the current track; what plays keeps playing.
- Same errors as every device endpoint: `503` unreachable, `422` `unsupported` / `invalid`, `502` `driver_error`.

## Implementation

- Contracts in `app/Integrations/Contracts/`: `QueueAddInterface::addToQueue(Collection $tracks)`, `QueuePlayNextInterface::playNext`, `QueueRemoveInterface::removeFromQueue($position, $expect)`, `QueueMoveInterface::moveInQueue($from, $to, $expect)`, `QueueClearInterface::clearQueue()`. Capabilities from `Capabilities::MAP`.
- `Http\Controllers\Api\QueueController` (REST) and `Http\Controllers\QueueController` (web buttons, flash messages) share `Domain\Library\QueueTracks` (type + id → tracks).
- Server-kept queues (UPnP renderer, ReMoment player): `Common\EditsDeviceQueue` over `DeviceQueue::append/insertNext/remove/move/clear`. After an edit that changes the next track the UPnP driver refreshes the renderer's gapless preload.
- Sonos: `duncan3dc` queue `addTracks($tracks, $position)` / `removeTrack` (zero-based, the playing track is `getStateDetails()->getNumber()`) and a raw `ReorderTracksInQueue` for a move (`InsertBefore` counts from one on the queue as it is now).
- ASE (`PlayQueueControls`): `POST PlayQueue` (append), `DELETE PlayQueue/{plid}`; play next and move use the queue's `insert` / `move` links (`PlayQueue?id=<before>`, `PlayQueue/{plid}?id=<before>`) which no device has confirmed. Positions count what `getUpNext()` lists, which leaves out items the library doesn't know, so the driver maps a position to the `plid` of the same listing.
- Spotify: `POST /v1/me/player/queue` per track; the Web API has no remove, move or play next. The queue Spotify returns also holds its own autoplay tracks after the user's.
- Mozart: add and clear are in the spec; play next is `PlayQueueItem.index`, whose meaning the spec doesn't describe (unverified). The spec has no queue read, remove or move, so those contracts (and `queue`, `queue_jump`) are not implemented until a device shows how: see `docs/architecture/refactor-hardware-check.md`.

## UI

- Album page: "Play next" and "Add to queue" next to "Add to playlist" when the pinned player has `queue_next` / `queue_add` (`POST /devices/{device}/queue`, route `devices.queue.add`). The same two actions are entries in the "…" menu of track rows (album page, artist page's top tracks, playlist page), of the artist hero (`type=artist`) and of the playlist page's Playback card (`type=playlist`), through `<x-queue-menu-items>`.
- Player bar Up next panel and the device page's Up Next card: move up / down, remove, Clear (`Livewire\Concerns\EditsQueue`), gated by capability.
- Drag to reorder: rows are draggable when the device has `queue_move` (Alpine `queueSort` in `public/js/remoment-live.js`, calls `moveInQueue(from, to, uri)`); the chevrons stay as the fallback.
- The `/receiver` panel has a remove (x) per row with `queue_remove` (`DELETE …/queue/{position}?expect=`); a refused edit shows in the panel's message line for 4 seconds, then the list is re-read.
- MQTT: every successful edit through the server publishes a non-retained ping on `remoment/player/{id}/queue` (`QueueUpdated` → `PublishQueueToMqtt`); the Up next lists and `/receiver` re-read at once. See `docs/architecture/live-updates.md`.
- Not built: seeing edits made on the speaker itself or by other apps before the safety refresh.

## MCP

The `queue` tool takes `action` (`list` | `jump` | `add` | `next` | `remove` | `move` | `clear`); `clear` needs `confirm=true`. See `docs/api/mcp.md`.
