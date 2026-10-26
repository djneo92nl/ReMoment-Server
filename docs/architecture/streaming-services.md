# Streaming services: Deezer and Tidal (design)

Status: **plan, nothing built.** Deezer and Tidal are not like Spotify. Spotify is one cloud account the server controls from anywhere (Web API + Connect). Deezer and Tidal play **on a device that holds the account itself**, so support is per device and per platform. This document says what that means for the library, the drivers and the API, and what has to be verified on hardware first.

## What exists today

| Where | What |
|---|---|
| `Ase\Connectors\ContentControls` | `playDeezerTrack(int $id)` and `playDeezerPlaylist(int $id)`: `POST BeoZone/Zone/PlayQueue[?instantplay]` with a `deezer` id. Nothing calls them. |
| Mozart spec (`mozart-api-6.2.0.44.yaml`) | `POST /api/v1/playback/deezer/flow` (optional `deezerUserId`); queue item types `deezer`, `tidal`; source types `deezer`, `tidal`, `tidalConnect`. `PlaybackApi` documents the same. |
| `ase-api-map.md` | Deezer is a built-in content profile (`BeoContent/music/deezerProfile/…`: charts, genres, albums) whether or not an account is set up, so its presence says nothing about a login. M5 has `tunein_account` instead of deezer credentials. |
| Play history, scrobbling | `deezer`, `tidal`, `qobuz` are known streaming source types (`StorePlaybackHistory`, `ScrobbleSources`, `DeviceCard`). Per-source Last.fm switch already covers them. |
| Library | `LibrarySources::LIBRARIES` and the docs already reserve `deezer` and `tidal` as libraries (kind `streaming`); `playableOn()` is the per-device check. |
| `MusicBrainzClient` | Artist/album links to deezer.com and tidal.com are parsed into metadata. |

## Why it is not Spotify

| | Spotify | Deezer | Tidal |
|---|---|---|---|
| Server controls playback | yes (Web API, Connect device) | **only by handing an id to a device that has the account** | Tidal Connect: no (the app picks the speaker); Mozart `tidal` queue item: unverified |
| Account lives | in the server (OAuth) | on the speaker | on the speaker / in the Tidal app |
| Works on | any Connect speaker (and, with librespot, any speaker) | B&O ASE, Mozart | Mozart (`tidal`, `tidalConnect`); other brands via Tidal Connect |
| Re-streaming to other speakers | possible (`spotify-stream.md`) | **no**, not without breaking the terms | **no** |
| Server can read the catalogue | yes (limited since Feb 2026) | public API, metadata and previews only (terms to re-check) | official API, metadata and playlists; playback only through Tidal's own SDKs |

Consequences:

1. These are **per-device capabilities**, not global libraries that every speaker can play.
2. The server never holds an audio stream for them, so no Icecast route and no `stream_url` fallback.
3. Metadata (browse, search, id mapping) comes from a catalogue client on the server; playback is a handoff of an id.

## Design

### Library entry and per-device playability

`deezer` and later `tidal` become entries in `LibrarySources::LIBRARIES`, kind `streaming`, with a `device` check answering "can this device play this library", exactly like DLNA. `GET /api/library/libraries?device_id=` then reports `playable` per device, and the web and API play buttons stay hidden or `422 not_playable` for devices without it.

A device capability is needed so clients can ask without a library call:

| Capability | Meaning | Reported when |
|---|---|---|
| `deezer_playback` | The device can start a Deezer track/album/playlist from an id | ASE and Mozart models verified to accept it (see "Verify first"). Never from the driver class alone |
| `tidal_playback` | Same for Tidal (Mozart `tidal` queue item) | only if the probe shows it works |

Add them to `Capabilities::MAP` and `DeviceCapabilities::for()`, the OpenAPI `capabilities` enum and the CLAUDE.md capability list.

### Catalogue and id mapping

A `Deezer` catalogue client under `app/Services/` (like `MusicBrainzClient`): search by artist + album, track by album + title and duration, album tracks, playlist tracks. Responses cached, rate limits respected, failures never break a page.

Ids are stored as `Metadata` with the service as source, as `spotify_album_uri` is: `deezer_album_id`, `deezer_track_id`, `deezer_artist_id` (and `tidal_*` later). Sources of an id, in order of trust:

1. A link already known from MusicBrainz (`deezer.com/album/{id}`, already parsed).
2. The id a speaker reports in its own now playing while the album plays, when the driver exposes it.
3. A catalogue search with a conservative match (normalized name key via `Normalizer`, artist match, track count and duration within tolerance). Low-confidence matches are not stored.

