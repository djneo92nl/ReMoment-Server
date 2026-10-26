# Spotify Web API audit (February 2026 Development Mode changes)

Checked **2026-10-26** against Spotify's [migration guide](https://developer.spotify.com/documentation/web-api/tutorials/february-2026-migration-guide), the [February 2026 changelog](https://developer.spotify.com/documentation/web-api/references/changes/february-2026) and the [developer-access blog post](https://developer.spotify.com/blog/2026-02-06-update-on-developer-access-and-platform-security). Roadmap item: workstream 1 of `v1-roadmap.md`.

## What Spotify changed

- New Development Mode apps from 2026-02-11, existing ones from 2026-03-09: Spotify **Premium** (of the app owner) required, **5 authorized users** per Client ID, a reduced endpoint set. (The migration guide says the Client-ID-per-developer limit was raised to 25 in July 2026.)
- The blog post says the endpoint changes for **existing** apps were **postponed** (no new date); the Premium and 5-user rules were not. The migration guide itself does not mention the postponement. Treat every "removed" endpoint below as possibly gone for a user who created their app after 2026-02-11, and as gone for everyone once Spotify sets a date.
- Removed: bulk lookups (`GET /tracks|albums|artists…?ids=`), `GET /browse/*`, artist top tracks, `GET /users/{id}[/playlists]`, `GET /markets`, `/playlists/{id}/tracks` (→ `/items`, owner or collaborator only), the per-type save/remove/check and follow endpoints (→ `/me/library`, `/me/library/contains`, URI based, max 40 URIs).
- Changed: `GET /search` limit max 10 (was 50), default 5. Fields removed from objects: album `label`, `popularity`, `album_group`, `available_markets`; artist `followers`, `popularity`; track `popularity`, `available_markets`, `linked_from`; user `country`, `email`, `product`.

## Calls the server makes

SDK: `jwilsson/spotify-web-api-php` through `SpotifyTokenService::makeApiClient()`; the changed calls go through `App\Domain\Spotify\SpotifyLibraryApi` (plain HTTP, token from the same service).

| Endpoint | Method | Called from | Feature | Status | Handling / risk |
|---|---|---|---|---|---|
| `/me/player` | GET | `Spotify\Services\DeviceListener::poll`, `SettingsController`, `Health\ExternalServices` | Now playing, routing, modes | supported | none |
| `/me/player/devices` | GET | `LibraryPlayback::connectDeviceId`, `SettingsController` | Connect speaker lookup | supported | none |
| `/me/player` (transfer), `/me/player/play`, `pause`, `next`, `previous`, `seek`, `shuffle`, `repeat` | PUT/POST | `Spotify\MusicPlayerDriver`, `LibraryPlayback` | Transport, play library on a speaker | supported | none |
| `/me/player/queue` | GET / POST | `MusicPlayerDriver::getQueue / addToQueue` | Up next, add | supported | none |
| `/me/tracks` | PUT / DELETE | `MusicPlayerDriver::setLiked` | Like | **replaced** by `PUT/DELETE /me/library?uris=` | **Changed**: new endpoint first, old one only if the new answers 404/405/410. Needs `user-library-modify` (optional scope, already requested); the reference also lists `user-follow-modify`/`playlist-modify-public` for the generic call, unverified whether any one suffices: if likes get 403 after reconnect, add them to `OPTIONAL_SCOPES` |
| `/me/tracks/contains` | GET | `DeviceListener::likedFor` | Liked state in `modes` | **replaced** by `GET /me/library/contains?uris=` | **Changed**, same fallback; any failure still means `liked: null` |
| `/me/tracks` | GET | `SpotifyLibraryImporter::importSavedTracks` | Library sync of Liked Songs | supported (not on the removed list) | low |
| `/me/playlists` | GET | `importPlaylists` | Playlist sync | supported | low |
| `/playlists/{id}/tracks` | GET | `importPlaylistItem` | Playlist entries | **removed** → `/playlists/{id}/items`, owned/collaborative only | **Changed**: `/items` first (accepts an `item` or `track` key), old one on 404; a playlist Spotify won't list (403/404) is kept **without tracks**; a failing playlist is skipped and logged, the sync continues |
| `/tracks/{id}` | GET | `EnrichTrackSpotify`, `StorePlaybackHistory`, importer, `ImportSpotifyAlbum`, `LibraryPlayback` | Track metadata, album of a played track | supported (single) | `popularity`/`available_markets` gone from the response: every use is `isset`-guarded. `EnrichTrackSpotify` degrades 403/404/410 to "no data" (marked done, no retry loop) |
| `/albums/{id}`, `/albums/{id}/tracks` | GET | importer, `SpotifyCatalog` | Fill an album from Spotify | supported (single) | `label`/`popularity` not used |
| `/artists/{id}` | GET | `EnrichTrackSpotify` | Artist genres | supported (single) | `popularity`/`followers` gone: guarded with `isset` |
| `/artists?ids=` | GET | `FetchSpotifyArtistImages` (up to 50 ids) | Artist photos | **removed** (bulk) | **Changed**: on 400/403/404/410 falls back to one `/artists/{id}` per artist; other errors still fail the job (retry) |
| `/artists/{id}/albums` | GET | `SpotifyCatalog` | Artist's discography on Spotify | supported (not on the list) | low; unverified on a post-February app |
| `/search` | GET | `PlayHistory`, `PlayedTrackAdder`, `EnrichTrackSpotify`, `FetchSpotifyArtistImages`, `SpotifyCatalog`, importer | Matching names to Spotify ids | **changed** (limit ≤ 10) | OK: every call uses `limit` ≤ 10 (1, 5, 8, 10) |
| `/me` | GET | `ExternalServices` health probe | Connection check | supported (`product`, `country`, `email` fields gone) | only the call succeeding is checked |
| `/browse/*`, artist top tracks, `/users/{id}*`, `/markets` | – | never called | – | removed | none; do not add |

## Still at risk

- Everything unverified on a real post-February Development Mode app (no live call was made): the exact shape of `/playlists/{id}/items` entries, whether the generic `/me/library` calls accept the current scopes, and `/artists/{id}/albums`.
- Spotify's playlist rule is restrictive: a connected user's *followed* (not owned) playlists import as empty playlists (no tracks) on an app that gets the new rules. A smart/local copy is the only replacement.
- Field removals (`popularity`, genres are still on artists) reduce enrichment data; nothing errors.
- If Premium lapses on the app owner's account the API stops answering; the listener's backoff and the health page (`ExternalServices`) show it as an unreachable service, which is the honest failure.

## The 5-user limit: each install needs its own Spotify app

Development Mode allows 5 authorized users per Client ID (and Premium on the owner), so a public ReMoment cannot ship one shared Client ID; **every install creates its own Spotify app** (https://developer.spotify.com/dashboard, redirect URI `{APP_URL}/settings/spotify/callback` as built by `route('spotify.callback')`), and the one admin connects their own account.

Covered since the follow-up: **Settings › Spotify** has a form for the app's Client ID and secret (with the redirect URI to register and the Premium/5-user note), and the setup steps are in [spotify-app-setup.md](spotify-app-setup.md). `.env` credentials still work as a fallback.

## Tests

`tests/Feature/SpotifyApiAuditTest.php` (Http::fake): generic like/unlike and contains with fallback, rejected save throws, playlist `/items` with fallback and 403/404 degradation, import continuing past a hidden playlist, single-artist fallback, enrichment degrading on 403.
