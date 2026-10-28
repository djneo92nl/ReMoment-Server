# Catalogue API: discography and wishlist

Read endpoints of the availability layer ([design](../architecture/availability-layer.md)). Open like the rest of the API (no login), but **not for client devices**: a request with `?client={api_token}` answers `403 forbidden`, and `include=discography` is ignored for a client. Touch clients and the artwork pre-cache never see a release-group nobody owns.

Other providers than Spotify (Deezer, Tidal, media servers) are not built yet; the endpoints below are provider-neutral, so they need a driver and a `LibrarySources` entry, nothing else.

## CatalogueItem

```json
{
  "id": 412,
  "release_group_mbid": "…",
  "title": "Selected Ambient Works Volume II",
  "type": "album",
  "year": 1994,
  "artist": { "id": 17, "name": "Aphex Twin" },
  "state": "streamable",
  "album_id": null,
  "wanted": true,
  "ignored": false,
  "fulfilled": false,
  "availability": [ { "provider": "spotify", "library": "spotify", "status": "available", "playable": true } ],
  "artwork": { "hash": "…", "proxy_120": "…", "proxy_320": "…", "colors": [], "safe_colors": [] }
}
```

- `type`: `album` or `ep` (singles, live albums and compilations are not in the discography).
- `state` is derived, never stored: `owned` (an own-file or DLNA track), `streamable` (only a streaming library, or a lookup provider says it has it), `known` (MusicBrainz only; not playable).
- `album_id` is set when the library has the album: open and play it with the normal album endpoints. `null` means missing from the library, even when `state` is `streamable`.
- `wanted` / `ignored`: the household-wide wish and "I don't want this" (never both). `fulfilled`: wished and now owned, so the wish is closed.
- `availability`: the providers that have it. `playable` appears only with `?device_id=` (the library's per-device check, `LibrarySources::playableOn`).
- `artwork`: the library album's cover, else the MusicBrainz release-group cover through the artwork pipeline; `null` until processed (queued, never waited for).

## Wished songs

A song wished for from the play history, a radio station or by hand (`wanted_tracks`): something to buy or rip, not an album. One wish per artist and title (spelling differences like "The Band" or "(Remastered 2011)" are one song); household-wide.

```json
{ "id": 7, "artist": "Station Band", "title": "Radio Song", "album": "Some LP", "track_id": null, "fulfilled": false, "source": "radio", "wanted_at": "2026-10-28T12:00:00+00:00", "artwork": null }
```

`track_id` is the library track of the song when there is one. The wish closes (`fulfilled`, and it leaves the wishlist) when that track has an own file or is on a DLNA server; saving it in Spotify does not close it.

## Endpoints

```
GET /api/library/artists/{id}/discography?availability=owned|streamable|missing&wanted=1&ignored=1&device_id=   → { data: [CatalogueItem] }
GET /api/library/wishlist?cursor=&device_id=                                                                     → { data: [CatalogueItem], songs: [WantedSong], next_cursor }   `songs` on the first page only
GET /api/library/new-releases?days=90&device_id=                                                                 → { data: [CatalogueItem + release_date] }   new and upcoming albums and EPs of your artists that the library lacks
GET /api/library/catalogue/{id}?device_id=                                                                       → CatalogueItem
GET /api/library/artists/{id}?include=discography[&device_id=]                                                   → the artist detail plus discography: [CatalogueItem]
GET /api/library/albums?availability=owned|streamable                                                            → the usual AlbumItem list, narrowed
POST /api/library/catalogue/{id}/import                                                                          → 202 { status: "queued" }   adds an album Spotify has to the library; 422 not_importable
PUT /api/library/catalogue/{id}/wanted   { "wanted": true }    → { "wanted": true }     household-wide wish; clears "ignored"; a second wish keeps the first timestamp
PUT /api/library/catalogue/{id}/ignored  { "ignored": true }   → { "ignored": true }    "I don't want this": hidden from the discography and wishlist; clears the wish
```

`availability` for Spotify fills in a little after the first look at an artist's discography (a background lookup; reload to see it), then stays for 180 days (7 for a wished album, 90 when Spotify did not have it). Import needs Spotify connected and an entry whose Spotify answer is available; the album shows up linked a moment later.

```
POST   /api/library/wanted-tracks  { artist, title, album?, image_url?, source?: history|radio|manual }   → WantedSong   201 new, 200 when it was already wished
DELETE /api/library/wanted-tracks/{id}                                                                    → { status: "ok" }
```

Both answer `403` for a client token, `422` without artist and title, `404` for an unknown id.

A wish closes by itself: when the linked library album gets an own file (scan, import) or is on a DLNA server, `fulfilled` becomes true and the entry leaves the wishlist; if the files go away it comes back (`catalogue:reconcile`, nightly, is the safety net). Saving the album in Spotify does **not** close it (streamable is not owned).

- The discography is newest first (no year last), entries marked ignored left out unless `ignored=1`. `availability=missing` means "not in the library" (`album_id` is null), so it includes entries a service could stream.
- The wishlist is wanted, not fulfilled, not ignored; most recently wished first; 50 per page.
- `?availability=` on `/library/albums` accepts `owned` and `streamable` only; **`missing` is a `422`**, because missing albums have no album row (use the discography). Without the parameter nothing changes, and a catalogue entry never appears in any existing list, count, shuffle or artwork list.
- Errors: `403 forbidden` for a client token, `404` unknown id, `422` validation.

Catalogue ids follow the stable-id rule of `docs/api/library.md`: after `library:merge-duplicates` folds two release-group rows into one, the old id still works on `GET`/`PUT /api/library/catalogue/{id}` and the response carries `canonical_id` (the surviving id; `id` in a CatalogueItem is always the surviving one). A really unknown id is `404`.

MCP: the `catalogue` tool (`discography`, `wishlist`, `item`, `wish`, `unwish`, `ignore`, `unignore`). The PUT endpoints answer `403` for a client token, `404` unknown id, `422` without the boolean.

## External catalogues: browse, play and add

What a service offers, for **any** artist, not only the ones in the library. Spotify is the first provider (`config/availability.php`, `App\Domain\Availability\SpotifyBrowser`); results are transient (nothing is stored) and each says whether the library already has it. Needs the service connected (`422 not_connected`), and is closed to client tokens like the rest of the catalogue (`403`); an unknown provider is `404 unknown_provider`, a service that does not answer `502 driver_error`.

```json
{ "provider": "spotify", "type": "album", "id": "4aawyAB9vmqN3uQ7FjRGTy", "title": "Selected Ambient Works Volume II", "artist": "Aphex Twin",
  "year": "1994", "track_count": 25, "image": "https://…", "in_library": false, "library_id": null }
```

`type` is `artist`, `album` or `track`; `library_id` is the library's artist, album or track id when `in_library`. `image` is the service's own URL: show it through the artwork pipeline, never hot-linked.

```
GET  /api/library/external/{provider}/search?q=&type=artist|album|track&limit=   → { data: [ExternalItem] }   q at least 2 characters; Spotify answers at most 10
GET  /api/library/external/{provider}/artists/{id}                                → { artist: ExternalItem, albums: [ExternalItem] }   albums newest first
GET  /api/library/external/{provider}/albums/{id}                                 → { album: ExternalItem, tracks: [ExternalItem] }
POST /api/library/external/{provider}/add   { type, id, album_ids? }              → 202 { status: "queued", queued: { albums, tracks } }
POST /api/devices/{id}/library/play-external { provider, type, id, shuffle? }     → { status: "ok", provider, type, id }
```

- **Add** queues the import; the records appear in the library a moment later (through `LibraryIdentity`, source = the provider, so they merge with copies from other sources and get enriched). An artist adds the given `album_ids`, else **all** its albums the library does not have yet (at most 100 per call).
- **Play** needs no import: Spotify plays the album or artist as its context, a track by URI, on a device that can play Spotify (the Spotify device, or a speaker mapped to a Connect name). `422 not_playable` otherwise (`LibrarySources::playableOn`), `503` unreachable, `502 driver_error`.
- MCP: the `external` tool (`search`, `artist`, `album`, `play`, `add`).
- Web: the search box offers Spotify's artists, albums and tracks with Play (on the player pinned in this browser) and Add.

## New releases

`GET /api/library/new-releases?days=90` lists the albums and EPs of the library's artists released in the last `days` days (1-365, default 90) **or still to come**, that the library does not have and nobody marked "not interested", newest first (up to 50). It is the catalogue's own rows: each item carries `release_date` (`YYYY-MM-DD`; entries MusicBrainz only dates by year are left out). Closed to client tokens (`403`); MCP: `catalogue` action `new_releases`; web: the *New from your artists* shelf on `/library` (shown only when there is something).

How it fills: `php artisan catalogue:new-releases` (daily 02:15; `--days=30`, `--dry-run`) reads ListenBrainz's fresh-releases feed once (`GET https://api.listenbrainz.org/1/explore/fresh-releases/`, at most 90 days, past and future) and, for every library artist with an album or EP release-group the catalogue does not hold yet, queues that artist's MusicBrainz discography sync at once instead of waiting for its monthly turn. The feed is only a trigger: nothing is stored from it, MusicBrainz stays the authority.

