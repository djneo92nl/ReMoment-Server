# Library Identity and Duplicate Merging

The library has **one record per artist, album and track**, whichever source named it: a DLNA scan, the Spotify library import, or a play recorded from a device's now-playing. A DLNA track and a Spotify track of the same song are one `tracks` row that has both a `dlna_url` and a Spotify URI, so `LibraryPlayback` can play it through DLNA first and Spotify second.

Files: `app/Domain/Library/Normalizer.php` (the keys), `LibraryIdentity.php` (find-or-create), `LibraryMerger.php` + `app/Console/Commands/MergeLibraryDuplicates.php` (merging existing duplicates).

## Where duplicates came from

Before this, every creation path keyed on `source`, and the unique keys include it (`artists (name, source)`, `albums (artist_id, name, source)`, `tracks (external_id, source)`):

| Path | Created by | Source |
|---|---|---|
| DLNA scan (`library:scan`, `/settings/dlna`) | `DlnaLibraryScanner` | `dlna` |
| Spotify import (`library:sync-spotify`, `/settings`) | `SpotifyLibraryImporter` | `spotify` |
| Plays (`StorePlaybackHistory`, from `NowPlayingUpdated`) | B&O ASE, Sonos, Mozart: usually `null`; Spotify (and ASE playing Spotify): `spotify` | as reported |

So the same artist existed once per source, and also once per spelling: Spotify says "The Beatles" and "Help! - Remastered 2009" on "Help! (Remastered)", file tags say "Beatles", "Help!" on "Help!", a speaker may send "BEATLES" or "Daft Punk feat. Pharrell Williams". Plays matched tracks by exact name + artist + source, so a play never counted for the scanned or imported track. Last.fm/MusicBrainz enrichment (`EnrichTrackMetadata`, `EnrichArtistLastfm`) and `library:backfill-*` only add metadata to existing rows; they never created records.

## Keys (`Normalizer`)

`artists.name_key`, `albums.name_key` and `tracks.name_key` hold a comparison form of `name`, set by a `saving` hook on the models. Keys are never shown.

