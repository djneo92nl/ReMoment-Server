# Metadata enrichment

Tracks, albums and artists get extra data from outside services, stored in the polymorphic `metadata` table (key, value, type, source).

## Pipeline

`App\Domain\Library\Enrichment::queue($track)` queues one job per source that hasn't finished for the track:

| Job | Source | Writes |
|-----|--------|--------|
| `EnrichTrackMusicBrainz` | `musicbrainz` | track `mbid`, `isrc`, `credits` (producer, composer, performers … `[{role, name, detail?}]`); artist `mbid`; album `mbid` (only the release whose title is the album's). Queues the two jobs below |
| `EnrichArtistMusicBrainz` | `musicbrainz`, `wikipedia` | once per artist with an `mbid`: `genres` (curated, else top tags), `country`, `area`, `artist_type`, `begin_date`, `end_date`, `links` (official, wikidata, discogs, bandcamp, allmusic, lastfm, instagram … json); from Wikipedia (via the Wikidata id) `wikidata_id`, `wikipedia_extract`, `wikipedia_url` |
| `EnrichAlbumMusicBrainz` | `musicbrainz`, `wikipedia` | once per album with an `mbid`: `label`, `catalog_number`, `barcode`, `country`, `release_date`, `release_type` (album, ep, single …), `secondary_types` (json), `track_count`, `disc_count`, `release_group_mbid`, `genres`; fills `albums.released_at` when empty; Wikipedia summary of the release group; **when the album has no image** its front cover from the Cover Art Archive (queued through `ProcessArtwork`) |
| `EnrichTrackLyrics` | `lrclib` | track `lyrics_plain` (empty = none exist), `lyrics_synced` |
| `EnrichTrackSpotify` | `spotify` | track `spotify_popularity`, `explicit`, `isrc`; artist `spotify_id`, `spotify_popularity`, `genres` (only when MusicBrainz gave none). Spotify-source tracks, when connected |
| `EnrichArtistLastfm` | `lastfm` | artist `bio`, `similar_artists` (needs `LASTFM_API_KEY`) |

- **Markers:** each job records `enriched_at` with its own source when it *finishes*. "No match" counts as finished; a rate limit or outage (HTTP 429/5xx) throws, so the job is retried (`maxExceptions` 3, within 12 h) without redoing the other sources. Tracks marked by the old single job (`enriched_at`, source `system`) count as done for every source.
- **Uniqueness:** every job is `ShouldBeUnique` per track/artist, so repeated queueing (many tracks of one artist, daily backlog runs) doesn't pile up duplicates.
- **Rate limit:** the three MusicBrainz jobs use the `musicbrainz` limiter (1/s, `AppServiceProvider`) plus a 1 s pause between the calls of one job (`MusicBrainzClient`).
- **Eligibility:** tracks without a real artist (empty or "Unknown Artist") are skipped.

## Triggers

- Spotify import and play history queue a newly created track.
- A DLNA scan queues a batch of `Enrichment::SCAN_BATCH` (100) tracks at its end, so a first scan of a big library doesn't flood the (shared) queue.
- `php artisan library:enrich [--limit=200] [--dry-run]` (scheduled daily) queues the newest tracks still missing a source, any library source, and the artists and albums that have an `mbid` but no MusicBrainz details yet (this is how libraries enriched before the detail jobs existed get them). The backlog is worked off over several days.

Add a source: write a job with `EnrichesFromSource`, add its source constant and job to `Enrichment::trackJobs()`, and its marker check to `Enrichment::backlog()`.

## Tags from DLNA servers

`DlnaContentDirectoryClient` reads what a server sends in DIDL-Lite, and `DlnaLibraryScanner::storeTags()` keeps it as track metadata with source `dlna:{server_id}` (written only when new or changed, so rescans stay cheap):

| Key | From | Notes |
|-----|------|-------|
| `genres` (json) | `upnp:genre` | distinct, in order; track level, separate from the artist-level `genres` the genre pages use |
| `track_number`, `disc_number` | `upnp:originalTrackNumber`, `upnp:originalDiscNumber` | |
| `album_artist` | `upnp:artist role="AlbumArtist"` | stored only; the track's artist (`dc:creator`) still decides identity |
| `bitrate` (kbps), `sample_rate` (Hz), `bit_depth`, `channels`, `file_size` (bytes) | `<res>` attributes | UPnP gives `bitrate` in bytes per second; absent or zero values are skipped |
| `mime_type` | third field of `<res protocolInfo>` | e.g. `audio/flac` |

`dc:date` (`2011`, `2011-03`, `2011-03-15`) fills `albums.released_at` when the album has none.

## Canonical genres

Every source writes raw genre strings under the metadata key `genres` (artist, album and track level). `Enrichment::save()` hands them to `GenreSync`, which keeps them in the typed tables `genres` and `genreables` (morph: artists, albums, tracks; `position` = rank within the source, `source`).

- `GenreNormalizer` identifies a genre by a key (lower case, ASCII, `&` as "and", letters and digits only), so "hip hop", "Hip-Hop" and "hiphop" are one genre; the first display name seen wins unless an alias names one ("rnb" → R&B, "dnb" → Drum & Bass). Tags that aren't genres (nationalities, decades, "seen live", …) are dropped.
- Sync is additive: a genre keeps its best position across sources and never disappears because a source stops reporting it.
- `Artist/Album/Track::genres()` returns the names, best first. The web genre pages (`/genres`, `/genres/{slug}`; old name URLs redirect) and the API (`GET /api/library/genres`, `/genres/{slug}`, `genres` in artist and album detail) read the tables.
- `php artisan library:sync-genres` rebuilds them from the stored metadata (the migration runs it once); run it again after changing `GenreNormalizer` rules (bump `GenreNormalizer::VERSION`).

## Where it shows in the web UI

- **Artist page** (`/artists/{id}`): Wikipedia bio (else Last.fm), genres, area/country, type and years, links (`Artist::details()`).
- **Album page** (`/albums/{id}`): a line of release type · year · label · catalog number · track count, genres (the artist's when the album has none), the Wikipedia summary (`Album::details()`).
- **Track rows on the album page:** the real track number when a DLNA server sent one, an *E* badge for explicit tracks, and an info button that opens genres, file quality (`Track::audioQuality()`), credits by role (`Track::credits()`), Spotify popularity, ISRC and a MusicBrainz link. `Track::DISPLAY_METADATA` lists the keys the album controller loads.
- **Genre pages** (`/genres`, `/genres/{slug}`).
