# Artist settings

`/artists/{id}/settings` (admin login; Livewire `ArtistSettings`, linked from the `…` menu of the artist hero). Like the device settings page: one card per section, every action re-checks the login (Livewire's own endpoint skips the route middleware).

## Manual overrides

An admin's choice is a `metadata` row with source `manual` (`Metadata::MANUAL`). `ReadsMetadata::metaValue()` returns the manual row before any other source, and `Enrichment::mbid()` does too, so enrichment re-runs (which write their own source's rows) never undo a choice. Resetting deletes the row. `App\Domain\Library\ArtistOverrides` sets, clears and lists them.

| Key | Meaning |
|---|---|
| `photo` | the picked profile photo (URL) |
| `extra_images` | pictures added by link or upload (json list; uploads are `remoment:cover/{sha1}` pseudo URLs, see `LocalCover`) |
| `hidden_images` | wide pictures switched off for the artist page backdrop and collage (json list) |
| `mbid`, `spotify_id`, `lastfm_name` | the identity mappings below |
| `bio`, `genres`, `tags`, `country`, `artist_type` | details overrides |
| `default_source` | `files` \| `dlna` \| `spotify`: the source the artist's pages open on |

`Artist::photoUrl()` and `galleryUrls()` (backdrop, collage; hidden ones left out) honour them.

## Sections

- **Photo** — every candidate (TheAudioDB thumb and fanart, Spotify, added, top album cover); click to use one, "Go back to automatic". Add by link or upload.
- **Backdrop** — wide pictures with an eye toggle, Hide all / Show all.
- **Identity** (`App\Domain\Library\ArtistIdentity`) — the MusicBrainz, Spotify and Last.fm match. A new MusicBrainz id drops what the old match brought (rows of sources `musicbrainz`, `audiodb`, `wikipedia` and their genres) and fetches again; Spotify refetches the photo; the Last.fm name is what `EnrichArtistLastfm` looks up. MusicBrainz and Spotify can be searched by the artist's name or set from an id or link. "Fetch again" clears the `enriched_at` markers of one or all sources and queues the jobs (the old single `system` marker is split into per-source ones first).
- **Name and duplicates** — rename (the comparison key follows), merge another library artist into this one (`LibraryMerger::mergeArtists`).
- **Details** — bio, genres, tags, country and type as manual rows (`ArtistOverrides::setDetails`); an empty field removes the row, so the sources' value shows again (as the placeholder). Manual genres replace the artist's `genreables`; clearing them re-syncs from the stored `genres` rows of every source. A later enrichment run may add its genres on top again (GenreSync is additive).
- **Library and files** — per library counts, a "open this artist's pages on" default (`default_source`: used by the artist and album pages when the URL names no `?source=`), the artist's own files (hide/show one or all, match again, verify, delete; ids are checked against the artist's files) and "Remove from library" (only when no Local/DLNA tracks, as before; moved here from the hero menu).
