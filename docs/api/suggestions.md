# Tag and cover suggestions

Roadmap 12.4. A review list of what MusicBrainz says about the own files and albums that disagree with it. It is the `suggestions` report of the library health reports ([library-health.md](library-health.md)), so listing, counts, paging and the web page come with them; accepting and dismissing are two admin routes. **Nothing is applied by itself.**

## List

`GET /api/library/health/suggestions` (open, **Full profile** like the other analysis reports: it needs the AcoustID fingerprint; `not_available_in_profile` below). The usual report envelope, and every finding has a `suggestion` object (null in the other reports):

```json
{ "report": "suggestions", "kind": "tag", "title": "Band · dont stop", "reason": "MusicBrainz spells this recording differently.",
  "track_id": 412, "album_id": 31, "artist_id": 9, "file": null, "copies": null, "physical": null,
  "suggestion": { "id": "title:412", "kind": "tag", "field": "title", "current": "dont stop", "suggested": "Don't Stop",
                  "can_apply": true, "source": "musicbrainz", "file_id": 880 } }
```

| `kind` | `field` | Found when | Applying |
|---|---|---|---|
| `tag` | `title` | the file's title differs from MusicBrainz's title of the recording (case, punctuation, whitespace; a curly versus a straight apostrophe is ignored) | writes `title` into the file |
| `tag` | `artist` | the file's artist differs from MusicBrainz's artist credit after the usual normalising (`The`, `feat.`) | writes `artist` |
| `tag` | `year` | the album has **no** year; MusicBrainz knows the first release year of the recording (an existing year is never suggested over: a reissue legitimately differs) | writes `year` |
| `cover` | `cover` | the `covers` report flags the album as having no cover, a tiny or a non-square cover, and the album has a MusicBrainz release id; `suggested` is that release id | queues `ReplaceCoverFromArchive`: the front image of the release from the Cover Art Archive, through `ProcessArtwork` |
| `album` | `track_numbers` | an album whose own files lack a track number (some do, some do not) or share one | nothing (`can_apply: false`, `suggested: null`): fix by hand |

A tag suggestion only exists for a recording **the fingerprint confirmed**: the track's AcoustID recording must be the recording the MusicBrainz job used (`mb_title`, `mb_artist` and `mb_year`, kept by `EnrichTrackMusicBrainz` from its recording lookup). A recording the job found by name is a guess and gives nothing. Tracks MusicBrainz-enriched before these three keys existed have none until they are enriched again.

## Accept and dismiss

Admin token, `id` is `suggestion.id` (`:` may be sent as is or escaped):

- `POST /api/admin/suggestions/{id}/accept` → `{ "status": "applied" | "queued", "id": … }`. A tag is written through **the tag editor**, `LibraryFiles::retag` (the writer behind `PUT /api/admin/files/{id}/tags`: mp3 pure PHP, FLAC `metaflac`, Ogg `vorbiscomment`). That **rewrites the user's file** and re-indexes it, so the track may move to another artist or album; ask first (the MCP tool needs `confirm=true`, the web button confirms). `422 invalid` for a suggestion that is not open, or that has no value; `502 storage_error` when the file cannot be written. A cover is fetched in the background; no front image at the Archive means the cover stays.
- `POST /api/admin/suggestions/{id}/dismiss` → `{ "status": "dismissed" }`. The suggested value is kept as `manual` metadata (`suggestion_dismissed_{field}` on the track, or on the album for a cover): the same value stays away, a different one comes back.

Both routes belong to the `file_storage` feature (Standard and Full); without a report in Standard, only a cover id of an own file is useful there. Control: `422 not_available_in_profile`.

## Web

`/settings/library-health/suggestions` (admin login): the Livewire component `TagSuggestions` lists each suggestion (old value struck through, new one next to it) with Accept and Dismiss; 100 rows, "Show more". MCP: `library_health` with `report: suggestions` lists, the ADMIN tool `library_suggestions` accepts or dismisses.

## Not built / unverified

- A suggested track number: MusicBrainz's recording lookup has no position on the release, and a release lookup per track would double the calls; the inconsistency is reported instead.
- No backfill: `mb_*` come from the next MusicBrainz enrichment of a track.
- Writing tags is as unverified as the tag editor itself (needs `metaflac` / `vorbiscomment` for FLAC and Ogg on the host); the Cover Art Archive call is tested with fakes only.
