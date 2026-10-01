# Library API

Browse the shared media library, keep household favorites, and play albums or artists on a device. All endpoints are under `/api`, unauthenticated like the rest of the API. Controller: `app/Http/Controllers/Api/LibraryController.php` (browse, favorites) and `Api/DeviceController` (`library/*` playback); playback logic: `app/Domain/Library/LibraryPlayback.php`, shared with the web Album/Artist/Playlist/track play buttons.

## Shapes

**Artwork** — `{ "hash": "<md5 of the cover URL>", "proxy_120": "…", "proxy_320": "…" }`, or `null` when the cover is not processed yet (or there is none). URLs have the same form as elsewhere (prefixed with `APP_URL`, take the path from `/storage/` and prefix `artwork_base_url` from `GET /api/info`); `hash` is the same as in the pre-cache list (`GET /api/clients/{api_token}/artwork`) and the SD card export, so a client can use its cached files. A missing cover is queued for processing at most once an hour per image (`App\Domain\Artwork\LibraryItemArtwork`); requests never wait for a download.

**ArtistItem** — `{ "id": 1, "name": "Muse", "album_count": 4, "artwork": Artwork|null, "favorite": false }`. `artwork` is the cover of the artist's most played album with an image.

**AlbumItem** — `{ "id": 12, "name": "Absolution", "artist_name": "Muse", "year": 2003|null, "artwork": Artwork|null, "favorite": false }`.

`year` comes from `albums.released_at`. Artists, albums and tracks are the library's rows, **one per artist/album/track whatever the source**: a DLNA scan, the Spotify import and plays resolve to the same record by a normalized name (case, "The ", diacritics, punctuation, "feat." credits and edition suffixes like "(Remastered 2011)" or "- Deluxe Edition" ignored; "(Live)" and the like kept apart). A track can then have both a DLNA URL and a Spotify URI. See `docs/architecture/library-identity.md`.

### Merged records and stale ids

Duplicates from before that are merged by an admin running `php artisan library:merge-duplicates`: the oldest record keeps its id, the others' **ids disappear**. A client holding such an id gets `404` from `GET /api/library/artists/{id}`, `GET /api/library/albums/{id}` and the `PUT …/favorite` endpoints, and a `422` validation error for `album_id`/`artist_id`/`track_id`/`start_track_id` in the play requests. Treat either as "gone": drop the cached id and re-read the list (`/api/library/artists`, `recent`, `favorites`), where the merged record appears with its surviving id. Favorites survive a merge (the earliest `favorited_at` is kept). Artwork `hash`es are per cover URL, so cached covers stay valid.

## Browse

### `GET /api/library/artists?cursor=`

`{ "data": [ ArtistItem, … ], "next_cursor": "…"|null }` — artists with at least one album, alphabetical (case-insensitive, a leading "The " ignored), 50 per page. Pass `next_cursor` back as `cursor` until it is `null`. The cursor is opaque; an invalid one returns Laravel's `422` validation error.

### `GET /api/library/artists/{id}`

`{ "id", "name", "favorite", "albums": [ AlbumItem, … ] }` — albums newest year first (unknown year last), then by name.

### `GET /api/library/albums/{id}?device_id=`

```json
{
  "id": 12,
  "name": "Absolution",
  "artist": { "id": 1, "name": "Muse" },
  "year": 2003,
  "artwork": { "hash": "…", "proxy_120": "…", "proxy_320": "…" },
  "favorite": false,
  "playable": true,
  "tracks": [ { "id": 34, "name": "Intro", "duration": 213, "playable": true } ]
}
```

Tracks are in best-effort album order (by id — there is no track number column). `duration` is seconds or `null`. A track is `playable` when the server has a stream for it: a DLNA URL, or a Spotify URI. With the optional `device_id`, `playable` is for that device: DLNA tracks need a device with a DLNA library driver, Spotify tracks a device that can play Spotify (below). The album is `playable` when any track is.

### `GET /api/library/recent?limit=30`