An album played from the library on a Deezer-capable device then needs only the stored id. An album without one is looked up once, then stored.

### Driver contract

A new contract in `app/Integrations/Contracts/`, in `DELEGATED_CONTRACTS` like the queue ones:

```php
interface PlaysStreamingItemsInterface   // name to settle; Spotify has PlaysSpotifyItemsInterface
{
    public function playStreamingItem(string $service, string $type, string $id, bool $instant = true): void;
    public function supportedServices(): array;   // ['deezer'], ['deezer','tidal'], ...
}
```

- ASE: `deezer` track and playlist through the existing connector methods. Album support depends on whether `PlayQueue` accepts an album item (unverified); without it an album plays as its track list in order (`QueueTracks` style), which loses gapless guarantees.
- Mozart: `POST /api/v1/playback/deezer/flow` is the user's Flow, not a library play. Track/album/playlist go through the play queue with a `deezer` or `tidal` item type.
- Sonos, UPnP: not supported (their Deezer/Tidal support is app-side).

`LibraryPlayback` gets a third path after DLNA and Spotify: the item is playable when the device lists the service in `supportedServices()` and the album, track or playlist has a stored id. Errors follow the existing set (`422 not_playable`, `503`, `502 driver_error`).

### Playback reporting

Already works in part: a Deezer track played from the speaker's own app arrives as `NowPlaying` with `source` type `deezer`, is stored in `plays`, scrobbled unless switched off, and gets artwork. Check per driver that the **service track id** is kept in `TrackData::meta` so history can attach it to the library track (and store the id mapping for free, source 2 above).

### Tidal Connect

Assume it behaves like Spotify Connect: the user picks the speaker in the Tidal app and the server cannot start it. Scope:

- **Read-only now playing, history and scrobbles** through the device's own listener (Mozart reports a `tidalConnect` source).
- Transport (play, pause, next, volume, seek) where the device driver already supports them for that source.
- No library "play on Tidal". If the Mozart `tidal` queue item turns out to work with a linked account, add it under `tidal_playback`; otherwise stay read-only and say so in the UI.
- No Tidal catalogue in the server for 1.0. The official API gives metadata and playlists, and its OAuth is not worth it before a playback path exists. Unofficial libraries that use extracted app tokens are out of scope.

## Verify first (read-only, on the device lab)

The design is only as good as these answers. All GET or read-only except the one play test, which is a deliberate single track.

1. Run `ase:map` on each ASE device: is there a Deezer profile with an account, or only the built-in profile?
2. Does `PlayQueue` with a `deezer` track id actually play on an ASE device with an account, and without one (error shape)? Does an album item exist in the queue schema?
3. Mozart: does the `deezer` queue item type play a track, album, playlist? Same for `tidal`, with and without a Tidal account on the speaker.
4. What does a Tidal Connect session report (source, track id, artwork, position)? Does the listener see it?
5. Which ids do speakers report in now playing for Deezer and Tidal tracks (to harvest mappings)?
6. Deezer public API: current terms, rate limits, and whether the catalogue calls need a registered app.

Record the results in this document (per model) before building; the capability must come from a verified model list or a live check, never from the brand.

## Build order

1. Probe (above); write the results here.
2. Deezer catalogue client + id metadata + harvest of ids from plays.
3. `deezer` library entry, `deezer_playback` capability, contract, ASE and Mozart implementations, `LibraryPlayback` path.
4. REST/OpenAPI/MCP: capability and `playable` flags need no new endpoints; add to `docs/api/library.md` and the MCP table if tools change.
5. Web: Deezer play buttons on album/artist pages on capable devices; "playable on" labels.
6. Tidal: read-only reporting; playback only if verified.
7. Tests: a `Fake` driver implementing the contract, id-mapping unit tests (match thresholds, low-confidence skip), `LibraryPlayback` path and `422 not_playable` tests.

## Not in scope

- Re-streaming Deezer or Tidal audio to other speakers (terms; no open stream).
- Qobuz (same pattern: another `streaming` library and `*_playback` capability once a device path is known).
- Importing the user's Deezer/Tidal library or playlists. That needs account access the server does not hold; see the availability layer below.

## Relation to the library design

How external libraries add to the collection (albums you lack, new releases, "streamable on X") is a separate decision: an availability layer over `LibraryIdentity`, where an album can be owned, streamable on a service or device, or only known. Deezer and Tidal slot in as availability providers with per-device `playable`; Spotify moves to the same model. Settle that before building step 5.
