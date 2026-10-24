# Radio: station-level now playing, NTS

## Station-level now playing
`StreamTitles` keeps, per station and independent of any device, the title (`radio:title:{id}`) and where it came from (`radio:source:{id}`: `icy`, `nts`). One `PollRadioTitle` chain per station (every 15 s) runs while something keeps calling `StreamTitles::touch()`: a device playing the station, `GET /api/radio/{id}/now`, or the `RadioNowPlaying` Livewire card on `/radio` (first 12 stations, polling every 30 s). The chain ends 90 s after the last touch, so nothing polls in the background.

`App\Domain\Radio\NowPlayingProviders` asks `NowPlayingProvider`s in order (`NtsProvider`, `IcyProvider`); the first that supports the station and has a title wins, a failing provider is skipped. Add a source by implementing the interface and listing it in `PROVIDERS`. `StationNowPlaying::for($station)` reads the cache only (API `now`, web card).

## NTS (`app/Integrations/Radio/Nts/`)
**Opt-in, off by default** (setting `radio_nts_enabled`, `RadioSettings::ntsEnabled()`, checkbox with a disclaimer under Settings › Files & Import › Library). NTS has no public API and its terms (only a Supporter page) say nothing on third-party use, so we do not assume permission. Off: `NtsClient` returns no data and makes no request, `NtsProvider` is skipped, `radio:import-nts`, the import button and `/radio/nts` refuse (404), the health probe is gone, and NTS stations that were imported stay in the database but are hidden from `/radio`. Plain stream playback through the API is unchanged.

- `NtsClient`: the unofficial `https://www.nts.live/api/v2/live` (cached 60 s) and `/mixtapes` (1 h). Titles carry HTML entities, decoded. Any failure is "no data".
- Live channel streams are not in the API: `https://stream-relay-geo.ntslive.net/stream` (1) and `/stream2` (2), which redirect to the current CDN. Mixtape streams are `audio_stream_endpoint`.
- `NtsImporter` (`php artisan radio:import-nts`, or the button on `/radio`): stations found by meta `nts_id` (`live-1`, `mixtape-poolside`), so re-running refreshes the stream URLs. Live channels also get `nts_channel`, which makes `NtsProvider` return the show on air (a show title, not a track). Mixtapes have no live title; ICY is tried.
- Health probe `nts` on `/settings/health`.

## Radio play log
`PollRadioTitle` writes a `radio_plays` row (`RadioPlayLog::record`) when an ICY title changes, for the station whether or not a device plays it; show titles (NTS) are not songs and not logged. `/radio/{station}` lists the last 50 with a library link (`LibraryIdentity::lookupTrack`) or a Spotify search. Pruned after 90 days (daily). Peek-only titles are never scrobbled: Last.fm still comes only from a device's `NowPlayingUpdated`.

## Discovery (`RadioBrowser`)
Servers come from DNS (`all.api.radio-browser.info`, cached a day) with a fixed fallback list, random order. `search`, `browse(mode, tag, country)` (`trending` = clicktrend, `votes`, `popular`), `tags()`, `byUuid()`, `click()`. The add page has Discover buttons and genre chips. A station added from a result keeps meta `radio_browser_uuid`; `markPlayed()` queues `ReportRadioClick` (one click call per listen). `radio:refresh` (weekly) checks every stream (`StreamChecker`): dead ones get meta `stream_status=dead`, moved ones are looked up by uuid.

## Suggestions (`RadioSuggestions`)
- `similarToFavourites()`: trending stations in the two most common genres of the favourite stations, minus those already in the library (by url or directory uuid). Button "For you" on the add page.
- `yourArtistsOnTheRadio()`: the 60 artists you play most, matched (normalised names) against the last 30 days of `radio_plays`; shown as "Your artists on the radio" chips on `/radio`, each linking to the station page.

## NTS episodes (`/radio/nts`, `Livewire\NtsBrowser`)
Shows (24 per page) → episodes → official tracklist. `NtsClient::shows/episodes/tracklist` (cached 1 h). A tracklist row has `offset` (seconds into the show; NTS' own estimate when it has no exact one) and no artist for "Unknown Artist"; tracks the library has link to their album. Archives live on Mixcloud/SoundCloud, so they are links, not playable here. Tracklists appear some hours after the broadcast and not for every show.
