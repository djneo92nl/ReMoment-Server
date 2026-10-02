# Metadata enrichment

Tracks, albums and artists get extra data from outside services, stored in the polymorphic `metadata` table (key, value, type, source).

## Pipeline

`App\Domain\Library\Enrichment::queue($track)` queues one job per source that hasn't finished for the track:

| Job | Source | Writes |
|-----|--------|--------|
| `EnrichTrackMusicBrainz` | `musicbrainz` | track `mbid`, `isrc`; artist `mbid`, `genres`, `country`; album `mbid`, `label` |
| `EnrichTrackLyrics` | `lrclib` | track `lyrics_plain` (empty = none exist), `lyrics_synced` |
| `EnrichTrackSpotify` | `spotify` | track `spotify_popularity`, `explicit`, `isrc`; artist `spotify_id`, `spotify_popularity`, `genres` (only when MusicBrainz gave none). Spotify-source tracks, when connected |
| `EnrichArtistLastfm` | `lastfm` | artist `bio`, `similar_artists` (needs `LASTFM_API_KEY`) |

- **Markers:** each job records `enriched_at` with its own source when it *finishes*. "No match" counts as finished; a rate limit or outage (HTTP 429/5xx) throws, so the job is retried (`maxExceptions` 3, within 12 h) without redoing the other sources. Tracks marked by the old single job (`enriched_at`, source `system`) count as done for every source.
- **Uniqueness:** every job is `ShouldBeUnique` per track/artist, so repeated queueing (many tracks of one artist, daily backlog runs) doesn't pile up duplicates.
- **Rate limit:** `EnrichTrackMusicBrainz` uses the `musicbrainz` limiter (1/s, `AppServiceProvider`) plus a 1 s pause between its calls.
- **Eligibility:** tracks without a real artist (empty or "Unknown Artist") are skipped.

## Triggers

- Spotify import and play history queue a newly created track.
- A DLNA scan queues a batch of `Enrichment::SCAN_BATCH` (100) tracks at its end, so a first scan of a big library doesn't flood the (shared) queue.
- `php artisan library:enrich [--limit=200] [--dry-run]` (scheduled daily) queues the newest tracks still missing a source, any library source. The backlog is worked off over several days.

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
