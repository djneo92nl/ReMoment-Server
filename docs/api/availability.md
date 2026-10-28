# Catalogue API: discography and wishlist

Read endpoints of the availability layer ([design](../architecture/availability-layer.md)). Open like the rest of the API (no login), but **not for client devices**: a request with `?client={api_token}` answers `403 forbidden`, and `include=discography` is ignored for a client. Touch clients and the artwork pre-cache never see a release-group nobody owns.

The wish and ignore endpoints, browse/play/add from external sources and the Spotify lookups are not built yet (design PRs 6 to 8).

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

## Endpoints

```
GET /api/library/artists/{id}/discography?availability=owned|streamable|missing&wanted=1&ignored=1&device_id=   → { data: [CatalogueItem] }
GET /api/library/wishlist?cursor=&device_id=                                                                     → { data: [CatalogueItem], next_cursor }
GET /api/library/catalogue/{id}?device_id=                                                                       → CatalogueItem
GET /api/library/artists/{id}?include=discography[&device_id=]                                                   → the artist detail plus discography: [CatalogueItem]
GET /api/library/albums?availability=owned|streamable                                                            → the usual AlbumItem list, narrowed
```

- The discography is newest first (no year last), entries marked ignored left out unless `ignored=1`. `availability=missing` means "not in the library" (`album_id` is null), so it includes entries a service could stream.
- The wishlist is wanted, not fulfilled, not ignored; most recently wished first; 50 per page.
- `?availability=` on `/library/albums` accepts `owned` and `streamable` only; **`missing` is a `422`**, because missing albums have no album row (use the discography). Without the parameter nothing changes, and a catalogue entry never appears in any existing list, count, shuffle or artwork list.
- Errors: `403 forbidden` for a client token, `404` unknown id, `422` validation.

MCP: the `catalogue` tool (`discography`, `wishlist`, `item`).