**All names:** Unicode NFKD with combining marks removed (Beyoncé = Beyonce), æ/œ/ø/ß/ł/đ/ð/þ spelled out, lower case, `&` = "and", apostrophes and periods dropped (R.E.M. = REM, Guns N' Roses = Guns N Roses), other punctuation and whitespace collapsed to one space (Jay-Z = Jay Z). A name of only punctuation ("!!!") keys as itself.

**Artists:** a leading "The " or trailing ", The" is ignored; a featuring credit — "feat.", "feat", "ft.", "featuring", bracketed or not — and everything after it is ignored. Nothing else is split: "Simon & Garfunkel", "Crosby, Stills & Nash", "A x B", "A with B" stay whole.

**Albums and tracks:** trailing **edition markers** are ignored, repeatedly: a `(…)`, `[…]` or ` - …` suffix whose words are all edition words and contain at least one marker:

- markers: remaster(ed/s/ing), deluxe, expanded, anniversary, special, collector('s), legacy, bonus
- also allowed: edition, version, super, digital(ly), the, and, track(s), with, year, re, master(ed), reissue, a year (2011) or ordinal (40th)

So "(Remastered 2011)", "- 2009 Remaster", "(Deluxe Edition)", "[Super Deluxe]", "(40th Anniversary Edition)", "(Bonus Track Version)" are ignored. Anything else keeps the record apart: "(Live)", "(Live Remastered)", "(Acoustic)", "(Mono)", "(Remix)", "(Radio Edit)", "(Japanese Edition)", "(2014)", "- Demos", "Vol. 2". "The " is not ignored for albums. **Tracks** also ignore a bracketed or " - " featuring credit ("Get Lucky (feat. Pharrell Williams)" = "Get Lucky"), but not a title that starts with "Feat".

**Durations:** two tracks with the same key are the same only when their durations are within `LibraryIdentity::SAME_DURATION` (5) seconds, or one is unknown. This keeps two different "Intro"s on one album apart.

Changing a rule: edit `Normalizer`, add test cases to `tests/Unit/Library/NormalizerTest.php`, bump `Normalizer::VERSION`, and run `library:merge-duplicates` (it re-keys every row).

## Find-or-create (`LibraryIdentity`)

Used by `DlnaLibraryScanner`, `SpotifyLibraryImporter` and `StorePlaybackHistory`:

- `artist(name, source)` — the oldest artist with that key, else a new one.
- `album(artist, name, source, attributes)` — the oldest album of that artist with that key, else a new one (`attributes` only on create).
- `track(artist, album, name, externalId, source, attributes)`:
  1. by external id: the track's own `external_id` + `source`, or one kept as `external_id` metadata (`source` = that source) after a merge. A track found by its own id is updated from `attributes` (name, duration, images), as before; one found by a kept id only gets blanks filled.
  2. by name: with an album, the album's oldest track with that key and a compatible duration, else an album-less track of the artist (which then gets the album); without an album, the artist's oldest compatible track **on an album**, else its oldest compatible album-less track.
  3. else a new track.

A track found by name keeps its `source`, `external_id` and `name`; the other source's external id is added as `external_id` metadata (or becomes the track's own when it has none). `LibraryPlayback::spotifyUri()` reads the Spotify URI from either place. The DLNA scanner stores `dlna_url` per server (`source` `dlna:{id}`), so a track on two servers has a URL from each.

A miss while rows without a key exist (added before keys did) first gives those rows their key — only the derived `name_key` column is written — and looks again. The migration itself only adds the columns.

Also new in the play path: a B&O speaker playing Spotify reports the URI only in its meta (`spotifyId`); it is used as the track's external id, so those plays are playable through Spotify too. Its flat meta array is stored instead of throwing.

## Merging existing duplicates (`library:merge-duplicates`)

```bash
php artisan library:merge-duplicates --dry-run   # list the groups, change nothing
php artisan library:merge-duplicates             # asks for confirmation, then merges
php artisan library:merge-duplicates --force     # no confirmation (scripts)
```

Not run automatically: it deletes records whose ids clients may hold, so it is a deliberate admin step. Run it once after deploying this, after a Normalizer rule change, and whenever duplicates appear (e.g. after two imports ran concurrently). It is idempotent: a second run reports "No duplicates found."

`LibraryMerger::plan()` computes keys in memory and groups:

1. artists by key;
2. albums by (artist after step 1, key);
3. tracks by (album after step 2, key) with compatible durations; an album-less track joins the artist's oldest compatible album track, else other album-less tracks.

The **oldest record (lowest id) survives**. `merge()` then runs one transaction per group — albums, then artists, then tracks — after re-keying all rows (`rekey()`):

| Merged | What happens |
|---|---|
| Album | its tracks move to the survivor; metadata moves (e.g. `spotify_album_uri`, `mbid`, `label`); `favorited_at` = the earliest; `released_at` filled if missing; `images` + `colors` from the album whose cover is fully processed (`ArtworkCache::has()`), else the first with a cover |
| Artist | its albums move to the survivor (an album with the same name and source already there is merged into it instead, as `albums` is unique on that); its tracks move; metadata moves (bio, similar artists, genres, mbid…); `favorited_at` = the earliest |
| Track | `plays.track_id` re-pointed; playlist entries re-pointed (a playlist that already has the survivor keeps its own entry and position); metadata moves (`dlna_url` per server, lyrics, isrc…); `duration`, `images`, `album_id` filled if missing; its `external_id` becomes the survivor's own when the survivor has none, else is kept as `external_id` metadata |

Metadata with the same key and source on both is not duplicated: the survivor's row is kept, taking the other's value only when its own is empty (e.g. lyrics `''` = "not found" filled from the other). Then the merged-away rows are deleted; nothing references them any more (`plays.track_id` would otherwise be nulled, the other foreign keys cascade).

Everything referencing library rows: `tracks.album_id`, `tracks.artist_id`, `albums.artist_id`, `plays.track_id`, `playlist_track.track_id`, and the `metadata` morph (`metadatable_type` = the model class). Nothing in Redis is keyed by library ids: artwork is keyed by `md5(cover URL)`, so `ArtworkCache`, the pre-cache list (`LibraryArtwork::albumsByRecency()`), `artwork:prerender`, the SD card export and the stats (joins over `plays` → `tracks`) work unchanged on the merged rows. Queued `EnrichTrackMetadata`, `EnrichArtistLastfm` and `SendNowPlayingToLastfm` jobs for a merged-away record are dropped (`$deleteWhenMissingModels`).

**For clients:** the ids of merged-away artists, albums and tracks stop existing. `GET /api/library/albums/{id}` / `artists/{id}` answer `404`, and play/favorite requests with such an id a `422` validation error or `404`; clients should drop the cached id and re-read the list (see `docs/api/library.md`).

## Known limits

- The display name of a merged record is the survivor's (the oldest), e.g. "Beatles" from file tags rather than Spotify's "The Beatles".
- Albums are per artist: a compilation imported per track artist (Spotify import uses the track's first artist) is still split by artist.
- Two different songs with the same title and nearly the same length on one album would be merged.
- Two imports running at the same moment can still create a duplicate; the next `library:merge-duplicates` run folds it in.

## Leading source

`App\Domain\Library\LeadingSource` decides which source the library pages and API show by default (setting `library_leading_source`: `auto` = DLNA if it has tracks else Spotify, `dlna`, `spotify`, `all`). A scope (`local`, `streaming`, `all`) maps to `LibrarySources` hidden sources (`local` hides `spotify`, `streaming` hides `dlna`), so it's a query filter only. The web pages take `?scope=` and remember it in the session (`<x-library-scope>` toggle); the API takes `?scope=`.

## Plays and the library

With `library_add_played_tracks` off (default), `StorePlaybackHistory` only looks tracks up (`LibraryIdentity::lookupTrack`: external id, else artist + album + name) and creates no artist/album/track. A play of an unknown track is stored with `track_id` null and `track_name`/`artist_name`/`album_name`/`image_url`/`duration`; `Play::trackTitle()` etc. read either. Plays logged while off are not added retroactively when the flag is turned on.
