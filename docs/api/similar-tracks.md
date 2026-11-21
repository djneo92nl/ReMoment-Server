# Similar tracks

Tracks that **sound** like a given track, and a radio started from it. Own files only: the sound embedding comes from the audio analysis with the ML sidecar (`ANALYSIS_ESSENTIA_ML`), so DLNA and Spotify tracks never have one and never appear. **Full profile only** (`422 not_available_in_profile`, feature `analysis`, in Control and Standard). Typed in `openapi.yaml` (`listSimilarTracks`, `playSimilarTracks`); code `App\Domain\Library\SimilarTracks`; how it works, storage and measured query times: [../architecture/similar-tracks.md](../architecture/similar-tracks.md).

`features.similar_tracks: true` in `GET /api/info` says at least one own file has an embedding (the key is absent otherwise, treat as false). A single track can still lack one until its file is analysed again: `422 not_analysed`.

## `GET /api/library/tracks/{id}/similar`

`?cursor=&scope=&client=&device_id=`, 50 per page, at most 200 deep (`similar.max_results`). `scope` and `client` hide libraries as on the other lists, `device_id` keeps to tracks that device can play (`422` for an unknown device).

```
GET /api/library/tracks/42/similar?device_id=3
→ {
    "canonical_id": 42,
    "source": { "model": "discogs-effnet-bs64-1", "dimensions": 1280 },
    "data": [ SearchTrack + {
      "similarity": 0.912, "distance": 0.088,
      "reason": { "moods": ["relaxed"], "genres": ["Jazz"], "tempo_diff_percent": 3.0, "same_artist": true }
    } ],
    "next_cursor": "…"
  }
```

- **Order:** `similarity` descending (cosine similarity of the two embeddings; `distance` is `1 - similarity`), then track id, so paging is stable. It is a ranking, not a probability: for real music the nearest tracks usually sit well above 0.7, but a client should show it as "most alike first" rather than a percentage of anything.
- **Never listed:** the track itself, the same AcoustID recording, and a track of the same artist with the same normalised title (the same song on another album); tracks whose file has no current embedding (not analysed with ML, or the file changed since).
- **`reason`** is what the analysis says the two also share: mood tags and classifier genre tags both have (lists of names, possibly empty), the tempo difference as a percentage of this track's tempo (`null` when either has none) and whether the artist is the same. It explains a result; it does not rank it.
- Results are cached for five minutes per track, scope and device, and dropped when any embedding is written.
- `404` unknown track (ids merged by `library:merge-duplicates` resolve to the survivor, `canonical_id`), `422 not_analysed` while the track has no embedding.

## `POST /api/devices/{id}/library/play-similar`

A similar tracks radio: body `{ "track_id": 42, "scope"?, "client"? }`. Plays the track first, then up to 49 tracks in the order of the list above that the device can play, **once** (no refill, not a playlist), through the same library playback as `play-album`.

```
POST /api/devices/3/library/play-similar { "track_id": 42 }
→ { "status": "ok", "seed": 42, "queued": 50 }
```

Errors: `422 not_analysed` (no embedding), `422 not_playable` (the track itself cannot be played on this device, or fewer than 5 similar tracks can), `422 unsupported` (no library playback), `503` unreachable, `502 driver_error`, a validation `422` for a missing or unknown `track_id`. Also a scene step (`playSimilarTracks`) and `play_from_library` type `similar` in MCP.

## MCP

`browse_library` kind `similar` with `id` = the track id (pages with `cursor`, takes `scope` and `device_id`), the REST answer as is. `play_from_library` type `similar` with `device_id` and `id` starts the radio.

## Operating it

- The nightly `library:analyze` re-runs the `essentia` stage of files analysed before embeddings existed (stage version `2+ml`); how far it is shows on `/settings/analysis`. `features.similar_tracks` turns true when the first embedding is stored.
- `SIMILAR_ENGINE=sqlite-vec` (with `SIMILAR_VEC_EXTENSION`, `php artisan similar:index`) is the optional fast path for large libraries; the answers are the same. See the architecture page.
