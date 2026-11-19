# Kept stems of a track (for a mixer)

The stems of one track (vocals, drums, bass, other) as four audio files an app can mix, kept on the server after someone asked for them. Playback of the mix is done by the app (web: the `/mixer` page, [../frontend/stem-mixer.md](../frontend/stem-mixer.md); iOS: AVAudioEngine, not built); the server only separates, stores and serves. Design and numbers: [../architecture/stems-service.md](../architecture/stems-service.md), "Kept stem audio".

No admin token anywhere: the endpoints are open like the library, and the download URL is signed and temporary. Full profile only (`422 not_available_in_profile` otherwise). Needs a track with an own file on this server.

## Four or six stems

The `stems` object has the stems the track was separated into: **vocals, drums, bass and other**, or **six** (also `guitar` and `piano`, in the order vocals, drums, bass, guitar, piano, other) when the stems service runs `STEMS_MODEL=htdemucs_6s`. The model is the server owner's choice (opt-in, see [../architecture/stems-service.md](../architecture/stems-service.md)); a track keeps the stems it was separated with, so a library can hold both. Clients must use the keys of the answer and not assume four. To get six for a track that has four, delete kind `stem_audio` (`DELETE /api/library/tracks/{id}/deep-analysis/stem_audio`) and ask for it again.

## Ask for the files

```
POST /api/library/tracks/{id}/deep-analysis   { "kinds": ["stem_audio"] }
GET  /api/library/tracks/{id}/deep-analysis   → kinds.stem_audio.state: none | queued | running | done | failed
DELETE /api/library/tracks/{id}/deep-analysis/stem_audio   → files and manifest gone (the loudness lanes stay)
```

Same flow, states and errors as the other deep-analysis kinds ([deep-analysis.md](deep-analysis.md)); `features.deep_analysis_kinds` of `GET /api/info` contains `stem_audio` while the stems service can run it. The job takes about `0.55 s` per second of audio on a fast machine (`estimate.stem_audio_seconds`), several times more on a small PC. One run also stores the `stems` lanes.

## List the files

```
GET /api/library/tracks/{id}/stems
```

```json
{
  "track_id": 42,
  "format": "mp3",
  "mime": "audio/mpeg",
  "bitrate": 192,
  "sample_rate": 44100,
  "channels": 2,
  "duration": 241.3,
  "bytes": 6012345,
  "expires_in": 3600,
  "bpm": 124.4,
  "key": "A",
  "mode": "minor",
  "camelot": "8A",
  "stems": {
    "vocals": { "url": "/api/library/tracks/42/stems/vocals?expires=…&signature=…", "bytes": 1503086 },
    "drums":  { "url": "…", "bytes": 1503086 },
    "bass":   { "url": "…", "bytes": 1503086 },
    "other":  { "url": "…", "bytes": 1503086 }
  }
}
```

- The files are **stereo, 44.1 kHz, as long as the track and start together**; an app lines them up at 0 and plays them with one gain per stem. Their sum is the track, not bit-exact (separation is not lossless).
- `format` is `mp3` (default, `STEM_AUDIO_FORMAT`) or `flac`. MP3 carries its encoder delay in the LAME header; use a decoder that honours it (browsers and AVFoundation do) or set the server to FLAC (`STEM_AUDIO_FORMAT`) when sample-exact alignment matters. `bitrate` is `null` for FLAC.
- `bpm`, `key`, `mode`, `camelot` come from the track's analysis (`null` when it has none); with `GET /api/library/tracks/{id}/mixes-with` they pick a pair. There is **no beat grid**: bpm and key are enough to choose and roughly align tracks, not to beat-match them.
- Each `url` is a path: put the server address in front. It is valid for `expires_in` seconds (`STEM_AUDIO_URL_TTL`, default 3600); list again for fresh ones.

Errors: `404 not_ready` (not kept; `state` says none, queued, running or failed), `404 files_missing` (the manifest says done but a file is gone from the disk: `DELETE` the kind and ask again), `422 no_file`.

## Download

```
GET /api/library/tracks/{id}/stems/{vocals|drums|bass|guitar|piano|other}?expires=…&signature=…
```

No token header: the signature is the authorization. The file with its `Content-Type` (`audio/mpeg` or `audio/flac`); `Range` requests are answered (`206`). `403` for a missing, altered or expired signature, `404` when the stem is not kept any more (removed, or the file changed).

## Storage and cleanup

`stems/{file id}/{stem}.{mp3|flac}` on the disk `STEM_AUDIO_DISK` (`local` = `storage/app/private`), not part of any library storage location. About 6 MB per 4-minute track as MP3, 80-120 MB as FLAC. Nothing keeps stems by itself: they exist only for tracks someone asked for, until `DELETE …/deep-analysis/stem_audio`, a change of the file (forgotten; `stems:prune` removes the leftovers daily) or removal of the file. MCP: tool `deep_analysis` (`request` with `kinds: ["stem_audio"]`, `stems` to list). Licence note: the htdemucs weights have no stated licence; the files are served to the household's own apps only.
