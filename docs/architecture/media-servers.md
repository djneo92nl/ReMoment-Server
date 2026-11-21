# Media servers (workstream 15, step 1)

One abstraction for the media servers a household runs (Jellyfin now; Plex, Emby and Kodi later), so a second server type is a driver and a declaration, with no change in the web card, the REST API or the MCP tool. Built 2026-11-21 as the roadmap's build-order step 1: **Jellyfin was moved onto the abstraction without changing what it does.** Decided by the owner on 2026-11-20: **Jellyfin is the only media-server driver until after 1.0**, so there is no second driver yet; the contract below is what Plex and Emby follow. Everything here is **unverified against a real Jellyfin server** (as before).

## Parts

| Part | Where |
|---|---|
| Type registry | `config/media-servers.php`: per type `label`, `driver` (class), `credentials` (the `ServiceCredentials` service the key is read from), `probe` (the `ExternalServices` probe id), `port`, `client_packages` (the Android TV package of the type's app), `icon`. Read it only through `App\Domain\MediaServers\MediaServers` |
| Servers | table `media_servers` (`App\Models\MediaServer`): `id`, `driver`, `name`, `base_url` (null = the base URL of the credentials), `credential` (the `ServiceCredentials` id, null = the type's own), `enabled`, `meta` JSON, timestamps |
| Contracts | `app/Integrations/Contracts/`: `MediaServerDriverInterface` (`configured()`, `canPlayOn(Device)`), capability interfaces `ContinueWatchingInterface`, `NextUpInterface`, `PlaysOnClientInterface`, `ReportsClientNowPlayingInterface`; `App\Domain\MediaServers\MediaServerCapabilities` maps them to the strings `continue_watching`, `next_up`, `play_on_client`, `sessions` |
| Normalised item | `App\Domain\Media\MediaItem` (next to `NowPlaying`): `serverId`, `itemId`, `kind` (movie, episode, series, season, album, track), `title`, `subtitle`, `year`, `image`, `progress` (0-100), `resumePosition` (seconds), `duration` (seconds), `library`; `id()` is namespaced `{server id}:{item id}` |
| Registry and link | `App\Domain\MediaServers\MediaServers`: `types()`, `driver($server)`, `capabilities()`, `forDevice($device, $type?)`, `clientPackages()`, `isClientPackage()`, `syncFromCredentials()` |
| Per device | `App\Domain\MediaServers\DeviceMedia`: `available`, `idle`, `shelves` (merged, cached per server for 60 s), `play`, `nowPlaying`. The web card `Livewire\MediaShelf`, `MediaController`, `JellyfinController` and the MCP tools share it |
| Jellyfin | `app/Integrations/Jellyfin/`: `JellyfinDriver` (implements all four capabilities), `JellyfinClient` (one server's REST API), `JellyfinItems` (API item to `MediaItem`, ticks), `JellyfinNowPlaying` (a session to a `NowPlaying`, as before) |

Added on top of the old code: the registry, the table, the generic routes. Removed: `JellyfinTv` and `JellyfinShelf` (their work is `DeviceMedia` and `JellyfinItems`), the global `JellyfinClient::configured()/baseUrl()` statics (now per server), the Philips listener's own Jellyfin check (now `DeviceMedia::nowPlaying()`), the Livewire `JellyfinShelf` (now `MediaShelf`, a card that draws any server's shelves).

## The single Jellyfin setting becomes one row

The key, user id and base URL stay where they are, on **Settings › Services › Jellyfin** (the credentials layer, [credentials.md](credentials.md)); the row **points at them** (`credential = 'jellyfin'`, `base_url = null`) and copies no secret. Two things create the row:

- the data migration `import_jellyfin_setting_into_media_servers`: when the Jellyfin credentials are complete (settings or `.env`) and no Jellyfin row exists, one row is inserted (`name` Jellyfin, enabled). Idempotent, reads only, `down()` does nothing. A data migration: leave it out of the schema squash, only `create_media_servers_table` is schema.
- the Jellyfin declaration's `onChanged` hook (`MediaServers::syncFromCredentials('jellyfin')`), so saving a complete key later creates the row too. It never changes or deletes an existing row: a renamed or disabled row stays as it is.

Not covered: a key that is only ever set in `.env` **after** the migration ran, and never saved on the page, has no row. Saving the page once creates it. A row with its own `base_url` overrides the credentials' address; that is the seam for a second server of the same type (its secrets would then need their own `credential`, not built).

A server whose credentials are incomplete, or that is disabled, applies to no device and is left out of the shelves; it stays listed in `GET /api/media-servers` (`configured`, `enabled`).

## Link to devices

Kept minimal: **no table**. A server applies to a device when it is enabled and configured, its driver says `canPlayOn($device)` (Jellyfin: a Philips Android TV, the same rule as before: `JellyfinTv::available()` required the Philips driver) and, when the device is limited, the server id is in the device meta key `media_servers` (a JSON list of ids, e.g. `[3]`; set it with a `device_meta` row, no UI or API yet). A device without that meta gets every server its driver can play on, which is what the old single-server setup did. The richer link of roadmap step 3 (`device_links` with kind `app`, found from the server's session list) replaces this when it is built.

## Behaviour that stayed

- The Philips listener reports a server's app now playing (every 5 s while the app is in front) through the driver's `sessions` capability, with the same `NowPlaying` as before.
- The TV page's card shows while a server's app is in front and plays nothing, loads after the page (`wire:init`), keeps the lists for a minute, plays on tap. The card draws the generic items: tap sends the item's namespaced id and its `resume_position` in seconds.
- `GET /api/devices/{id}/jellyfin` and `POST …/jellyfin/play` answer exactly as before (tiles with `ticks`, `item_id`, `position_ticks`, the same errors); they are wrappers over `DeviceMedia` limited to Jellyfin servers. The operation ids `jellyfinLists` and `jellyfinPlay` are kept. The roadmap said to remove them once the iOS app was checked; that check has not been done, so they stay and are **not** removed in this step.
- MCP tool `jellyfin` is unchanged (it calls the wrapper); `media_servers` is new and calls the generic routes.

## Adding a media server type (Plex, Emby, Kodi)

1. A driver class implementing `MediaServerDriverInterface` and the capability interfaces it supports (a server that cannot do one simply does not implement it: no list is built for it, no `play_on_client`). It normalises to `MediaItem` (ids as strings, seconds, `progress` 0-100) and throws on a server that does not answer.
2. An entry in `config/media-servers.php` (driver, credentials service id, probe id, port, the client app package).
3. A credentials declaration in `ServiceCredentials::declare()` (with an `onChanged` that calls `MediaServers::syncFromCredentials('{type}')` if it should appear by itself) and a probe in `ExternalServices`.
4. Tests with `Http::fake`. If adding it needs a change in `DeviceMedia`, a controller, the card or an MCP tool, the abstraction is wrong: fix the abstraction.

Known pressure points for the second driver: `canPlayOn` and `client_packages` are Android TV shaped (the Plex Companion and Kodi JSON-RPC targets are not apps on a TV the Philips driver can see); the `credential` is one service per type, not per server; the shelves have only `continue_watching` and `next_up`.

## Not built (roadmap 15, later steps)

Several servers of one type with their own keys, the `device_links` link, `recently_added`, `search`, `libraries`, `item_detail`, `mark_watched`, music and the provider vocabulary, webhooks and alerts, any second driver, the admin routes and UI to add, edit or remove a server (today the row comes from the Jellyfin credentials; edit `media_servers` or the device meta by hand), and removing the Jellyfin-only routes.

## Tests

`tests/Feature/MediaServersTest` (registry, capabilities, the credentials hook, the link and its device meta, the migration, the generic routes, the Jellyfin wrappers, now playing, the MCP tool), `tests/Unit/MediaItemTest` (namespaced ids, Jellyfin items and ticks), `tests/Feature/JellyfinShelfTest` (the card, the wrappers, the MCP `jellyfin` tool), `tests/Unit/JellyfinNowPlayingTest`.
