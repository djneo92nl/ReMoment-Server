# Physical collection API

CDs, records and tapes on the shelf (workstream 6, `docs/architecture/v1-roadmap.md`). An item stands on its own (artist, title, year, medium) and is tied to the library album and the MusicBrainz release-group when there is one. It is the **`physical` availability provider**: an item makes its album **owned**, so it closes a wish on that release-group and shows `Owned` in the discography ([availability.md](availability.md)). Open like the rest of the API (no login), closed to client tokens (`403`).

```json
{ "id": 3, "artist": "Aphex Twin", "title": "Selected Ambient Works 85-92", "year": 1992, "medium": "vinyl", "barcode": "5024396991122",
  "catalogue_number": "AMB 3922", "condition": "VG+", "notes": null, "location": "Shelf B2", "acquired_on": "2024-03-01",
  "album_id": null, "release_group_mbid": "…", "ripped": false, "ripped_at": null }
```

`medium` is `cd`, `tape`, `vinyl`, `minidisc` or `other`. `album_id` is the library album with the same artist and title (set when the item is added, or later when the album appears, or the album a ripped disc landed in, see [Ripped discs](#ripped-discs)); `ripped` is set by hand, or by itself when the disc is ripped. A barcode is stored as digits only.

```
GET    /api/library/physical?medium=&q=&ripped=&cursor=   → { data: [Item], next_cursor }   by artist, then title; 50 a page; q matches artist, title or barcode
GET    /api/library/physical/lookup?barcode=              → { data: [Release] }   what MusicBrainz knows for the barcode, to choose from (one request)
POST   /api/library/physical                              → 201 Item   { artist, title, medium (required), year, barcode, catalogue_number, condition, notes, location, acquired_on, release_group_mbid, release_mbid }
GET    /api/library/physical/{id}                         → Item
PUT    /api/library/physical/{id}                         → Item   any of the fields above, and `ripped: true|false`
DELETE /api/library/physical/{id}                         → { status: "ok" }
```

`Release` is `{ release_mbid, release_group_mbid, artist, title, year, label, catalogue_number, country, medium }`: pass the chosen one's ids and fields to `POST` (the web page's "Use this"). The lookup answers `502 driver_error` when MusicBrainz does not (the web page then offers the form by hand). Several items can share an album (a CD and a vinyl). Removing the last item of a release-group opens its wish again.

## CSV import

Bulk entry in two steps, so nothing is added before you have seen what would happen to each row.

```
POST /api/library/physical/import                    → preview   multipart `file` or `csv` text; optional `default_medium`, `mapping`, `rows`
POST /api/library/physical/import/{token}/confirm    → { status: "ok", added, queued_lookups, skipped_duplicates, skipped_invalid }
```

**Preview** writes nothing and answers `{ status: "preview", token, expires_in: 3600, format: "discogs"|"generic", mapping, unmapped, summary, truncated, data }`. `summary` is `{ rows, new, lookup, duplicate, invalid }`; `data` has one entry per row, `{ row, status, reason, duplicate_of, artist, title, medium, year, barcode, location, catalogue_number, condition, notes, acquired_on }`, where `row` is the line of the file (the header is line 1) and `status` is:

| status | meaning |
|---|---|
| `new` | will be added as it is |
| `lookup` | has a barcode: after the import a queued job asks MusicBrainz for the release and ties the item to it (so a wish on that release-group closes). A row **with only a barcode** (no artist, no title) is added by that job from the release, with `artist` and `title` null in the preview |
| `duplicate` | the collection already has this barcode (UPC-A and EAN-13 spellings of one code count as the same), or this artist, title and medium (the same name keys as the library: "The Band" is "Band", "(Remastered)" is ignored), or an earlier row of the file has; the first one wins. `duplicate_of` is the existing item. Add a second copy by hand with `POST /api/library/physical` |
| `invalid` | `reason` says why: no medium (give a medium or format column, or `default_medium`), artist without title or the reverse, neither a name nor a barcode, a barcode that is not 6 to 20 digits (a barcode a spreadsheet turned into `6.02537E+12` is named as that), a field too long |

**Files.** A Discogs collection export is recognised by its headers (`Catalog#`, `Artist`, `Title`, `Format`, `Released`, `release_id`, `CollectionFolder`, `Date Added`, the condition and notes columns): "Nirvana (2)" becomes "Nirvana", a format such as "Vinyl, LP, Album" or "2xCD" becomes a medium, the sleeve condition goes into the notes, a folder other than Uncategorized becomes the location. Any other CSV needs a header line; columns are matched by name (case ignored): artist (also artist name, band, performer), title (album, release), medium (format, media), year (released, release year), barcode (upc, ean, gtin), location (shelf, box), catalogue_number (catalog#, cat#), condition, notes (comments), acquired_on (date added). `mapping` names a column explicitly: `mapping[artist]=Performer`. The delimiter (comma, semicolon, tab) and the encoding (UTF-8 with or without a byte order mark, or Windows-1252) are detected. At most 2 MB and 5000 rows; a file that is none of this is `422 invalid` with the reason. `format` and `unmapped` (columns that were not used) tell what was understood. `rows` limits how many rows `data` lists (`truncated` is then true); the summary always counts all.

**Confirm** takes the `token` of a preview (valid for an hour, used up by the confirm; `404 not_found` after that): the rows are classified again, so a row someone added meanwhile is skipped. `added` counts items created now; `queued_lookups` the MusicBrainz lookups queued. They are never made inline: `LookupPhysicalBarcode` jobs run behind the shared `musicbrainz` rate limiter, started two seconds apart, so a few hundred barcodes take some minutes and cost no request time; an outage is retried for up to 12 hours. A lookup ties an item to a release only when the release is by the same artist (the title may differ by edition) and prefers the release of the item's medium. A barcode-only row that MusicBrainz does not know, or that could not be looked up at all, is kept as "Unknown artist" / "Barcode …" with a note, searchable by its barcode, rather than lost.

Web: the "Import a CSV" card on `/library/collection` (upload or paste, the preview table with a filter per status, then "Import N rows"). MCP: `physical` action `import`: with `csv` it previews (the first 100 rows are listed), with the returned `token` it adds.

## Ripped discs

The ReMoment Ripper tags what it rips with `MUSICBRAINZ_ALBUMID` (the release it chose) and uploads through `POST /api/admin/files`. When a file arrives (through that upload, the inbox import or a scan: `FileIndexer::attach` calls `Domain\Catalogue\PhysicalRipLink`) and the release id in its tags, or a `BARCODE` tag, is the `release_mbid` or barcode of a **CD** item, that item is marked `ripped`, its `album_id` becomes the album the file landed in (replacing the one it was tied to by name), and its `file_id` the first file of the rip. The web list then shows "Ripped" as a link to the album. The release id is on the item when it was added from a barcode lookup (the web page's "Use this", or the import's lookup), so pick the same release in the ripper as on the shelf. Not matched, mark the item ripped by hand: vinyl and tape items (a disc rip is of a CD), and a different pressing of the same release-group (its release id and barcode differ). Unverified: the `BARCODE` tag in m4a files, and a rip with the real ripper app (the tests upload a FLAC tagged as the ripper tags it).

MCP: the `physical` tool (`list`, `lookup`, `add`, `update`, `remove`, `import`). Web: `/library/collection`. Not built yet: CSV export, a phone-camera barcode scan, and the changer / tape-deck device links (workstream 2a).
