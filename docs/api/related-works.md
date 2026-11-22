# Related works

Roadmap 12.7. What MusicBrainz says a track is related to: this is a **cover of** a song, a **remix of** or **samples** another recording, an **arrangement**, **other versions**. Shown in a track's details on the album page and served by one route; Standard and Full (the `library` feature).

## Where the data comes from

No extra request. The recording lookup of `EnrichTrackMusicBrainz` (the one that already reads credits) now also asks for `recording-rels`; `App\Domain\Library\RelatedWorks::parse()` keeps from the answer

- track metadata `related_works` (json list, source `musicbrainz`, at most 30) and
- track metadata `work_mbid`: the MusicBrainz *work* (the song) the recording performs.

A track enriched before this existed has neither until MusicBrainz is asked again for it (the daily backlog only takes tracks without the source marker, so for older tracks that is a manual re-enrich). The rate limit and the retry rules are the job's own (the `musicbrainz` limiter, 1 request a second).

| MusicBrainz | Item `relation` | Notes |
|---|---|---|
| `performance` of a work with the attribute `cover` | `cover_of` | target = the work; `artist` = its composers and writers when the lookup carries them (the real lookup in the test fixture does not) |
| recording to recording `remix`, `samples material`, `medley`, `edit`, `remaster` | `remix`, `samples_material`, `medley`, `edit`, `remaster` | label by direction ("Remix of" / "Remixed as", "Samples" / "Is sampled by") |
| work to work `arrangement`, `other version`, `musical quotation`, `based on`, `revision of` | the same names with `_` | read from a real response: `arrangement forward` is "Has an arrangement", `musical quotation backward` is "Is quoted in" |

## API

`GET /api/library/tracks/{id}/related?scope=&client=` (open):

```json
{ "canonical_id": 412,
  "data": [ { "relation": "cover_of", "label": "Cover of", "target": "work", "mbid": "879ba403-…", "title": "Mad World", "artist": null, "detail": null,
              "track": { "id": 97, "name": "Mad World", "artist_name": "Tears for Fears", "album_id": 12, "album_name": "The Hurting", "duration": 213, "artwork": null } } ],
  "versions": [ { "id": 97, "name": "Mad World", "artist_name": "Tears for Fears", "…": "…" } ] }
```

- `track` is the **library track** the item is, or null (shown as plain text): a recording target by its MusicBrainz id (any of the `mbid` sources: MusicBrainz, AcoustID, the file's own tag), a work target by the library tracks that perform it (`work_mbid`).
- `versions`: the other library tracks performing the same work as this one: the original and the other covers you own, with no lookup. The scope (`scope`, `client`) hides what the leading source or the client hides.
- A track without data answers `data: []`, `versions: []`; an unknown id is 404. MCP: `browse_library` kind `related`.

## Web

A **Related** row in *Track details* on the album page (`Livewire\TrackRelated`, lazy, for tracks with a MusicBrainz recording): label and title per item, a link to the album page when the track is in the library, plain text otherwise; "Other versions here" below. See [../frontend/related-works.md](../frontend/related-works.md).

## Unverified

The direction of recording-to-recording relations (remix, samples, medley, edit, remaster): the labels assume "forward = the looked-up recording is the one doing it", which holds for quotation but not for arrangement in the one real response checked (2026-11-21, Gary Jules, Mad World), so those five may read backwards until a response with one is compared. Keep the mapping in `RelatedWorks::LABELS`; a wrong label is a one-line fix.
