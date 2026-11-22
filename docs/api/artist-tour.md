# Artist tour

A guided way through one artist's tracks in the library. Plays and release dates only, so it works in **Standard and Full**; open like the rest of the library API.

## What the tour is

1. The artist's **three most played** songs (those with a play), so you start on ground you know.
2. Then **one song of each album, oldest album first**: the song of that album you have played the least (never played first), and round again until the tour has `discover.tour_length` tracks (15; `length` asks for 4 to 60). Albums without a release date come last; a track without an album counts as its own album.

Each item has a `reason`: "One of your most played by Band (9 plays)", "Never played, from Early (1999)", "A less played song from Late (2015)". Fewer than four tracks make no tour.

## `GET /api/library/artists/{id}/tour?length=&device_id=&scope=&client=`

```json
{ "canonical_id": 7, "data": [ SearchTrack + { "step": 1, "reason": "One of your most played by Band (9 plays)" }, … ] }
```

`422 not_enough_tracks`; `404` for an unknown artist.

## `POST /api/devices/{id}/library/play-artist-tour`

Body `{ "artist_id", "length"?, "scope"?, "client"? }`. Plays the tour once, only what the device can play: `200 { status: "ok", artist, queued }`; `422 not_playable` (fewer than four playable tracks), `422 unsupported`.

## MCP

`browse_library` kind `artist-tour` (`id` = artist) and `play_from_library` type `artist_tour` (`id`, `length`).
