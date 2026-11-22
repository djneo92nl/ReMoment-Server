# Concerts

Roadmap 12.6. Upcoming concerts of the artists in the library, from **MusicBrainz events**. Opt-in, Standard and Full (the `library` feature for reading, `enrichment` for the daily sync).

## Why MusicBrainz

The roadmap named setlist.fm (free key, mostly past setlists), Bandsintown (an app id, artist-name lookups) and Songkick (access restricted). All of them need a key or an agreement; MusicBrainz events need none and the server already talks to MusicBrainz for the enrichment, so that is the one source. Its limits are real: events are entered by volunteers, so festivals and big tours are there and the local pub gig of a small artist usually is not. An empty answer means "none known".

## Opt-in and location

Two library settings (Settings › Storage › Library, `GET/PUT /api/admin/library/settings`, MCP `library_settings`; [admin-library.md](admin-library.md)):

| Key | Meaning |
|---|---|
| `concerts_enabled` | default **off**. On: once a day the server asks MusicBrainz for the events of each library artist that has a MusicBrainz id, which tells MusicBrainz which artists you own. The form says so |
| `concerts_location` | text, comma separated terms such as `Netherlands, Amsterdam`; empty = everywhere. An event passes when any term is contained (case-insensitive) in its **venue, city or address** |

MusicBrainz places carry a city and an address that usually ends in the country name, not a country field, so a country term works when the address has it ("Arena Blvd 1, Amsterdam, Netherlands") and otherwise the city has to be named. Name the big cities too.

## Sync

`php artisan concerts:sync [--limit=100] [--days=7] [--dry-run]`, scheduled daily 03:10 only while the setting is on. It deletes concerts that have passed and queues `SyncArtistConcerts` for the artists (own files and DLNA, not Spotify-only, with an `mbid`) not read in the last 7 days. The job uses the `musicbrainz` limiter (1 request a second), browses `GET /ws/2/event?artist={mbid}&inc=place-rels+area-rels` in pages of 100 (at most 3), keeps events whose start date is today or later and that are not cancelled, and replaces the artist's rows; a rate limit or outage throws and the job is retried without touching the stored rows. Dates that are only a year or month are skipped. Table `concerts` (migration `2026_11_24_120000_create_concerts_table`); the last read is the artist metadata `concerts_checked_at` (source `musicbrainz-events`).

## API

`GET /api/library/concerts?artist_id=&location=&all=&limit=` and `GET /api/library/artists/{id}/concerts` (open):

```json
{ "enabled": true, "location": "Netherlands, Amsterdam",
  "data": [ { "id": 7, "artist_id": 3, "artist_name": "Radiohead", "name": "Glastonbury Festival 2027", "type": "Festival",
              "starts_on": "2027-06-25", "ends_on": null, "starts_at": "10:45", "venue": "Worthy Farm", "city": "Pilton",
              "address": "Worthy Farm, Worthy Lane, Pilton BA4 4BY, United Kingdom", "latitude": 51.155, "longitude": -2.586,
              "url": "https://musicbrainz.org/event/…" } ] }
```

Soonest first. `enabled: false` means the setting is off (`data` is empty by choice). `location` is the filter applied (the setting, or the `location` parameter of this call); `all=1` ignores it. `limit` 1–200 (default 50). Answers are cached 10 minutes and the cache is dropped after a sync; no request to MusicBrainz is made here. Control answers `422 not_available_in_profile`.

MCP: `browse_library` kind `concerts` (optional artist `id`, `location`, `limit`).

## Web

A **Coming up** shelf on the artist page (Overview) and the reusable `Livewire\ConcertsShelf`; see [../frontend/concerts.md](../frontend/concerts.md).

## Unverified

- The response shape was checked on 2026-11-21 against a real browse (Radiohead, 44 events, all past): `life-span.begin`, `time`, `cancelled`, `relations[].place` with `address`, `coordinates` and `area` (a city). An event held "in" an area without a place (`area-rels`) is parsed from the same shape but no real example with an upcoming date was available.
- Browse order is not documented; the sync reads up to 300 events, so an artist with more past events than that may miss upcoming ones. A MusicBrainz search with a date range would fix that if it proves a problem.
- Home Assistant event for a nearby concert (roadmap mentions it) is not built.
