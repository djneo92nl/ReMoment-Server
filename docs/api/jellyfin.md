# Jellyfin on a TV (REST API)

For a paired Philips Android TV with a Jellyfin server key set (Settings › Services › Jellyfin: key, optional user id, base URL). Code: `App\Integrations\Jellyfin\JellyfinTv` (shared by this API, the MCP tool `jellyfin` and the web card `Livewire\JellyfinShelf`). **Unverified against a real Jellyfin server.**

```
GET  /api/devices/{id}/jellyfin
  → { active, continue_watching: [Tile], next_up: [Tile] }
POST /api/devices/{id}/jellyfin/play   { item_id, position_ticks? }
  → { status: "ok", item_id }
```

`Tile` is `{ id, title, subtitle, image, progress, ticks }`: `title` the film or series, `subtitle` the year or `S2E3 · Episode name`, `image` a poster URL on the Jellyfin server (the web UI shows it through the artwork pipeline; a client may load it itself on the LAN), `progress` 0-100 of what was watched, `ticks` the resume position (10 000 000 per second) to pass as `position_ticks`.

`active` is true while the TV shows its Jellyfin app and nothing plays in it (the web card shows only then; the lists are returned regardless). The lists are cached for one minute.

`play` starts the item in the Jellyfin app on that TV (`POST /Sessions/{sessionId}/Playing?playCommand=PlayNow` on the Jellyfin server, addressed to the session whose address is the TV's). The app has to be running and connected to the server.

Errors: `422 unsupported` (not a Philips TV with a Jellyfin key), `422` validation (`item_id`), `502 driver_error` (Jellyfin did not answer, or the app on the TV has no session).
