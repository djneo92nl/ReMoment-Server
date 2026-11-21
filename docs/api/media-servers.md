# Media servers (REST API)

The generic API for the media servers of a household (Jellyfin today; docs/architecture/media-servers.md). Open like the rest of the API, no accounts. **Unverified against a real server.** `GET /api/devices/{id}/jellyfin` and `POST …/jellyfin/play` are the Jellyfin-only forms of the two device routes, see [jellyfin.md](jellyfin.md).

```
GET  /api/media-servers
  → { data: [Server] }
GET  /api/devices/{id}/media
  → { active, servers: [{ id, name, driver, ok }], continue_watching: [Item], next_up: [Item] }
POST /api/devices/{id}/media/play   { item, server?, position? }
  → { status: "ok", item }
```

`Server` is `{ id, name, driver, label, base_url, enabled, configured, capabilities }`: `driver` the type (`jellyfin`), `configured` whether its credentials are complete (Settings › Services; one that is not applies to no device), `capabilities` from `continue_watching`, `next_up`, `play_on_client`, `sessions`. Never a key.

`Item` (the same for every server type; times in seconds):

```json
{ "id": "3:abc", "server_id": 3, "item_id": "abc", "kind": "episode", "title": "The Show",
  "subtitle": "S2E3 · Pilot", "year": null, "image": "http://jf:8096/Items/ser1/Images/Primary?maxWidth=400",
  "progress": 25, "resume_position": 1800, "duration": 3000, "library": null }
```

`kind` is `movie`, `episode`, `series`, `season`, `album` or `track`; `title` the film or the series; `subtitle` the year or `S2E3 · Episode name`; `image` a poster on the media server (the web UI shows it through the artwork pipeline; a client may load it itself on the LAN); `progress` 0-100 of what was watched; `resume_position` where to resume. `id` is namespaced by the server, so lists of several servers mix.

`GET /api/devices/{id}/media` merges the shelves of every server that applies to the device (enabled, configured, its driver can play on that kind of device, and the device's meta `media_servers` when it is limited), in server order, each read and cached for one minute on its own. `active` is true while the device shows a server's app and nothing plays in it (the web card shows only then; the lists are returned regardless). A server that does not answer is listed with `ok: false` and the others still show; no server answering is a `502`.

`POST …/media/play` starts the item in the server's app on that device. `item` is a listed item's `id` (`3:abc`); a bare item id works while exactly one server applies, or with `server`. `position` is seconds (a listed item's `resume_position` to resume). For Jellyfin this is `POST /Sessions/{sessionId}/Playing?playCommand=PlayNow` to the session whose address is the TV's; the app has to be running and connected to the server.

Errors: `422 unsupported` (no server applies to the device, a bare item id with several servers, a server that does not apply, or one that cannot play), `422` validation (`item`, `server`, `position`), `502 driver_error` (the server did not answer, or the app on the device has no session).

MCP tool `media_servers` (no `device_id`: the servers; with it: the device's shelves; with `item` too: play).
