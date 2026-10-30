# Availability layer (design)

Status: **design; PRs 1b to 9, wished songs and new releases are built** (1b Spotify only) (8 without the generic play-external path, which belongs to 1b) (tables, MusicBrainz discography sync, linker, `catalogue:sync` / `catalogue:reconcile`; see "Built so far" at the end). The rest is not. Written 2026-10-28 for workstream 3 of [v1-roadmap.md](v1-roadmap.md). Implementation starts after stable IDs (14.2) have landed; the tables here feed the schema review (14.3, see [For the schema review](#for-the-schema-review-143)).

The problem in one paragraph: the library is a copy of what each source gave us. Nothing says which albums of an artist we lack, which of those a service could play, or lets the household wish for one. Spotify, Deezer/Tidal ([streaming-services.md](streaming-services.md)), the physical library (roadmap 6), media servers (15) and discovery (4) all need the same answer to "where can this album be had, and can device X play it", so they plug into one model as **providers**.

## What the end user gets (two jobs, one model)

1. **Browse, play and add from a service.** Find any artist, album or track on Spotify (later Deezer, Tidal, media servers), play it on a device that can, and add it to the streaming library with one action. This works for artists you do not have yet and is the core for a streaming-first household. See [Browse, play and add](#browse-play-and-add-from-an-external-source).
2. **Know what is missing from the collection.** On an artist page: "you have 6 of 14 albums". For someone who buys records or CDs and rips them, the missing ones can be put on a **wishlist** that closes by itself when the album becomes owned. Copy rule: the wishlist is about **buying or ripping** what you are missing ("Want to buy or rip"), never "getting" files; no wording that suggests acquiring music from anywhere but a shop, a disc or a service.

The two meet on one screen: a missing album is also **streamable** when a service has it, so the row offers **Play** (stream it now) next to **Wish** (buy/rip it someday) and **Add to library**. "Missing" never means "unplayable".

## Decisions taken as fixed

1. The discography lists **albums and EPs only** by default (no singles, live, compilations; a toggle may come later).
2. The wishlist is **household-wide**, like favourites. Nothing per user, ever (one shared admin).
3. Missing albums live in **their own table** (`catalogue_albums`); no existing album query changes; an album is **promoted** (linked to a real `albums` row) when it is imported or owned.
4. **MusicBrainz release-groups** decide what counts as missing, with a manual "I don't want this" per release-group.
5. Tables work on **MariaDB and SQLite**.
6. **Pi 2/3 budget (1 GB):** sync is incremental, rate limited (MusicBrainz ~1 request/s), on the existing queue machinery, never inside a request.

## Facts checked (and what is not)

| Fact | Source, checked 2026-10-28 |
|---|---|
| Release-groups of an artist: `GET /ws/2/release-group?artist={mbid}&type=album\|ep&limit=100&offset=N&fmt=json`; default limit 25, **max 100**; `offset` pages | [MusicBrainz API](https://musicbrainz.org/doc/MusicBrainz_API) |
| Primary types: album, single, ep, broadcast, other. Secondary types: audio drama, audiobook, compilation, demo, dj-mix, field recording, interview, live, mixtape/street, remix, soundtrack, spokenword | same page |
| `release-group-status=website-default` limits a browse to what the website overview shows | same page |
| At most one request/s per IP; exceeding it declines **all** requests from the address (HTTP 503); every request needs an identifying `User-Agent` | [Rate limiting](https://musicbrainz.org/doc/MusicBrainz_API/Rate_Limiting) |
| `MetadataHttp` already sends `config('metadata.user_agent')` and throws on 429/5xx | code |
| Spotify search `limit` max 10; `/artists/{id}/albums` works on the owner's post-February Development Mode app (**verified 2026-10-30**, see [Verified against live services](#verified-against-live-services-2026-10-30)) | [spotify-api-audit.md](spotify-api-audit.md), live call |
| **Verified 2026-10-30** (see the last section): the browse response carries `primary-type` and `secondary-types` per release-group without `inc=`; the `type` filter does **not** constrain secondary types (filtered client side); a 404 for an unknown artist id. **Still not verified:** how MusicBrainz answers a *merged* (redirected) artist mbid (no merged id at hand). ListenBrainz needs no token; its limits and shape are in the last section. |

## 1. Model and vocabulary

### States

An album **entry** (a release-group of an artist, or a library album with no release-group) has one availability state, derived and never stored:

| State | Rule | Playable |
|---|---|---|
| `owned` | at least one provider with `ownership = owned` has it | per provider and device |
| `streamable` | none owned, at least one `ownership = streamable` provider has it | per provider and **per device** |
| `known` | only the catalogue (MusicBrainz) knows it | never; a candidate for the wishlist |

`wanted` and `ignored` are **flags** on a catalogue row, not states. Wanted + `owned` is a closed wish (`fulfilled_at`).

### Providers

**DLNA counts as owned** (owner's answer, 2026-10-28): it serves the household's own files, so it earns the Owned badge and closes a wish; it is `streaming` in `LIBRARIES` only because this server does not hold the files. Later, DLNA tracks may be **synced into the own-file library** (copied and indexed as `files`) and playlists mix all sources as they do today; that is outside this design and needs nothing from it beyond the existing derived provider.

A provider is where an album can be had. Ownership is **a property of the provider, not of `LibrarySources::LIBRARIES['kind']`**: `kind` (`local`/`streaming`) is a scope concept for the hide switches and defaults, and DLNA is `streaming` there although a DLNA server serves the household's own files. Reusing `kind` for "owned" would make DLNA albums "streamable". So the registry has its own field.

| Provider id | Ownership | Mode | Library (in `LIBRARIES`) | Truth lives in |
|---|---|---|---|---|
| `files` | owned | derived | `files` | `tracks` / `track_files` (today's `LibrarySources::availableFrom`) |
| `dlna` | owned | derived | `dlna` | tracks with a `dlna_url` |
| `physical` | owned | derived | none (a label, not playable unless a changer link exists, roadmap 6) | `physical_items.album_id` |
| `spotify` | streamable | derived for saved albums, **lookup** for the rest | `spotify` | `spotify_album_uri` metadata / tracks; `album_availability` for unsaved albums |
| `deezer`, `tidal` | streamable | lookup | `deezer`, `tidal` (reserved by streaming-services.md) | `album_availability` |
| `jellyfin`, `plex`, `emby` (instances `jellyfin:3`) | owned (the owner's server) | lookup | one library per type, instance as suffix like `dlna:{id}` | `album_availability` |

**Derived** providers compute availability from rows that already exist (no duplicate to keep in sync). **Lookup** providers ask an external catalogue and store the answer in `album_availability`.

### "Playable on device X"

`playableOn(album, device)` = any provider that has the album **and** whose library passes `LibrarySources::playableOn($library, $device)` (the existing per-library `device` check, which for Deezer/Tidal will be the `deezer_playback` / `tidal_playback` capability). A provider without a library (`physical`) never makes an album playable by itself; it only labels. The result is a list of provider ids, so the UI can say "On Spotify and Deezer; plays on Kitchen, Study". `LibraryPlayback` stays the executor: it tries DLNA, then Spotify, then the streaming-services path, and gains one entry point to play an external id with no `albums` row (see API).

### Registry and contract (extend `LIBRARIES`, do not duplicate)

`LibrarySources::LIBRARIES` stays the single list of libraries (scope, hide switch, device check). The availability layer adds a thin registry keyed by the **same ids**: `config/availability.php` maps provider id → driver class, like `config/devices.php`. A test asserts that every provider with a library names an id that exists in `LIBRARIES`, and that every `streaming` library has a provider.

```php
namespace App\Domain\Availability;

enum Ownership: string { case Owned = 'owned'; case Streamable = 'streamable'; }
enum Mode: string { case Derived = 'derived'; case Lookup = 'lookup'; }

interface AvailabilityProvider
{
    public function id(): string;                 // 'spotify', 'deezer', 'jellyfin'
    public function ownership(): Ownership;
    public function mode(): Mode;
    public function library(): ?string;           // key of LibrarySources::LIBRARIES, null for physical
    public function enabled(): bool;              // credentials / connection present (ServiceCredentials, 14.4)
    public function rateLimiter(): ?string;       // name registered in AppServiceProvider ('spotify', 'deezer')
    public function recheckAfterDays(bool $found): int;   // positive vs negative cache
}

/** Mode::Derived: answers from existing rows, for one album (or a page of them: one query, no N+1). */
interface DerivesAvailability extends AvailabilityProvider
{
    /** @param  Collection<Album>  $albums  @return array<int, AvailabilityHit> keyed by album id */
    public function forAlbums(Collection $albums): array;
}

/** Mode::Lookup: asks the service; the job (not the provider) stores the result and applies the rate limiter. */
interface LooksUpAvailability extends AvailabilityProvider
{
    /** @return AvailabilityHit|null  null = not there; low-confidence candidates are never returned */
    public function lookup(AlbumQuery $query): ?AvailabilityHit;
    /** Cheaper bulk path when the service lists an artist's albums in one call (Spotify, Deezer, Jellyfin); optional. */
    public function lookupArtist(ArtistQuery $query): iterable;
}

/** Browse: what the service offers, for any artist, not only library ones. Results are transient (never stored). */
interface BrowsesExternalCatalogue extends AvailabilityProvider
{
    /** @param  'artist'|'album'|'track'  $type  @return list<ExternalItem>  limit per the service (Spotify search: max 10) */
    public function search(string $query, string $type, int $limit = 10): array;
    /** @return list<ExternalItem> the albums (and top tracks where the service has them) of an artist */
    public function artistItems(string $externalArtistId): array;
    public function albumTracks(string $externalAlbumId): array;
    /** Create the library records through LibraryIdentity (source = this provider) and return them. */
    public function addToLibrary(string $type, string $externalId): LibraryAddResult;
}
/** ExternalItem: provider, type, externalId, title, artist name, year, track count, image URL, in_library (album_id/artist_id when LibraryIdentity already knows it). */

/** AlbumQuery: artist name + name_key, album title + name_key, year, track count (if known), MusicBrainz links (spotify/deezer/tidal urls from `links` metadata), barcode. */
/** AvailabilityHit: provider, externalId, confidence 0-100, match (link|search|reported|manual). */
```

Adding a provider = a `LibrarySources::LIBRARIES` entry (if playable), a `config/availability.php` line, a class implementing one of the two interfaces, a credential declaration (14.4) and a rate limiter. Nothing in the API, web or MCP changes: they read `Availability::forAlbums()` / `forCatalogue()`, which merge the derived providers and the `album_availability` rows. Roadmap 15 owns the media-server instance question (one `LIBRARIES` entry per server **type**, instances as a `:{id}` suffix, the precedent set by `dlna:{id}`); settle it there, this document only requires that a provider id may carry a suffix (hence the `provider` column width below).

## 2. Tables

All names are final candidates for 14.3. Conventions of the repo: `foreignId()->constrained()`, JSON columns as `json`, timestamps, no database-specific SQL. `char(36)` for UUIDs (MusicBrainz ids) works on both engines.

### `catalogue_artists` (sync state per library artist)

The artist's MusicBrainz id is **not** moved: it stays a `metadata` row (`key=mbid`, `source` `musicbrainz` or `manual`, read by `Enrichment::mbid()`), which is where `EnrichTrackMusicBrainz` (from a track's recording) and the artist settings page already write it. No `artists.mbid` column: the sync reads it through `Enrichment::mbid` and the batch selection uses a `whereHas`, fine at a few thousand artists. What *is* needed is per-artist sync state that can be ordered ("oldest first"), which a text metadata value cannot do, hence a thin table (this answers "is `catalogue_artists` needed": yes, as sync state only, not as a second artist table; artists outside the library are not modelled in 1.0).

| Column | Type | Notes |
|---|---|---|
| `artist_id` | unsigned bigint, **PK**, FK `artists.id` ON DELETE CASCADE | one row per library artist that was ever synced |
| `mbid` | char(36) null | the mbid the last sync used; a changed `mbid` metadata triggers a full resync and drops the artist's `catalogue_albums` that are neither wanted, ignored nor linked |
| `status` | string(12) | `ok`, `no_mbid`, `not_found`, `unverified`, `error` |
| `release_group_count` | unsigned smallint null | kept rows after filtering |
| `synced_at` | timestamp null | last **successful** run |
| `next_sync_at` | timestamp null, **index** | the scheduler picks `next_sync_at <= now()` oldest first |
| `attempts` | unsigned tinyint default 0 | consecutive failures, for backoff |

### `catalogue_albums` (one row per release-group of a library artist)

Every release-group that passes the filter gets a row, **whether or not the library has it**; `album_id` says so. (Decision 3 keeps *missing* albums out of `albums`; keeping the row after promotion, instead of deleting it, preserves wish/ignore history and the mapping, and makes the discography one query.)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | exposed to clients; see 14.2 note below |
| `artist_id` | FK `artists.id` ON DELETE CASCADE, index via unique | the library artist whose discography it is; a collaboration listed under two library artists gets two rows |
| `release_group_mbid` | char(36) | |
| `title` | string(255) | `Normalizer::storedName` for longer |
| `name_key` | string(255) | `Normalizer::album($title)`, set by a `saving` hook like `Album` (same edition-marker rules, so "Deluxe" of a release-group still matches the owned "Deluxe Edition") |
| `type` | string(8) | `album` or `ep` (only those are stored by default) |
| `first_release_date` | string(10) null | MusicBrainz dates are partial (`1999`, `1999-05`); a `date` column would reject them under MariaDB strict mode, so the text is kept as is |
| `first_release_year` | unsigned smallint null, index `(artist_id, first_release_year)` | for ordering and matching |
| `disambiguation` | string(255) null | MusicBrainz's, shown when two entries share a title |
| `album_id` | FK `albums.id` **ON DELETE SET NULL**, null, index | the promotion link; null = the library has no such album |
| `linked_at` | timestamp null | when linked |
| `link_method` | string(12) null | `mbid` (album metadata `release_group_mbid` equals), `name` (key + year), `manual` |
| `fulfilled_at` | timestamp null | set when the linked album has an **owned** provider (own files, physical, owned media server); closes a wish. Cleared again if ownership disappears |
| `wanted_at` | timestamp null, index | household-wide wish |
| `ignored_at` | timestamp null | "I don't want this"; hidden from the discography by default, from the wishlist, from "missing" counts |
| `seen_at` | timestamp | last sync that returned it; a row not seen for two syncs and neither wanted, ignored nor linked is deleted |
| timestamps | | |

Keys: **unique `(artist_id, release_group_mbid)`**; index `(artist_id, name_key)` (the linker's query); index `album_id`; index `wanted_at`. `wanted_at` and `ignored_at` are mutually exclusive (setting one clears the other; enforced in the model, no CHECK constraint: Laravel has no portable builder for it and SQLite adds them only at table creation).

What is **not** stored: a cover. The cover is the Cover Art Archive front of the release-group (`https://coverartarchive.org/release-group/{mbid}/front-500`, an existing source of `EnrichAlbumMusicBrainz`); it goes through the normal artwork pipeline (`LibraryItemArtwork::proxy`, "queued at most once an hour, never waited for") **only for rows a page or the API actually shows**, so storage and traffic stay small.

### `album_availability` (lookup providers' answers)

One row per (entity, provider). The entity is an `albums` row **or**, while the library has no such album, a `catalogue_albums` row.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `album_id` | FK `albums.id` ON DELETE CASCADE, null | set when the album is in the library |
| `catalogue_album_id` | FK `catalogue_albums.id` ON DELETE CASCADE, null | set while it is not |
| `provider` | string(48) | `spotify`, `deezer`, `jellyfin:3`; wide enough for an instance suffix |
| `status` | string(12) | `available` or `unavailable` (**negative cache**: searched, not found, so it is not searched again every night) |
| `external_id` | string(255) null | Spotify album URI, Deezer id, Jellyfin item guid; null when `unavailable` |
| `confidence` | unsigned tinyint | 0-100; rows below the store threshold (70) are never written as `available` |
| `match` | string(12) | `link` (MusicBrainz URL relation or known id), `search`, `reported` (a speaker's now playing), `manual` |
| `checked_at` | timestamp, index `(provider, checked_at)` | recheck selection; positive rows after 180 days, negative after 90 (`recheckAfterDays`) |
| timestamps | | |

Keys: **unique `(album_id, provider)`** and **unique `(catalogue_album_id, provider)`**: NULLs do not collide in either engine, so a row with only one of the two set is unique per provider. Exactly one of the two FKs is set (enforced in the model/repository). **Rule: the row lives on the most specific entity**: on the album once the catalogue row has an `album_id` (playback already holds an `Album` and wants one indexed lookup), on the catalogue row before. **Promotion moves the rows** (`UPDATE ... SET album_id, catalogue_album_id = NULL`; on a unique collision keep the newer `checked_at`).

Existing id sources: `spotify_album_uri` stays the Spotify id for real albums (derived provider reads it; the Spotify sync does not copy it). **This supersedes** [streaming-services.md](streaming-services.md)'s idea of `deezer_album_id` album metadata: album ids for new providers live here (one place, with confidence and `checked_at`); track-level ids stay metadata (`deezer_track_id`), as tracks are outside this table.

### Existing queries stay untouched

- No column or index is added to `albums`, `artists`, `tracks`, `metadata`.
- No catalogue row appears in any existing list, count, shuffle, search, genre page, artwork pre-cache list or export: they query `albums`, which has no missing albums. The linkage is **from** the new table (`catalogue_albums.album_id`), so reading an album never joins it unless an endpoint asks.
- Filters are additive and opt-in: `?availability=` adds a `where` only when present.
- `LibrarySources` hidden-source filters keep working on albums; catalogue rows are filtered by their own rules (below), never by the client's `hidden_sources`.

### Matching an owned album to a catalogue row (the linker)

`CatalogueLinker::link(Album)` and `::linkArtist(Artist)`:

1. **By id:** the album's `release_group_mbid` metadata (written by `EnrichAlbumMusicBrainz`) equals a row's `release_group_mbid`. Confidence high, `link_method=mbid`.
2. **By name:** same artist, equal `name_key`. If one candidate: link. If several (self-titled albums: Weezer's Blue, Green and White albums all key to `weezer`): pick the one whose `first_release_year` is closest to `albums.released_at`, only when it is the unique closest within 2 years; else **do not link** (stays missing, flagged for a manual link).
3. **Year tolerance is a tie-break, not a gate:** an owned reissue often carries the reissue year (a 2011 remaster of a 1973 album), so a year mismatch alone never blocks an exact `name_key` match.
4. **Track-count sanity** applies to *service* matches (Spotify/Deezer: `total_tracks` against the owned or MusicBrainz track count within ±max(3, 30%), since deluxe editions are longer), not to the local link, because a release-group has no track count.
5. Fuzzy (non-equal key) matches are **never** made automatically. Low-confidence = not stored.

When a link is made, `fulfilled_at` is set if the album has an owned provider (`Availability::isOwned`), and any `album_availability` rows on the catalogue row move to the album.

## 3. Sync jobs

### Where jobs run

Enrichment jobs today run on `default`, which `health,default` workers share with the heartbeat (a stuck job there silences the scheduler heartbeat and trips the watchdog). Catalogue jobs are short but numerous and rate limited, and a rate-limit release must not delay the heartbeat. **Proposal: a new queue name `catalogue`, served by the existing `heavy` worker** (`--queue=heavy,catalogue` in `docker/8.4/supervisord.conf` and `supervisord.prod.conf`): no extra PHP process on the Pi, `heavy` keeps priority, and it is added to `Queues::all()` so the health page and the stuck-queue alert see it. (If the owner prefers an own worker: one more `[program]`, ~40 MB.) The jobs use the existing `EnrichesFromSource` shape: `ShouldBeUnique`, `RateLimited('musicbrainz')` (that limiter is a shared cache counter, so these jobs and the enrichment jobs on `default` share one 1/s budget across workers), `maxExceptions`, growing `backoff()`, `retryUntil` 12 h, `deleteWhenMissingModels`.

### `catalogue:sync` (command, scheduled nightly at 02:30, before the 03:30 file scan; albums the scan creates link through the observer)

`php artisan catalogue:sync [--artist=ID] [--limit=150] [--dry-run] [--force]`. Selects artists with an album in the library and `next_sync_at` null or due, **owned artists first** (own-file artists, then favourites/recently played, then the rest), dispatches `SyncArtistCatalogue` for at most `--limit`. It never loops over everything in one run: the backlog is worked off over nights, like `library:enrich`.

### `SyncArtistCatalogue(artist)`

1. `mbid = Enrichment::mbid($artist)`. Missing → step 2; present → step 3.
2. **No mbid:** `GET artist?query=artist:"{name}"&limit=5`. Accept a candidate only if its `name_key` equals ours **and** `score >= 95` **and** it is the only such candidate. Then browse its release-groups (step 3) and **verify**: at least one owned album's `name_key` must equal a release-group's; otherwise discard (`status=unverified`, nothing written, retried in 90 days or when an album is added). Only a verified id is saved (`Enrichment::save($artist,'mbid',…,'string','musicbrainz')`, never overriding a `manual` id), which also feeds `EnrichArtistMusicBrainz`.
3. **Browse:** `GET release-group?artist={mbid}&type=album|ep&limit=100&offset=…` until a page returns fewer than 100. Client side, keep rows with `primary-type` in `album`/`ep` and an **empty `secondary-types`**. Upsert on `(artist_id, release_group_mbid)`: title, `name_key`, type, dates, `seen_at`.
4. **Match:** `CatalogueLinker::linkArtist` (no network).
5. **Clean up:** delete rows not seen, neither wanted, ignored nor linked. Set `catalogue_artists` (`synced_at`, `next_sync_at` = now + 30 days ± random 5 days for artists played in the last 90 days or favourited, +90 days otherwise, `attempts=0`).

**Idempotent:** rerunning changes nothing but `seen_at`/`synced_at`. **Failure:** 429/503 and network errors throw → retry with backoff (no state written until the whole artist succeeded: pages are collected in memory, then upserted in one transaction); a 404 → `not_found`, retry in 90 days; three consecutive failures raise a `catalogue_sync` alert through `Alerts::raise` (workstream 14.1), resolved by the next success. A 503 from MusicBrainz means the IP is blocked for a while: the job releases with the longest backoff instead of retrying fast, so one burst never keeps the address blocked.

### Request estimate for ~800 albums

Assumption (measured 2026-10-30, see the last section: really 629 artists with albums, 35% with an mbid, so the estimate below is about half of the real first run).

| Step | Requests |
|---|---|
| First sync, artists with mbid (280): 1 browse each, a few artists need 2 pages | ~300 |
| Artists without mbid (120): 1 search + 1 browse | ~240 |
| **First full sync** | **~540 requests ≈ 9-12 minutes of limiter time**, spread over 3-4 nights with `--limit=150` |
| Steady state: monthly refresh of everyone, quarterly for the idle | ~250/month ≈ 4 min/month |

The first run competes with the enrichment backlog for the same 1/s budget; `--limit` and the nightly slot keep it from starving per-track enrichment (which is what users notice first).

### Availability lookups (lookup providers)

`LookupAlbumAvailability(entity, provider)` (queue `catalogue`, limiter = the provider's: Spotify `spotify` 3/s, MusicBrainz not involved). Never run for every row at once:

- **Lazy:** opening an artist page (web or `?include=discography`) queues lookups for that artist's rows whose `checked_at` is null or past its recheck age, at most 40 per view (unique per entity+provider), so a page the user never opens costs nothing.
- **Eager for wishes:** every wanted row is rechecked weekly on every enabled provider (so "streamable now" can surface; roadmap 3: a wish becomes "available on Spotify" without the user looking).
- **Nightly trickle:** `catalogue:availability [--provider=] [--limit=200]` for owned artists, oldest `checked_at` first.

**Spotify** (`SpotifyAvailability`, uses `SpotifyLibraryApi`/the existing `SpotifyCatalog` helper in `app/Domain/Library`): per artist, **one** artist-albums call if `spotify_id` metadata exists and `/artists/{id}/albums` works (unverified: probe once; on 400/403/404 remember "unsupported" for a day), matching all its catalogue rows against the list; else one `search?q=album:"…" artist:"…"&type=album&limit=10` per row. Accept a candidate only when `Normalizer::album(name)` equals the row's `name_key`, an artist's `name_key` equals ours, `album_type` is `album`, and `total_tracks` is plausible; among several (original and remaster) prefer the release year closest to `first_release_year`. Confidence 90 (key + year ±1), 75 (key, other year), below 70 not stored (written as `unavailable`). Spotify unconnected → provider `enabled() = false`, nothing queued. Saved albums need no lookup: their `spotify_album_uri` metadata (set by `SpotifyLibraryImporter` and `LibraryPlayback::spotifyAlbumUri`) is read by the derived part of the provider.

Estimate for the same library (~2,400 missing albums): with artist-albums ~400-600 calls, with search ~2,400 calls at 3/s = ~15 min, still lazy/trickled; fine either way, the choice only depends on what Spotify's development mode still answers.

**Deezer/Tidal/media servers** implement `lookupArtist`/`lookup` against their own catalogue client when workstreams 5 and 15 get there; id harvest from speakers' now playing (`match=reported`) writes the same table.

### Promotion on import and ownership change

Albums are created through `LibraryIdentity::album()` in every path (`FileIndexer`, `DlnaLibraryScanner`, `SpotifyLibraryImporter`, `NowPlayingTrackResolver`, so `library:import` too). Hooks, deliberately **not** inside `LibraryIdentity` or `LibraryMerger` (14.2 is editing them):

1. `Album` model observer `created` / `updated(name)`: `CatalogueLinker::link($album)` — one indexed query on `(artist_id, name_key)`, plus the mbid check when the metadata exists. No job, no network.
2. `EnrichAlbumMusicBrainz` (and the Spotify importer) call `link` again after they write `release_group_mbid` / the album mbid, so a late id upgrades or fixes a name match.
3. **Ownership** (closing wishes): `CatalogueLinker::refreshOwnership(albumIds)` is called at the end of a file-scan batch, of `library:import`, of a DLNA scan and when a `physical_items` row is created. It sets or clears `fulfilled_at`. A nightly `catalogue:reconcile` does the same for everything as the safety net (and is the one code path tests rely on).
4. A streamable-only wish is **not** fulfilled by saving it in Spotify (that is `streamable`, not `owned`), matching the roadmap ("becomes owned (import, physical item)").
5. Import of a streamable catalogue album (`POST /api/library/catalogue/{id}/import`, Spotify: `ImportSpotifyAlbumById`) creates the album through `LibraryIdentity` and the observer links it.

### Interaction with stable IDs (14.2) and merging

- `LibraryMerger` must, **after 14.2 has landed**, re-point `catalogue_albums.album_id` from the merged-away album to the survivor, move `album_availability.album_id` (unique collision: keep the newer `checked_at`), and, on an artist merge, move `catalogue_albums.artist_id` and `catalogue_artists`; where `(artist_id, release_group_mbid)` now collides, keep the row with `wanted_at`/`ignored_at`/`album_id` (wanted beats ignored beats neither; earliest timestamp) and delete the other. That is one added step in `merge()`, plus the same in `LibraryRemover` for an album delete (`album_id` is `SET NULL` by the FK, the row survives: removing an album from the library makes it "missing" again, and the user can ignore it).
- Catalogue ids are client-visible (`PUT /api/library/catalogue/{id}/wanted`), and a collision delete retires one. Use the 14.2 mechanism: `id_aliases` already has `entity` as `string(16)`, `catalogue_album` is 15 characters; the merger writes an alias row, the catalogue endpoints resolve it. No new table.
- Nothing in 14.2 blocks the read-only parts (registry, tables, sync, API reads): they only add tables. The merger step and the alias resolution in the new endpoints wait for 14.2.
- `Normalizer::VERSION` bumps must re-key `catalogue_albums.name_key` too: `library:merge-duplicates` (which re-keys all rows) gets a `catalogue_albums` re-key line, or `catalogue:sync --force` re-derives it from `title`.

## Browse, play and add from an external source

Spotify already has the pieces (`SpotifyCatalog`, `ArtistMoreOnSpotify`/`AlbumMoreOnSpotify`, `ImportSpotifyAlbumById`, the Spotify path of `LibraryPlayback`); this section makes them provider-neutral so Deezer, Tidal and media servers are a driver, not new endpoints.

- **Search and browse** call the provider live (`BrowsesExternalCatalogue`), cache the answer briefly (5 minutes, keyed by provider+query), go through the provider's rate limiter, and are done in the request only because a user is waiting on one call; a failure shows "Spotify is not answering", never an error page. Nothing is stored: results are `ExternalItem`s. Each carries `in_library` (resolved with one `name_key` query) so the UI shows "Added" instead of an Add button.
- **Play** any item without importing it: `LibraryPlayback` gets `playExternal(provider, type, id)`; Spotify plays an album/artist/track context URI on a Connect-capable device, Deezer/Tidal hand an id to a device with `deezer_playback`/`tidal_playback`. It answers `422 not_playable` when the device cannot play that provider (the same `LibrarySources::playableOn` check).
- **Add** creates the artist, album and tracks through `LibraryIdentity` with `source` = the provider (the same path as the Spotify import), so the item appears in the streaming library, merges with copies from other sources and gets enriched. For an album: all its tracks; for an artist: the artist plus **all albums** the user confirms (a dialog lists them, album/EP only by default); for a track: the track on its album. The Album observer then links it to the catalogue, closing nothing (streamable is not owned) but turning "Missing" into "In your library on Spotify".
- **MusicBrainz is the spine of the discography, the provider fills in what it can play** (owner decision 2026-10-28: MusicBrainz from the start, because a streaming service often lacks part of an artist's discography, so its list alone would hide what is missing). For an artist the service knows, the artist page can show "On Spotify: 14 albums, 6 in your library" straight from `artistItems`; this also works as a first answer before the MusicBrainz sync has run for that artist. MusicBrainz stays the authority for *missing* (collector view); the provider list is the authority for *what you can stream now*. Matching the two reuses the Spotify lookup of section 3, so one artist-albums call fills `album_availability` for every catalogue row of that artist.
- Adding is what lets an artist with **no MusicBrainz id** still be wished or marked: once added, wish by title attaches to a library album through `albums` (see open question 4: for those artists a wish may be stored against the album's `release_group_mbid` when it appears, else as a manual catalogue row with `release_group_mbid` null). 

## 4. API and UI

Rules of the repo apply to each endpoint: an OpenAPI entry (`docs/api/openapi.yaml`), an MCP tool or extension (`app/Mcp/Tools` + the table in `docs/api/mcp.md`), a row in `docs/api/library.md` (or the new `docs/api/availability.md`) and the CLAUDE.md reference. All new routes are **guest-accessible like favourites**, except the two maintenance calls.

### Shapes

`CatalogueItem`:

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
  "availability": [
    { "provider": "spotify", "library": "spotify", "status": "available", "playable": true }
  ],
  "artwork": { "hash": "…", "proxy_120": "…", "proxy_320": "…", "colors": [], "safe_colors": [] }
}
```

`state` is `owned` | `streamable` | `known`; `album_id` is set when the library has the album (then it opens the normal album endpoints); `playable` is only present with `?device_id=` (the composition rule above); `artwork` is `null` until processed, like every list.

### Endpoints

```
GET  /api/library/artists/{id}?include=discography[&device_id=]   → the existing detail plus  discography: [CatalogueItem]   (opt-in; see "clients")
GET  /api/library/artists/{id}/discography?availability=owned|streamable|missing&wanted=1&ignored=1&device_id=   → { data: [CatalogueItem] }   newest first, ignored left out unless ignored=1
GET  /api/library/wishlist?cursor=&device_id=            → { data: [CatalogueItem], next_cursor }   wanted and not fulfilled, most recently wanted first; 50 per page
GET  /api/library/catalogue/{id}                         → CatalogueItem with the full provider list
PUT  /api/library/catalogue/{id}/wanted    { "wanted": true }    → { "wanted": true }    clears "ignored"
PUT  /api/library/catalogue/{id}/ignored   { "ignored": true }   → { "ignored": true }   clears "wanted"
POST /api/library/catalogue/{id}/import                  → { status, album_id }    streamable → create the library album (Spotify first); 422 not_importable
GET  /api/library/external/{provider}/search?q=&type=artist|album|track&limit=   → { data: [ExternalItem] }
GET  /api/library/external/{provider}/artists/{externalId}                      → { artist: ExternalItem, albums: [ExternalItem] }   in_library flags included
GET  /api/library/external/{provider}/albums/{externalId}                       → { album: ExternalItem, tracks: [ExternalItem] }
POST /api/library/external/{provider}/add   { type: artist|album|track, id, album_ids? }   → { status, added: { artists, albums, tracks } }   needs the provider enabled; 422 unknown provider / not_importable
POST /api/devices/{id}/library/play-external   { provider, type: artist|album|track, id, shuffle? }   → { status, name }   plays without importing; 422 not_playable
POST /api/admin/library/artists/{id}/catalogue-sync      → { status: "queued" }   admin token
```

- A catalogue row of an album the library has **redirects the caller to the album** (`album_id`), so play and favourite stay on the existing endpoints; `play-external` (with the row's provider and `external_id`) is for rows without `album_id`. Ids resolved through `id_aliases` answer normally with `canonical_id` (14.2 pattern).
- **`?availability=` on the browse endpoints** (`/library/albums`, `/library/artists/{id}` albums, `/library/search`): `owned` and `streamable` filter albums by the provider rule (`LibrarySources::availableFrom` for derived, an `exists` on `album_availability` for lookups). **`missing` cannot be an album filter** (missing albums have no `albums` row and `AlbumItem` has no shape for them): it is valid only on `…/discography` and `…/catalogue`; on `/library/albums` it answers `422 invalid`. This narrows what the owner wrote ("`?availability=owned|streamable|missing` on the browse endpoints") and is listed in the open questions.
- Capabilities: no device capability is added; `playable` rides on the existing `library_playback` and per-library checks.

### What clients must never see

Touch ESP32 clients and the artwork pre-cache read `albums` and the `…/artwork` list; neither can contain a catalogue row because those queries never join the new tables. The only places a `known` row is returned are the explicit endpoints above, and `?include=discography` is **opt-in**: the existing artist detail response is byte-for-byte unchanged for a client that does not ask. A request with `?client={api_token}` ignores `include=discography` and the catalogue endpoints answer `403` for a registered client token (they are for the household's apps and the web UI). The iOS app opts in explicitly. `known` rows never count in `album_count`, shuffles, random album, play-tracks or `GET /api/library/libraries`.

### MCP

One tool `catalogue` (`app/Mcp/Tools/Catalogue.php`) with actions `discography`, `wishlist`, `wish`, `unwish`, `ignore`, `unignore`, `import`, and one tool `external` (`search`, `artist`, `album`, `add`, `play`) for browse/play/add; the `sync` action is ADMIN (listed only with the admin bearer token, like the maintenance tools). It goes through `ApiBridge`, so the REST API stays the single implementation. `BrowseLibrary` gains the `availability` argument.

### Web (no views are changed by this document)

- **Search** (the library search page gains a provider switch, "Spotify" next to "Library"): result rows with Play, Add (or "Added") and a link to the external artist/album page, which lists the artist's albums with the same Play / Add / Wish row as below. Add on an artist opens the album picker.
- **Artist page** (`/artists/{id}`, Overview tab): a "Discography" panel below the albums using the shared `x-card`, `x-badge` and `x-empty-state` components (docs/frontend/components.md), one row per entry: cover, title, year, type, an `x-badge` for the state (`Owned`, `On Spotify`, `Not in your library`), **Play** when a provider has it and the pinned device can play it, **Add to library**, a Wish control ("Want to buy or rip") and an overflow "Not interested". A chip row filters All / Missing / Wanted; above 12 entries only the first 12 show with "Show all 34", because a long-career artist (a hundred release-groups) must not push the page's real content down. Albums the library has link to `/albums/{id}`. The source switch of the page does not apply to missing rows.
- **Playable-on labels:** a streamable entry reads "Plays on Kitchen, Study" (devices from `LibrarySources::playableOn` per provider), or "Not playable on any device yet" for owned-elsewhere or known entries; on the pinned device the play button appears only when playable there, like `x-play-button`.
- **Wishlist shelf** on `/library` (`x-shelf`, as the other shelves; hidden when empty) and a `/library/wishlist` page for the full list with "Remove wish" and the streamable badge.
- Livewire stays the renderer: the lookups finish in the background, so the panel refreshes through the same `$wire.$refresh()` glue (a short poll while lookups for the artist are pending), no new JS framework.

## For the schema review (14.3)

| Table / column | Change | Notes |
|---|---|---|
| `catalogue_artists` | new | PK `artist_id` (FK cascade); `mbid` char(36) null; `status` string(12); `release_group_count` usmallint null; `synced_at` ts null; `next_sync_at` ts null **index**; `attempts` utinyint; no timestamps needed (sync state) |
| `catalogue_albums` | new | `id`; `artist_id` FK cascade; `release_group_mbid` char(36); `title` string(255); `name_key` string(255); `type` string(8); `first_release_date` string(10) null; `first_release_year` usmallint null; `disambiguation` string(255) null; `album_id` FK albums **set null** null; `linked_at` ts null; `link_method` string(12) null; `fulfilled_at` ts null; `wanted_at` ts null; `ignored_at` ts null; `seen_at` ts; timestamps. **Unique** `(artist_id, release_group_mbid)`; indexes `(artist_id, name_key)`, `(artist_id, first_release_year)`, `album_id`, `wanted_at` |
| `album_availability` | new | `id`; `album_id` FK albums cascade null; `catalogue_album_id` FK catalogue_albums cascade null; `provider` string(48); `status` string(12); `external_id` string(255) null; `confidence` utinyint; `match` string(12); `checked_at` ts; timestamps. **Unique** `(album_id, provider)`, **unique** `(catalogue_album_id, provider)`; index `(provider, checked_at)` |
| `wanted_tracks` | new (owner request 2026-10-28) | `id`; `artist` string(255); `title` string(255); `album` string(255) null; `image_url` text null; `artist_key` string(255) (`Normalizer::artist`); `title_key` string(255) (`Normalizer::track`); `track_id` FK tracks **set null** null (index); `source` string(12) (`history`, `radio`, `manual`); `wanted_at` ts; `fulfilled_at` ts null; timestamps. **Unique** `(artist_key, title_key)`; index `wanted_at`. Not tied to `catalogue_albums`: a song heard on the radio often has no album and its artist may not be in the library |
| `id_aliases.entity` | new value | `catalogue_album` (15 chars, fits `string(16)`); no schema change, but the plan must list it |
| `albums`, `artists`, `tracks`, `metadata` | **none** | the point of decision 3 |
| Others needing the same review | | `physical_items.album_id` (6) and `media_servers` (15) are provider truth for the derived/lookup providers; their FKs to `albums` should use the same ON DELETE choices (album delete → null for physical items, cascade for availability) |

**MariaDB vs SQLite:** no CHECK constraints (exclusive FK and wanted/ignored are enforced in code); no `date` column for partial dates; UUIDs `char(36)`; unique indexes over a nullable FK rely on NULL-distinct semantics, which both engines have; every string key stays ≤ 255 chars (the utf8mb4 index-length caveat of `database.md`; `name_key` is already 255 on `albums`); FK `SET NULL` needs SQLite foreign keys on, as the tests already do; no JSON columns are needed. Nothing here uses driver-specific SQL.

**Storage growth:** ~10 release-groups per artist (albums + EPs) × 400 artists ≈ 4,000 `catalogue_albums` rows (~1 MB) and a few thousand `album_availability` rows. Even a 5,000-artist library stays in the low tens of MB.

## 5. Build order (small PRs, each with tests and docs)

Dependencies: **14.3** (schema plan) is where PR 2's tables are written straight into the consolidated set; until the squash they are normal migrations with the comment the `id_aliases` migration uses. **14.2** must land before PRs 6 and 8's alias handling and before PR 9. The **credentials layer (14.4) is done**: Spotify's connection state feeds `enabled()`; Deezer/Tidal declarations arrive with their providers.

| # | PR | Depends on | Notes |
|---|---|---|---|
| 1 | `Availability` read service + provider registry + `config/availability.php`, derived providers (`files`, `dlna`, `spotify` saved), `Ownership` on providers; test that registry and `LIBRARIES` agree | none (touches `LibrarySources` only by reading; **does not touch** the merger or controllers) | no schema; can start now |
| 1b | **External browse/play/add:** `BrowsesExternalCatalogue`, `ExternalItem`, `LibraryPlayback::playExternal`, `/api/library/external/*`, `play-external`, Spotify implementation on the existing catalogue code, search UI switch; OpenAPI, MCP `external`, docs | 1 (no schema, no controllers shared with 14.2 beyond new routes: add after 14.2 lands if it still edits `LibraryController`/routes) | the streaming-first core, built in parallel with the MusicBrainz track (2, 3); ships value without MusicBrainz or any new table |
| 2 | Migrations + models: `catalogue_artists`, `catalogue_albums`, `album_availability`; factories; model rules (exclusive wanted/ignored, `name_key` hook) | 14.3 plan reviewed | parallel with 1 |
| 3 | `MusicBrainzClient::browseReleaseGroups`, `SyncArtistCatalogue`, `CatalogueLinker`, `catalogue:sync`, `catalogue` queue on the `heavy` worker, `Queues::all`, `catalogue_sync` alert, Album observer; `Http::fake` tests incl. paging, filter, idempotence, 503 | 1, 2 | verifies the unverified browse fields with one real call first; docs/architecture update |
| 4 | Artist mbid discovery (search + verify by owned albums) | 3 | **required, part of the first release**: the discography is only as complete as the artists' mbids |
| 5 | Read API: `…/discography`, `include=discography`, `/library/wishlist`, `/catalogue/{id}`, `?availability=` owned or streamable; OpenAPI, `docs/api/availability.md`, MCP `catalogue` (read actions) | 3, **14.2 landed** (controllers, routes) | |
| 6 | Wish and ignore endpoints, `wanted_at`/`fulfilled_at` rules, `catalogue:reconcile`, ownership hooks in scan/import end; MCP actions | 5 | closes wishes |
| 7 | **`LibraryMerger`/`LibraryRemover` integration** and `catalogue_album` alias resolution | **14.2 landed** | the interaction with stable IDs; do not start earlier |
| 8 | Spotify availability for catalogue rows (`SpotifyAvailability`, lazy and trickle jobs; reuses 1b's client), `import` | 1b, 3, 5 | needs the artist-albums probe result |
| 9 | Web: discography panel, wishlist shelf and page, playable-on labels | 5, 6, 8 | follows DESIGN.md and the components |
| 10+ | Providers as separate PRs: Deezer/Tidal (workstream 5), physical (6), media servers (15), ListenBrainz new releases into `catalogue_albums` (4) | 1-3 | each is "a declaration plus a driver", and proves the contract; if one needs a change in the API or web, the contract is wrong |

Parallel (MusicBrainz track 2 → 3 → 4 → 5 and browse track 1b run side by side, both in the first release): 1 ∥ 2; 1b ∥ 2/3 (different code); 4 ∥ 5; 9 ∥ 8. Strictly sequential: 3 after 1+2, 7 after 14.2.

## 6. Risks and open questions

### Risks

- **Artist mbid coverage is the ceiling.** The mbid arrives only from `EnrichTrackMusicBrainz` (a track's recording, MusicBrainz search) or by hand. Artists without it get no discography unless step 2 of the sync (search + verify) works. **Measure first** (read-only; run in the production container):

  ```bash
  php artisan tinker --execute='$a=\App\Models\Media\Artist::whereHas("albums"); $with=(clone $a)->whereHas("metadata",fn($q)=>$q->where("key","mbid")->whereIn("source",["musicbrainz","manual"])); echo $with->count()." of ".$a->count()." artists with albums have an mbid".PHP_EOL; $al=\App\Models\Media\Album::count(); $rg=\App\Models\Media\Album::whereHas("metadata",fn($q)=>$q->where("key","release_group_mbid"))->count(); echo "$rg of $al albums have a release_group_mbid".PHP_EOL;'
  ```

  (Run 2026-10-30 on the dev library: 221 of 629 artists, 33 of 996 albums; see the last section.) PR 4 (search + verify) is in the first release regardless; the number says how much it will have to do.
- **MusicBrainz data quality:** duplicate release-groups for one record (rare, shown twice until one is ignored), release-groups of the wrong primary type, an artist merged into another (the stored mbid redirects or 404s), self-titled albums that share a key (the linker refuses ambiguous matches rather than guessing, so such an album shows both as owned elsewhere and missing until linked by hand), compilations credited to the artist, classical works where "album" is a performance. Mitigation: `ignored`, the manual link, and a conservative linker.
- **UI noise:** an artist with 80 albums and EPs. Collapsed list, default sort newest first, "Missing" chip, ignored hidden; the wishlist is the only global surface, and nothing about missing albums appears in counts.
- **MusicBrainz block:** exceeding 1 request/s declines the whole IP. All MusicBrainz calls use the shared limiter, and the first-sync batch is capped.
- **Spotify availability depends on what development mode still answers** (artist-albums, search ≤ 10); the design degrades to "no Spotify data", never to an error, and the provider reports `enabled()=false` when the connection is lost.
- **Storage:** negligible (see above); the artwork for missing rows is the only growth, and is processed only on demand.
- **Observer cost on huge scans:** one indexed query per created album; measure on a 10k-album scan, and move to an end-of-scan `linkArtist` batch if it shows.

### Where the fixed decisions meet the code

1. **"Owned" vs `LIBRARIES.kind`:** DLNA is `streaming` there; the roadmap table calls own files owned and DLNA ("stream from this server / DLNA") an owned playback path. Resolved by a separate `ownership` field on providers (above); needs the owner's yes.
2. **Missing albums "in their own table" vs a clean promotion:** the row is kept after promotion (linked) instead of moved. Not a conflict with the intent, a deviation from the literal word "promoted".
3. **`?availability=missing` on browse endpoints** cannot return `albums` rows (there are none): narrowed as above.
4. **streaming-services.md says album ids for Deezer live in `Metadata`:** superseded for albums by `album_availability`; that document needs a one-line pointer when PR 8 or the Deezer work starts.
5. **Roadmap 15 wants media servers as library providers with a fixed `LIBRARIES` constant**, which cannot hold server instances: needs the suffix rule (`jellyfin:3`) or a dynamic registry.
6. **Wishing a Spotify-saved album:** it has an `albums` row but is only streamable. The wish lives on the catalogue row (every release-group has one), which is why `catalogue_albums` keeps linked rows. Without an mbid for the artist, such an album **cannot be wished** (no catalogue row); acceptable? (Question 4.)
7. **Artwork pre-cache and clients:** safe by construction (nothing joins the new tables), but the pre-cache list's "never hidden" claim should be covered by a test that adds missing rows and asserts `GET /api/clients/{token}/artwork`, `/api/library/albums`, counts and random album are unchanged.

### Questions the owner must answer before implementation

1. ~~Is DLNA owned?~~ **Answered: yes** (badge and closing wishes); syncing DLNA into own files is a later, separate step.
2. Run the mbid one-liner above and tell the coverage; it sizes PR 4's workload (PR 4 is in regardless).
3. Queue: add `catalogue` to the existing `heavy` worker (recommended, no extra process), or its own worker?
4. May an album be wished only if its artist has an mbid (and so a release-group)? Or is a manual "wish by title" for artists without one needed?
5. Should a wish also close when the album is saved in Spotify (streamable), or only when owned (recommended: only owned, matching the roadmap)? A "wanted, now streamable" badge covers the rest.
6. Is the 30/90-day refresh policy acceptable, or should new releases be detected faster once ListenBrainz (workstream 4) is in?
7. Spotify availability: lazy-on-view plus wishes plus a trickle for owned artists (recommended), or eager for the whole catalogue?
8. Toggle for singles/live/compilations: confirm it stays out of 1.0, and that rows are not stored for those types (turning it on later means a resync, about 250 requests).
9. ~~Order of 1b vs MusicBrainz~~ **Answered: MusicBrainz from the start**, two tracks in parallel (1b and 2-5). **Answered (2026-10-28): Spotify-only is enough for browse, play and add in 1b's first release, but not for discovery**: discovery and the discography (missing albums, wishlist, new releases) are provider-independent and come from MusicBrainz, so nothing in tables 2 to 5 may assume Spotify. Providers only add "playable here" to rows MusicBrainz already lists.
10. `?availability=missing` only on discography/catalogue endpoints: confirm the narrowing.

## Built so far (2026-10-28)

PRs 2 and 3 of the build order, in one change: migration `2026_10_28_200000_create_catalogue_tables.php` (`catalogue_artists`, `catalogue_albums`, `album_availability`, as in this document), models `CatalogueAlbum` (name key and wish/ignore exclusivity in `saving`), `CatalogueArtist`, `AlbumAvailability`; `MusicBrainzClient::releaseGroups()` (pages of 100, album/EP, no secondary types); `App\Domain\Catalogue\ArtistCatalogueSync` and `CatalogueLinker`; job `SyncArtistCatalogue` on the `catalogue` queue (served by the `heavy` worker in both supervisor files, listed in `Queues::all()`, `catalogue.sync` alert after three failed artists); commands `catalogue:sync [--artist] [--limit=150] [--force] [--dry-run]` (02:30) and `catalogue:reconcile` (05:00); an `Album` model event links new or renamed albums. Tests: `tests/Feature/CatalogueSyncTest.php`.

Differences from the text above: vanished release-groups are removed by comparing with the ids of the **current** sync (not "two syncs"), unless wished, ignored or linked; DLNA and own files count as owned, physical items join with workstream 6; artists without an mbid are searched by name (`MusicBrainzClient::searchArtists`) and taken only when the name key is equal, the score is at least 95 and exactly one candidate qualifies, **and** one of the library's albums by the artist equals one of the candidate's release-groups (PR 4, built; only a verified id is saved, as `mbid` metadata); otherwise the status is `unverified` (a candidate failed the checks) or `no_mbid` (none), retried in 90 days; the browse response fields (`primary-type`, `secondary-types`) were confirmed with a real call on 2026-10-30 (and the browse now adds `release-group-status=website-default`).

PR 5 (read API): `App\Domain\Catalogue\CatalogueItems`, `LibraryController::discography/wishlist/catalogueAlbum`, `include=discography` on the artist detail, `?availability=owned|streamable` on `/library/albums` (not on the artist's albums or search yet), MCP tool `catalogue`, `docs/api/availability.md`, OpenAPI. The state is computed from tracks (own files and DLNA = owned) and `album_availability` rows; the provider registry (PR 1) is not built, so `CatalogueLinker::OWNED_SOURCES` stands in for the ownership field until it is. Catalogue ids do not use `id_aliases` yet (PR 7).

PR 6 (wish and ignore): `PUT /api/library/catalogue/{id}/wanted|ignored`, MCP actions `wish`/`unwish`/`ignore`/`unignore`, and the ownership hooks: `FileIndexer` (every indexed file, so also `library:import` and uploads) and `DlnaLibraryScanner` (every track) call `CatalogueLinker::refreshOwnershipQuietly`, one indexed query when the album has no release-group, which sets or clears `fulfilled_at`; `catalogue:reconcile` (nightly) covers removals. Physical items will call the same method (workstream 6).

PR 7 (merging and stable ids): `App\Domain\Catalogue\CatalogueMerge`, called by `LibraryMerger` before it deletes a record. Merging albums moves `catalogue_albums.album_id` and the loser's `album_availability` rows to the survivor (same provider: the newer `checked_at` wins) and refreshes ownership, so files that came with the merge close a wish. Merging artists moves the discography; a release-group both have becomes one row (the survivor's, with the earliest wish, else the earliest "not interested", the link and the lookup answers of both) and the retired id is written to `id_aliases` as `catalogue_album`, so `GET`/`PUT /api/library/catalogue/{id}` keep working and answer with `canonical_id`; the loser's `catalogue_artists` row goes and the survivor is synced again soon. `LibraryRemover` needs no change: removing an album leaves its row as missing (`album_id` set null by the foreign key). Sync cleanup forgets aliases of release-groups it deletes. Route parameter `{catalogue_album}` is bound through `IdAliases` like `{artist}` and `{album}`.

PR 8 (Spotify availability and import): `App\Domain\Catalogue\SpotifyAvailability` (`lookupArtist`: one artist-albums listing through `SpotifyCatalog::artistAlbums` matched against every stale release-group of the artist; equal name key, confidence 90 within a year and 75 otherwise, below 70 recorded as `unavailable`; answers asked again after 180 days found, 90 not found, 7 for a wished album; albums the library already has from Spotify and ignored ones are skipped), job `LookupSpotifyAvailability` (queue `catalogue`, `spotify` limiter, unique per artist), queued lazily when an artist's discography is read (once an hour per artist, only with Spotify connected) and by `php artisan catalogue:availability [--limit=100] [--dry-run]` (nightly 03:00, wished albums first). The answer lives on the release-group and **moves to the library album** when the album is linked (promotion). `POST /api/library/catalogue/{id}/import` queues `ImportSpotifyAlbumById` for an entry with an available Spotify answer and no library album (`202 {status: queued}`; the observer then links the new album); MCP action `import`. Not done: the track-count sanity check (the artist-albums listing has no track count; a candidate with an equal name key is trusted), and `play-external` (PR 1b).

PR 9 (web): `App\Livewire\Catalogue` and `livewire/catalogue.blade.php` (discography card on the artist page, wishlist page `/library/wishlist`, Wishlist shelf on `/library`), described in `docs/frontend/library.md`. Built from existing parts (`x-card`, `x-badge`, `x-album-grid`, `x-artwork-thumb`, `x-media-card`, `x-shelf`); no new component. The name `x-discography` was taken by the library's own album grid, so the new panel is the Livewire component `catalogue`. No play button for streamable-only entries until PR 1b.

Wished songs (owner request: favorite and wishlist songs from the play history, radio tracks included): table `wanted_tracks` (see the schema table), `App\Domain\Catalogue\WantedTracks`, model `WantedTrack`. **One wish per (artist, title) key**, household-wide; the library track of the song is linked when there is one (`track_id`), and the wish closes when that track has an own file or a DLNA copy (`CatalogueLinker::OWNED_SOURCES`, the album rule). `FileIndexer` and `DlnaLibraryScanner` call `WantedTracks::refreshForTrackQuietly` per track (a lookup by title key, nothing when no wish matches), `catalogue:reconcile` refreshes every wish. **History rows** (`Livewire\PlayHistory`) got a heart and a star: the heart favorites the song and, when the play is only logged as text or is a radio stub, first puts it in the library (`PlayedTrackAdder::addFromPlay`: Spotify match if connected, else the artist/title/album of the play; a stub the station created becomes a normal track), the star wishes for it without touching the library. API `POST /api/library/wanted-tracks`, `DELETE /api/library/wanted-tracks/{id}`, and `songs` in `GET /api/library/wishlist`; MCP `wish_song`/`unwish_song`; the wishlist page and the `/library` shelf list songs. Not done: the heart/star on the station-level radio log (`radio_plays`, shown nowhere yet) and a "Songs" availability lookup on Spotify.

PR 1b (browse, play and add, Spotify first): `config/availability.php` (provider id to class), `App\Domain\Availability\{BrowsesExternalCatalogue, ExternalItem, ExternalCatalogues, SpotifyBrowser}`, `LibraryPlayback::playExternal`, job `ImportSpotifyTrackById`, `Api\ExternalCatalogueController` and `DeviceController::libraryPlayExternal`, MCP tool `external`, Play and tracks in the web search. Documented in `docs/api/availability.md`. A test asserts that every provider in the registry has a `LibrarySources::LIBRARIES` entry. **Differences:** the contract is smaller than the sketch above (`search`, `artist`, `album`, `add`; no `lookup`/`lookupArtist`, which `SpotifyAvailability` does directly), unknown provider is `404` (not `422`), add answers `202 {status, queued: {albums, tracks}}`, and the derived-availability read service (the original PR 1: an `Ownership` field per provider) is still not built: `CatalogueLinker::OWNED_SOURCES` stands in. No separate external artist or album *pages* in the web, only the search block.

New releases (workstream 4 on top of the catalogue): `App\Services\ListenBrainz\ListenBrainzClient::freshReleases()`, `App\Domain\Catalogue\NewReleaseScan`, job `ScanNewReleases` (queue `catalogue`, limiter `listenbrainz` 1/s), commands `catalogue:new-releases` (daily 02:15), `CatalogueItems::newReleases`, `GET /api/library/new-releases`, MCP `new_releases`, the *New from your artists* shelf. **Design choice:** the feed is a *trigger*, not a source: its global release list has no secondary types (live, compilation) and covers every artist, so a library artist with an unknown release-group only gets its MusicBrainz sync queued early and the shelf reads `catalogue_albums` (full dates, not linked, not ignored). Facts checked 2026-10-28 on https://listenbrainz.readthedocs.io/en/latest/users/api/misc.html and `.../api/index.html`: `GET /1/explore/fresh-releases/` takes `release_date`, `days` (max 90), `sort`, `past`, `future` and returns `artist_credit_name`, `artist_mbids`, `release_date`, `release_group_mbid`, `release_group_primary_type`, `release_mbid`, `release_name`; at most one call a second, an identifying User-Agent. **Verified 2026-10-30:** the endpoint needs no token (a call without one answers 200), the body is `{payload: {releases, total_count}}`, and rows carry `release_group_secondary_type` (so the scan now skips live and compilation rows); see the last section. The per-user feed (`/1/user/{name}/fresh_releases`, with `confidence`) is not used: it needs a ListenBrainz user. Not built: a ListenBrainz token field and the optional scrobbling.

External pages (owner request: browse albums on a streaming service, not only add them): `Livewire\ExternalArtist` and `Livewire\ExternalAlbum` at `/external/{provider}/artists|albums/{id}` (the web side of the external catalogue API; no new endpoints), links from the search box and *More on Spotify*, a play button on those cards; described in `docs/frontend/library.md`. `SpotifyCatalog::artistAlbums` now stores the Spotify artist id it finds by name as `spotify_id` metadata.

Provider registry (the original PR 1, small): `config/availability.php` `providers` (`files`, `dlna` and `physical` owned, `spotify` streamable; a provider has only its `ownership`, because its id is its `LibrarySources::LIBRARIES` key when it is a library) and `App\Domain\Availability\Providers` (`ownership()`, `isOwned()`, `anyOwned()`, `ownedLibraries()`, `streamingLibraries()`), replacing the `CatalogueLinker::OWNED_SOURCES` stand-in. A test asserts every library has a provider.

Physical provider (workstream 6, its own part, built as the `physical` provider of this layer): table `physical_items` (the item has its own artist, title, year, medium, barcode, location, `release_group_mbid`, an optional `album_id` set null when the album goes, `ripped_at`, `file_id`; for the schema review 14.3: indexes on barcode, `(artist_key, title_key)`, `release_group_mbid`, `album_id`, medium), `App\Domain\Catalogue\PhysicalItems`, API/MCP/web in `docs/api/physical.md`. It is **owned** in `config/availability.php`. Ownership is read three ways: `CatalogueLinker::isOwned($album)` (an item with the album's id), `CatalogueItems` (`physical` among the providers of a row, by album or by release-group), and a catalogue sync marking release-groups already on the shelf as fulfilled; `CatalogueLinker::refreshPhysical` sets or clears `fulfilled_at` when an item is added, changed or removed, and an album that appears after its item takes the item over. The barcode lookup uses MusicBrainz `release?query=barcode:…`; its field names (`artist-credit`, `label-info`, `media[].format`, `release-group`) were confirmed against a live call on 2026-10-30.

**Direction change (owner, 2026-10-28): the library is the local tracks (own files only: DLNA is never local, it is a streaming library that counts as owned); streaming extends it live.** Superseded in this document: everything about **adding to the library** from a service (the `add`/`import` endpoints, `ImportSpotifyAlbumById`/`ImportSpotifyTrackById`, "Add" buttons, the "More on Spotify" import) and Spotify as a library (`LibrarySources::LIBRARIES['spotify']`, scope `spotify`, the saved-tracks and playlist sync, `fill-from-spotify`) is **gone**, with a migration (`library:drop-spotify`, `SpotifyLibraryPurge`) removing the rows an old import left. What a service gives instead: search and **play** (`play-external`, now also playlists), **browse** (an artist with all albums and popular tracks, an album, the account's playlists, all read live, `/external/{provider}/...` pages and the Spotify side of the artist and album pages), and **want** (`wanted_tracks`, source `streaming`; a streaming track is never favorited). `Providers` stays (Spotify is a *streamable* provider with lookup answers on release-groups; the discography card plays an entry with `Play on Spotify`, `external_id` is in `availability[]`), but it is no longer a library: `LibrarySources::STREAMING_SERVICES` names it and `Providers::playableOn()` answers per device. The provider contract lost `add`; `BrowsesExternalCatalogue` gained `playlists()`, `playlist()` and `popularTracks()` (Last.fm top tracks matched on Spotify when a Last.fm key exists, else Spotify search, because Spotify's top-tracks endpoint is gone). Left in place, now unreachable: the playlist modes (`PlaylistMode`, `PlaylistCoverage`, the playlist `library`/`mode` API fields), a candidate for removal with the next API cleanup.

## Verified against live services (2026-10-30)

Everything above was built and tested with `Http::fake` and invented fixtures. On 2026-10-30 the calls were made for real: one request at a time, at least a second apart, GET only, with the app's own User-Agent (`ReMoment/1.0 (remko@pionect.nl)`, which names a contact, as MusicBrainz asks). Trimmed real answers are the fixtures in `tests/Fixtures/musicbrainz` and `tests/Fixtures/listenbrainz`, pinned by `tests/Feature/AvailabilityLiveFixturesTest.php`.

| Call | Assumed | What is true | Status |
|---|---|---|---|
| MusicBrainz `GET /ws/2/release-group?artist={mbid}&type=album%7Cep&limit=100&offset=0&fmt=json` (Radiohead `a74b1b7f-…`, Dirty Three `af8bb921-…`, Bob Dylan `72c536dc-…`) | Fields `id`, `title`, `primary-type`, `secondary-types`, `first-release-date`, `disambiguation`; paging by count | All present on the browse without `inc=`; the body is `{release-group-count, release-group-offset, release-groups}`. `first-release-date` is `""` when unknown, and can lie in the future (`2026-11-06`). The `type=album\|ep` filter keeps primary types only: Radiohead has **417** album/EP groups, 56 of them bootleg compilations, and `secondary-types` (Live, Compilation, Demo, Remix, Interview ...) is still returned, so the client-side filter is needed and works. Dylan: 1,034 groups = 11 pages | Confirmed |
| same, plus `release-group-status=website-default` | not used | Radiohead 417 → **41** groups (the same 18 plain albums/EPs, minus one unofficial "Radiotick Tracks 2"); Dylan 1,034 → 225; Dirty Three 20 → 17. One page instead of five for most artists | **Corrected** (the sync now sends it) |
| same, unknown artist id (`00000000-0000-4000-8000-000000000000`) | 404 → `not_found` | `404 {"error":"Not Found",…}` | Confirmed (fixture, test) |
| same, a merged (redirected) artist id | handled somehow | no merged id at hand; a 404 would end as `not_found`, retried in 90 days | Not verified |
| MusicBrainz busy `503` | throws, the job backs off | seen once while sampling after about 20 calls spaced 1.2 s ("The MusicBrainz web server is currently busy"): it is server load, not only our rate. `MetadataHttp` throws, the job retries after 60, 300, 900 ... s, nothing is written | Confirmed (test) |
| MusicBrainz `GET /ws/2/artist?query=artist:"Radiohead"&limit=5` | `artists[].id/name/score` | exactly those; Radiohead scores 100, namesakes 57 and 53. Response carries `X-RateLimit-Limit: 400` | Confirmed |
| MusicBrainz `GET /ws/2/release?query=barcode:720642442517&limit=10` (Nirvana, *Nevermind*; the UPC I know from the DGC CD, which MusicBrainz lists as a **12" Vinyl** promo release, so the lookup read `vinyl`) | `artist-credit`, `label-info`, `media[].format`, `release-group`, `date`, `country` | all there as read. Search ignores the leading zero: `0720642442517` and `720642442517` find the same 11 releases; formats come as `12" Vinyl`, `Vinyl` | Confirmed |
| ListenBrainz `GET /1/explore/fresh-releases/?days=14&past=true&future=true&sort=release_date`, no token | works without a token; a list or `{payload:{releases}}` | HTTP 200 **without** a token; the body is `{payload:{releases:[…], total_count}}`. Fields: `artist_credit_name`, `artist_mbids`, `release_date`, `release_group_mbid`, `release_group_primary_type` (missing on ~1% of rows), **`release_group_secondary_type`** (only when present: Soundtrack, Live, Compilation, Remix ...), `release_mbid`, `release_name`, `release_tags`, `listen_count`, `caa_id`. Window: `release_date` ± days | Confirmed, **corrected** (secondary type is used; docs list a plain list) |
| same, size | 14 and 90 days | 14 days: 4,711 releases (2 MB; 2,106 albums, 611 EPs, 1,866 singles); 90 days: 33,165 releases (14 MB, about 75 MB of PHP arrays). Headers `X-RateLimit-Limit: 30`, `X-RateLimit-Remaining`, `X-RateLimit-Reset-In`, `Cache-Control: s-maxage=300` | **Corrected**: client default is now 30 days, the docblock says what it costs |
| Spotify `GET /v1/search?type=artist&q=artist:Radiohead&limit=10`, and `type=album,artist&q=Dirty Three&limit=10` | max limit 10 | 200, 10 items each (artist items still carry `popularity`, `followers`, `genres`); `limit=11` also answers 200 but returns 10 | Confirmed |
| Spotify `GET /v1/artists/{id}/albums?include_groups=album,single&limit=50` (Radiohead 45, Dirty Three 10) | may be closed on a post-February app | **works**: 200, `total`, `items`, `next=null`; items have `album_group`, `album_type`, `release_date`, `release_date_precision`, `artists`, and **`total_tracks`** (the design said the listing has no track count; the sanity check can now be built) | Confirmed, **corrected** |
| Spotify `GET /v1/artists/{id}`, `GET /v1/albums/{id}` | works | 200; the album carries `tracks`, `label`, `external_ids`, `popularity` | Confirmed |
| `SpotifyAvailability::lookupArtist` end to end (Foo Fighters, 11 stale rows) | answers per release-group | 6 `available` (confidence 90), 5 `unavailable`; two calls (artist search, artist albums) | Confirmed |

### The owner's library, dry run (dev database)

`php artisan tinker` in the dev container, the doc's one-liner: **221 of 629 artists with albums have an `mbid` (35%)**, 33 of 996 albums have a `release_group_mbid`; 0 catalogue rows before. The doc assumed 70% and about 400 artists.

`ArtistCatalogueSync` run for 5 artists (a counting client; 4 are in the table, the fifth is `unverified`):

| Artist | Library albums | mbid | Requests | Seconds | Release-groups stored | Linked to an owned album |
|---|---|---|---|---|---|---|
| Foo Fighters | 85 | yes | 1 | 0.3 | 19 | 8 |
| The Lonely Island | 2 | yes | 1 | 0.1 | 3 | 2 |
| The Script | 1 | yes | 1 | 0.1 | 12 | 0 |
| The Beatles | 13 | no (search, verified, saved) | 4 | 4.6 | 34 | 10 |
| JON VINYL | 1 | no | 2 | 1.3 | 0 (`unverified`) | 0 |

Seconds are request time only; the queue limiter spaces jobs 1/s. Of 68 stored groups 20 are linked (48 missing). A sample of 6 artists without an mbid through the search-and-verify path: 1 verified (Feist), 3 candidates correctly refused (the library holds only a compilation of theirs), 1 ambiguous, 1 none (a collaboration credit).

**Finding: the library's album names are releases, not albums.** Foo Fighters has 85 library albums such as `One By One Special Norwegian Edition (82876 512002)` or `Everlong UK CD1 (7243 8 84361 2 0)` (names with the catalogue number of the pressing). The linker (`Normalizer::album` equality) only ties the 8 that are plain titles, so *Five Songs and a Cover* is shown missing while `Five Songs And A Cover US Best Buy Exclusive EP (BMK 96143)` is owned, and artist verification (step 2) fails for an artist whose albums are all such names. Not fixed here: a prefix rule in the linker or the verification is a design choice (risk of false links), see the recommendations.

**Extrapolation.** 629 artists: 221 with an mbid at about 1.2 browse pages each (website-default) ≈ **265 requests**; 408 without: one search each plus a browse for the roughly 50-65% that have a single strong candidate (about 1.5 pages) ≈ **730**; total **about 1,000 requests ≈ 17-20 minutes of limiter time**, over 6-7 nights at `--limit=150`; the design said 400-600. Steady state: the 221 (plus the verified ones) monthly ≈ 300 requests/month. Missing albums: about 12 per synced artist in the sample (small, and biased by the Beatles) times 250-300 artists that will get a discography ≈ **3,000-3,600**, against the design's 2,400.

### Recommendations on the open questions

- **Q2 (coverage):** 35% with an mbid. Step 2 (search + verify) therefore does most of the work (408 artists) and the first sync needs about 1,000 requests, twice the estimate; run it once with `--limit=150` over a week. Many unverified artists are compilation-only and are correctly refused.
- **Q3 (queue):** keep `catalogue` on the `heavy` worker (as built). 17-20 minutes of work in total and a 1/s limiter shared with enrichment need no process of their own.
- **Q4 (wish needs an mbid):** keep it required for albums. The search-and-verify step gives most real artists an mbid (and the sync saves it), and wishing a *song* already works by title (`wanted_tracks`). Revisit after the first full sync: count artists left at `no_mbid`/`unverified` that have owned own-file albums.
- **Q6 (refresh policy):** keep 30/90 days. The ListenBrainz trigger is cheap (one call a day, 2 MB for 14 days, 5 MB for 30) and now ignores live and compilation rows, so new releases of an artist are found within a day without a faster policy. Do not raise `--days` over 30 on a Pi 1 GB: 90 days needs about 75 MB of PHP memory.
- **Q8 (singles/live/compilations toggle):** stays out. The raw browse is the same pages minus the unofficial ones; a resync for a toggle would cost the first-sync numbers above (about 300 requests for the artists with an mbid).
- **Linker/verification (new):** the library's release-style names cap the "owned" count. Decide with the owner whether the linker should accept `title (…catalogue number…)`, `title EU`, `title Japan` as editions of one album (strip a trailing parenthesised catalogue number and a region word in `Normalizer::album`, version-bumped), before the owned/missing numbers are shown to anyone.
- **Spotify total_tracks:** the artist-albums listing has it; the track-count sanity check of PR 8 (skipped as "not available") can be built.
