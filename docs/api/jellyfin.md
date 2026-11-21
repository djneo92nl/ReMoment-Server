# Jellyfin on a TV (REST API)

For a paired Philips Android TV with a Jellyfin server key set (Settings › Services › Jellyfin: key, optional user id, base URL). Since the media-server abstraction ([media-servers.md](media-servers.md), [../architecture/media-servers.md](../architecture/media-servers.md)) these two routes are **thin wrappers** over the generic `GET /api/devices/{id}/media` and `POST …/media/play`, limited to Jellyfin servers and kept in their old shape for the clients that use them (the generic form has normalised items, several servers and seconds instead of ticks). Code: `App\Domain\MediaServers\DeviceMedia` (shared by the generic API, these routes, the MCP tools `jellyfin` and `media_servers` and the web card `Livewire\MediaShelf`) and `App\Integrations\Jellyfin\JellyfinDriver`. **Unverified against a real Jellyfin server.**

```
GET  /api/devices/{id}/jellyfin
  → { active, continue_watching: [Tile], next_up: [Tile] }
POST /api/devices/{id}/jellyfin/play   { item_id, position_ticks? }
  → { status: "ok", item_id }
```

`Tile` is `{ id, title, subtitle, image, progress, ticks }`: `title` the film or series, `subtitle` the year or `S2E3 · Episode name`, `image` a poster URL on the Jellyfin server (the web UI shows it through the artwork pipeline; a client may load it itself on the LAN), `progress` 0-100 of what was watched, `ticks` the resume position (10 000 000 per second) to pass as `position_ticks`. A tile is a `MediaItem` of the generic API: `id` is its `item_id`, `ticks` is `resume_position` in ticks.

`active` is true while the TV shows its Jellyfin app and nothing plays in it (the web card shows only then; the lists are returned regardless). The lists are cached for one minute.

`play` starts the item in the Jellyfin app on that TV (`POST /Sessions/{sessionId}/Playing?playCommand=PlayNow` on the Jellyfin server, addressed to the session whose address is the TV's). The app has to be running and connected to the server.

With more than one Jellyfin server the play route cannot tell which one is meant (`422 unsupported`): use `POST /api/devices/{id}/media/play` with the item's namespaced `id`.

Errors: `422 unsupported` (not a Philips TV, or no enabled Jellyfin server with a key), `422` validation (`item_id`), `502 driver_error` (Jellyfin did not answer, or the app on the TV has no session).
