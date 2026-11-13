# Tap to play: NFC tags and QR codes

A tag is an id (the uid of an NFC tag, or a generated one behind a QR code) that points to something to play: an album, an artist, a playlist, a track, a radio station or an item of the physical collection (which plays its library album). Stick an NFC tag on a CD case or print the QR code on a card: tapping or scanning it plays the music.

Reading and playing a tag is open, like the playback endpoints. Managing tags needs the admin token (or the admin login on the web).

## A reader taps a tag

```
POST /api/tags/{uid}/play    body: { "device_id"?: 3, "client"?: "<api_token>" }
```

The device is, in this order: `device_id`, the tag's own device (set by the admin), the first assigned device of the client whose `api_token` is given as `client`. With none of those: `422 no_device`.

```json
{ "status": "ok", "tag": { "id": 4, "uid": "04A31F92C45E80", "label": "Blue card", "target": { "type": "album", "id": 12, "name": "Portishead – Dummy", "exists": true }, "device": null, "shuffle": false, "last_used_at": "2026-11-13T20:15:00+00:00", "url": "http://remoment.local/t/04A31F92C45E80" }, "device": { "id": 3, "name": "Kitchen" } }
```

Errors:

| Status | `error` | When |
|---|---|---|
| 404 | `unknown_tag` | The uid is not assigned. The body has the normalised `uid`. The uid is remembered for 10 minutes so the admin can assign it without typing it |
| 422 | `no_device` | No device given and the tag has none |
| 422 | `not_playable` | The target is gone, a physical item has no library album, or the device cannot play it |
| 422 | `not_available_in_profile` | A library target on a Control install (a radio station tag works in every profile) |
| 422 / 502 / 503 | as the play endpoints | `unsupported`, `driver_error`, `unreachable` |

`GET /api/tags/{uid}` → `{ "data": Tag }` tells what a tag plays without playing it (404 `unknown_tag`; this does not remember the uid).

**Uid format.** Readers write a uid differently (`04:a3:1f:92`, `04A31F92`). The server compares letters and digits only, upper case, at most 64 characters, so any of these forms finds the same tag.

**How it plays.** The server calls its own play endpoint for the target (`library/play-album`, `play-artist`, `play-playlist`, `library/play`, `radio/{station}`), so the result and the errors are exactly those of that endpoint, including Spotify routing and the profile gate. `shuffle` on a tag applies to albums, artists and playlists.

## Managing tags (admin token)

```
GET    /api/admin/tags                 → { "data": [Tag] }
GET    /api/admin/tags/last-scanned    → { "data": { "uid", "at" } | null }     an unknown uid tapped in the last 10 minutes
POST   /api/admin/tags                 body: { target_type, target_id, uid?, label?, device_id?, shuffle? }   → 201 { "data": Tag }
PUT    /api/admin/tags/{id}            body: any of { target_type, target_id, label, device_id, shuffle }      → { "data": Tag }   (the uid cannot change)
DELETE /api/admin/tags/{id}            → { "status": "ok" }
```

`target_type` is `album` | `artist` | `playlist` | `track` | `radio` | `physical`; `target_id` must exist. An empty `uid` makes a new one (`QR` + 10 hex characters) for a QR code. A uid that is in use is a 422 validation error.

Tag: `{ id, uid, label, target: { type, id, name, exists }, device: { id, name } | null, shuffle, last_used_at, url }`. `exists` is false when the target was removed (the tag stays and answers `not_playable`); `url` is the QR page.

## QR codes and phones

`GET /t/{uid}` is the page behind a tag's QR code. Opening it plays the target on `?device={id}`, else the tag's own device, else the player pinned in that browser (the player bar's device); with none of those the page asks which device. Opening the page is what plays, so anything that fetches the link plays it too.

## Web

Settings › Tags (`/settings/tags`, admin; Livewire `PlayTagManager`): add a tag (the "use the tag that was just scanned" link fills in an unknown uid a reader tapped), pick what it plays by searching, an optional fixed device and shuffle, and show the QR code to print. Without a library (Control profile) only radio stations can be chosen.

## A reader on an ESP32 (outline)

1. Register as a client device (`docs/api/client-devices.md`) and keep the `api_token`.
2. On a tag: `POST /api/tags/{uid}/play` with `{ "client": "<api_token>" }`. The admin assigns the reader its device under Settings › Client Devices, or gives a tag its own device.
3. `200`: done. `404 unknown_tag`: blink; the admin can now assign the tag. Anything else: show the error.

Not verified: a real reader, and scanning the QR page from a phone outside the dev machine.

## Code

`App\Domain\Tags\PlayTags` (normalise, find, play through `App\Mcp\ApiBridge`), model `App\Models\PlayTag` (table `play_tags`), `Api\TagController`, `TagPageController`, `App\Support\QrCode` (inline SVG, `bacon/bacon-qr-code`), MCP tool `tags`.
