# Physical collection API

CDs, records and tapes on the shelf (workstream 6, `docs/architecture/v1-roadmap.md`). An item stands on its own (artist, title, year, medium) and is tied to the library album and the MusicBrainz release-group when there is one. It is the **`physical` availability provider**: an item makes its album **owned**, so it closes a wish on that release-group and shows `Owned` in the discography ([availability.md](availability.md)). Open like the rest of the API (no login), closed to client tokens (`403`).

```json
{ "id": 3, "artist": "Aphex Twin", "title": "Selected Ambient Works 85-92", "year": 1992, "medium": "vinyl", "barcode": "5024396991122",
  "catalogue_number": "AMB 3922", "condition": "VG+", "notes": null, "location": "Shelf B2", "acquired_on": "2024-03-01",
  "album_id": null, "release_group_mbid": "…", "ripped": false, "ripped_at": null }
```

`medium` is `cd`, `tape`, `vinyl`, `minidisc` or `other`. `album_id` is the library album with the same artist and title (set when the item is added, or later when the album appears); `ripped` is set by hand now and by CD import (workstream 7) later.

```
GET    /api/library/physical?medium=&q=&ripped=&cursor=   → { data: [Item], next_cursor }   by artist, then title; 50 a page; q matches artist, title or barcode
GET    /api/library/physical/lookup?barcode=              → { data: [Release] }   what MusicBrainz knows for the barcode, to choose from (one request)
POST   /api/library/physical                              → 201 Item   { artist, title, medium (required), year, barcode, catalogue_number, condition, notes, location, acquired_on, release_group_mbid, release_mbid }
GET    /api/library/physical/{id}                         → Item
PUT    /api/library/physical/{id}                         → Item   any of the fields above, and `ripped: true|false`
DELETE /api/library/physical/{id}                         → { status: "ok" }
```

`Release` is `{ release_mbid, release_group_mbid, artist, title, year, label, catalogue_number, country, medium }`: pass the chosen one's ids and fields to `POST` (the web page's "Use this"). The lookup answers `502 driver_error` when MusicBrainz does not (the web page then offers the form by hand). Several items can share an album (a CD and a vinyl). Removing the last item of a release-group opens its wish again.

MCP: the `physical` tool (`list`, `lookup`, `add`, `update`, `remove`). Web: `/library/collection`. Not built yet: CSV import and export, a phone-camera barcode scan, and the changer / tape-deck device links (workstream 2a).
