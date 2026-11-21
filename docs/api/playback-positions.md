# Resume position (audiobooks, long tracks, podcast episodes)

Workstream 8.3. The server keeps a **bookmark** for a long track or a podcast episode, so playing it again continues where it stopped. Household-wide (there are no accounts) and open like playback itself: no admin token. Standard and Full profiles (the routes sit under the `library` gate).

## What gets a bookmark

A track keeps a bookmark when it is at least `resume.min_minutes` long (config `config/resume.php`, env `RESUME_MIN_MINUTES`, default 20) **or** one of its genres is an audiobook or podcast genre (`resume.genres`: `audiobook`, `podcast`, `spoken word`, `hörbuch`, `luisterboek` …). A podcast episode always has one ([podcasts.md](podcasts.md)). Songs never do. Code: `App\Domain\Resume\PlaybackPositions`.

## How it is kept

`App\Listeners\Device\RecordPlaybackPosition` listens to `ProgressUpdated` of every device:

- the item behind the now-playing is found once per track and remembered (`LibraryIdentity::lookupTrack`, or the episode the server started), so a progress report costs a cache read;
- the position is written at most every `resume.save_interval` seconds (10) and **not in the first `resume.min_seconds`** (10), so playing from the start can never overwrite a bookmark before it is restored;
- within `resume.tail_seconds` (30) of the end the item is **finished** and its bookmark is cleared; so is a stop (`NowPlayingEnded`) inside that tail;
- progress is ignored while a resume seek is still due on that device.

An app that follows playback itself can set the position with the API below.

## How playing resumes

When the server starts a bookmarked track on a device (`play`, `play-album`, `play-artist`, a queue of tracks, the web UI's play buttons, MCP `play_from_library`), it queues `App\Jobs\ResumePlayback` a few seconds later. The job waits until the device reports that item as now playing, then calls `seek` with the bookmark (up to five tries, three seconds apart; after that the item plays from the start). Only devices with the `seek` capability resume; others play from the start, silently.

- `play-album` without `start_track_id` and without `shuffle` starts at the album's **bookmarked track** (an audiobook continues at the chapter you were in), then seeks into it.
- `resume=false` in the body of any play request (REST or MCP) starts from the beginning. `resume` is read from the request, so every play path shares it. The bookmark itself stays until the new listening passes the first seconds.
- The play response is unchanged (it does not carry the resume position); read `GET /api/library/positions`.

## Endpoints

```
GET    /api/library/positions?limit=30          most recently kept first: continue listening
PUT    /api/library/positions/{kind}/{id}       { position, duration? }   set by hand; kind = track | episode
DELETE /api/library/positions/{kind}/{id}       forget it
```

```json
{ "data": [ { "kind": "track", "id": 812, "position": 1800, "duration": 3600, "percent": 50,
              "updated_at": "2026-11-23T20:14:02+01:00", "title": "Chapter 3",
              "subtitle": "Rob Inglis · The Hobbit", "album_id": 77, "artwork": { "proxy_120": "…", "proxy_320": "…" } } ] }
```

`PUT` answers `{status: "ok", kind, id, position, cleared}`: a position of 0, or one inside the end tail of the item (`duration` defaults to the item's own), is a finish and clears the bookmark (`cleared: true`). `404` for an unknown track or episode, `422` for a negative position. A bookmark whose item is gone is kept but not listed.

Table `playback_positions` (`kind`, `item_id`, `position`, `duration`, `device_id`; unique on kind + item; no foreign keys, so a bookmark never blocks removing an item).

## MCP

`continue_listening` (`list`, `set`, `clear`), see [mcp.md](mcp.md).

## Unverified on hardware

The seek after the start is tested with fake drivers only. How soon each real renderer accepts a seek after `play` (UPnP renderers, ASE, Sonos) is not measured; the retry (5 × 3 s) is the guess. A renderer that reports no title for the item cannot be matched by the job and plays from the start.
