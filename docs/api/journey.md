# Journey: from one track to another

A queue that moves from a start track to an end track through the **sound space**, each step a little closer to the end and a little further from the start. Own files only (the sound embedding of [similar-tracks.md](similar-tracks.md)), **Full profile only** (`422 not_available_in_profile`). Open like the rest of the library API.

## How it is built

With the unit vectors `A` (start) and `B` (end) and a journey of `n` tracks, step `i` aims at `(1 - a) * A + a * B` with `a = i / (n - 1)`. A candidate's cosine with that target ranks like `(1 - a) * dot(c, A) + a * dot(c, B)`, so the server makes one pass over the candidates with two dot products each, whatever the length, and every step takes the best unused one, preferring another artist than the step before. Candidates are the analysed own files of the scope (and what `device_id` can play); the two ends are never candidates. Results are cached `similar.cache_seconds`.

It is a path through the library you have, not a promise: a small library gives a bumpy road. The length is `similar.journey_length` (12), 4 to 30.

## `GET /api/library/journey?from=&to=&length=&device_id=&scope=&client=`

```json
{ "data": [ SearchTrack + { "step": 1, "of": 12, "progress": 0, "reason": "The start of the journey" },
            SearchTrack + { "step": 2, "of": 12, "progress": 0.091, "reason": "Step 2 of 12 from A to B" },
            … ] }
```

Errors: `422 not_analysed` with `track: "from"|"to"` (that end has no embedding yet), `422 not_enough_tracks` (fewer than `length - 2` candidates), `422` for the same track twice or a length outside 4 to 30.

## `POST /api/devices/{id}/library/play-journey`

Body `{ "from_track_id", "to_track_id", "length"?, "scope"?, "client"? }`. Plays the journey once from the start track: `200 { status: "ok", from, to, queued }`. Errors: `422 not_analysed`, `422 not_playable` (an end cannot be played on this device, or too few tracks it can play), `422 unsupported`, `503`, `502`.

## MCP

`browse_library` kind `journey` (`id` = start, `to_id` = end, `length`) and `play_from_library` type `journey` (`id`, `to_id`, `length`).