`{ "data": [ AlbumItem, … ] }` — the most recently played distinct albums, newest first (`LibraryArtwork::lastPlayedPerAlbum()`, the same recency as the pre-cache list). `limit` 1–100, default 30.

## Favorites

Household-wide (there are no user accounts): `albums.favorited_at` / `artists.favorited_at`, `null` when not a favorite.

```
PUT /api/library/albums/{id}/favorite    body: { "favorite": true }   → { "favorite": true }
PUT /api/library/artists/{id}/favorite   body: { "favorite": true }   → { "favorite": true }
GET /api/library/favorites               → { "albums": [ AlbumItem ], "artists": [ ArtistItem ] }
```

Favorites are listed most recently favorited first; favoriting an item again keeps its original time. The web album and artist pages show a heart that toggles the same flag (`POST /albums/{id}/favorite`, `POST /artists/{id}/favorite`).

## Play on a device

```
POST /api/devices/{id}/library/play-album   body: { "album_id": 12, "start_track_id": 34, "shuffle": false }  → { "status": "ok", "album": "Absolution" }
POST /api/devices/{id}/library/play-artist  body: { "artist_id": 5, "shuffle": true }                         → { "status": "ok", "artist": "Muse" }
```

`start_track_id` (optional) must be a track of that album; playback starts there and continues to the end of the album. `shuffle` (optional, default `false`) shuffles the order (after the start track, if given). An artist plays all its library tracks, grouped by album.

Two paths, tried in this order:

1. **DLNA** — when the device's driver implements `LibraryPlaybackInterface` (B&O ASE, Sonos, Mozart) and at least one track has a DLNA URL (`dlna_url` metadata from a DLNA scan). The tracks are queued on the device; tracks without a URL are skipped.
2. **Spotify** — when Spotify is connected, the device is the Spotify virtual device or a local device mapped to a Spotify Connect name (`/settings/spotify-connect`, the mapping the Spotify listener routes by), and at least one track has a Spotify URI (`external_id` `spotify:track:…`: tracks from the Spotify library import or from playing Spotify — or kept as `external_id` metadata with source `spotify` on a track that is also on DLNA). The server looks up the Connect device by its mapped name in `GET /v1/me/player/devices` (the Spotify device plays on whatever Connect device is active) and calls `PUT /v1/me/player/play`:
   - an **album** plays as its Spotify album context — the full album, also tracks not in the library — offset at the start track (or a random library track when shuffling). The album URI is looked up once from one of its tracks (`GET /v1/tracks/{id}`) and stored as album metadata `spotify_album_uri`. If that fails, the album's library tracks are played as a list of URIs;
   - an **artist** plays its library tracks as a list of URIs (at most 100);
   - then shuffle is set to `shuffle` on that device (best effort).

The device then reports the playback through its listener as usual (for a mapped speaker via Spotify routing, see CLAUDE.md).

Errors:

| Status | Body | When |
|---|---|---|
| `422` | `{ "error": "not_playable", "message": "…" }` | Neither path works for this device and album/artist (no DLNA driver or DLNA tracks; not Spotify-capable or no Spotify tracks) |
| `422` | Laravel validation `{ "message", "errors" }` | Unknown `album_id`/`artist_id`, `start_track_id` not on the album |
| `503` | `{ "error": "unreachable", … }` | The device is unreachable |
| `502` | `{ "error": "driver_error", "message": "…" }` | The device or Spotify refused or didn't respond, or Spotify doesn't currently list the mapped Connect speaker (it may be asleep) |

The same paths now also serve the older endpoints: `POST /api/devices/{id}/library/play` (`track_id`) falls back to Spotify for a Spotify track, and `library/play-playlist` plays a Spotify playlist as its Spotify context. Their error shapes are unchanged (`no_dlna_url` on DLNA devices).

### Capability

A device lists `library_playback` in `capabilities` when either path exists for it: a DLNA library driver, or (Spotify connected and) being the Spotify device or mapped to a Spotify Connect name (`App\Domain\Device\DeviceCapabilities`). It says nothing about a particular album — use the album's `playable` with `device_id` for that.
