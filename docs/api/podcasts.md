# Podcasts

Workstream 8.2. The household subscribes to podcasts by RSS address; the server keeps their episodes (rows only, **no audio is downloaded or relayed**) and plays one on a device by handing the publisher's audio URL to the speaker, through the same `stream_url` path as radio, the Spotify stream and the line-in. Where an episode stopped is kept by [resume position](playback-positions.md) (kind `episode`) and restored when it is played again. Standard and Full profiles (gate `podcasts`; Control answers `422 not_available_in_profile`); `features.podcasts` in `GET /api/info` is `true` there. Typed in `openapi.yaml` (`Podcast`, `PodcastEpisode`, `PodcastEpisodePage`). Code: `App\Domain\Podcasts`.

Reading, playing and marking played are **open** like playback itself (household-wide, there are no accounts, "played" is the household's). Adding, changing, refreshing and removing a subscription need the **admin token**.

## Endpoints

```
GET    /api/podcasts                                    subscriptions A to Z, with unplayed counts
GET    /api/podcasts/{id}
GET    /api/podcasts/{id}/episodes                      ?unplayed=1&limit=50&cursor=   newest first
GET    /api/podcast-episodes                            same filters, every podcast: unplayed=1 is "new episodes"
GET    /api/podcast-episodes/{id}                       one episode with `position` (where it stopped)
PUT    /api/podcast-episodes/{id}/played                { played: true|false }   marking played clears its bookmark
POST   /api/podcasts/{id}/mark-played                   every episode; → { status, changed }
POST   /api/devices/{id}/podcast-episodes/{id}/play     { resume?: false }   capability stream_url

POST   /api/admin/podcasts                              { feed_url }                  ADMIN: subscribe (fetches the feed now)
PUT    /api/admin/podcasts/{id}                         { keep_episodes: N|0|null }   ADMIN: retention
DELETE /api/admin/podcasts/{id}                         ADMIN: removes the episodes and their bookmarks
POST   /api/admin/podcasts/{id}/refresh                 ADMIN: check now → { status: updated|unchanged|failed, data }
```

```json
{ "data": { "id": 3, "title": "The Show", "author": "Jane Host", "description": "…", "site_url": "https://show.example",
            "feed_url": "https://show.example/feed.xml", "language": "en",
            "artwork": { "proxy_120": "…", "proxy_320": "…", "proxy_512": "…", "colors": ["#…", "#…"] },
            "episode_count": 100, "unplayed_count": 4, "latest_episode_at": "2026-11-20T08:00:00+00:00",
            "keep_episodes": null, "last_checked_at": "2026-11-21T10:00:00+01:00", "last_error": null } }
```

```json
{ "data": [ { "id": 812, "podcast_id": 3, "podcast_title": "The Show", "title": "Episode 5", "description": "Plain text",
              "published_at": "2026-11-20T08:00:00+00:00", "duration": 3723, "season": null, "number": 5,
              "played": false, "played_at": null, "position": 1200,
              "enclosure": { "url": "https://cdn.example/5.mp3", "type": "audio/mpeg", "size": 51234567 },
              "artwork": { "proxy_320": "…" } } ],
  "next_cursor": "…"|null }
```

`artwork` is the feed's image through the artwork pipeline (never the publisher's URL); it is `null` until processed (the first request queues it). `duration` is what the feed says (itunes:duration), `null` when it says nothing. The cursor is opaque (keyset on `(published_at, id)`); a bad one is `422`.

### Subscribing

`POST /api/admin/podcasts` reads the address (`http`/`https`; `feed://` and `itpc://` are read as http), **fetches the feed once now** and stores it with its first episodes, so a wrong address is refused at once: `422 invalid_feed` (unreachable, HTTP error, larger than 10 MB, not RSS, no title) and nothing is stored. A feed that is already subscribed is `409 already_subscribed` with the existing podcast in `data`. Success is `201`. Only RSS 2.0 is read (Atom feeds are not); items without an audio enclosure are not episodes.

## Playing an episode

`POST /api/devices/{id}/podcast-episodes/{episode}/play` calls `playStreamUrl(enclosure url, episode title, cover)` of the device's driver (resolved through `DeviceRouting`, like radio). The speaker fetches the audio from the publisher itself, so the server carries no traffic.

- `422 unsupported` when the device lacks the `stream_url` capability, `503 unreachable`, `502 driver_error`, `404` for an unknown episode.
- **Resume.** The play response is `{ status, episode_id, title, resume_position }`. When the episode has a bookmark and the device can `seek`, a `ResumePlayback` job seeks to it a few seconds after the start (`resume_position` says where; `null` = from the start). `resume=false` in the body starts from the beginning. The episode is remembered as what plays on the device, so the progress the device reports keeps its bookmark (every 10 s; nothing in the first 10 s), exactly as for a long track ([playback-positions.md](playback-positions.md)). The device's now-playing title must be the episode title for the seek to match it.
- **Played.** Playing an episode to within 30 s of its end (or stopping there) clears the bookmark and marks the episode played. `PUT /api/library/positions/episode/{id}` with a position at the end does the same.
- The enclosure URL goes to the device as it is. A renderer that cannot do **HTTPS** or follow the publisher's redirects (many podcast hosts redirect through trackers) cannot play it; the server's proxy for such a case is **not built** (see Left out).

## How feeds are kept up to date

`podcasts:refresh` runs every 30 minutes (scheduled only where the profile has `podcasts`) and queues a `RefreshPodcast` job per feed that is **due**, at most `batch` (20) per run and `spacing_seconds` (3) apart, so a long list never hits the publishers at once. Each job:

- sends a **conditional GET** (`If-None-Match` with the stored ETag, `If-Modified-Since` with Last-Modified); a `304` only records the check;
- keeps feeds **at least `PODCASTS_REFRESH_MINUTES` (120)** apart; a manual `POST …/refresh` is `429 too_soon` within 5 minutes of the last check;
- **backs off** while a feed fails: the interval doubles per failure up to 24 hours, the reason is `last_error` (listed on the podcast), the episodes it had stay; `Retry-After` on a `429`/`503` is honoured; a good fetch resets it;
- updates the podcast's title, author, description and image from the feed, adds new episodes (matched by the feed's `guid`, else the enclosure URL) and updates changed ones. **Played state and bookmarks are never touched by a feed edit.** An episode without a date counts from when it was first seen.

Command: `php artisan podcasts:refresh [--force] [--podcast=ID] [--now]` (`--force` ignores the interval and back-off, `--now` fetches here instead of queueing).

## Retention

Only rows are kept. Per podcast the **newest N episodes** stay (`keep_episodes`; `null` = `PODCASTS_KEEP_EPISODES`, default 100; `0` = all). Older ones are removed after each refresh and at once when `keep_episodes` is lowered, **except an episode with a bookmark**. The feed's own older episodes are not stored again (the newest N of the feed are all that is written), so pruned ones do not return on the next refresh. Unsubscribing removes the episodes and their bookmarks.

## MCP

`podcasts` (`list`, `episodes`, `play`, `mark_played`, `mark_all_played` open; `subscribe`, `refresh`, `keep`, `unsubscribe` ADMIN, enforced by the admin routes behind it), see [mcp.md](mcp.md). `continue_listening` lists and sets episode bookmarks too.

## Tables

`podcasts` (`feed_hash` unique sha1 of the address, `feed_url`, title, author, description, site_url, image_url, language, `keep_episodes`, `etag`, `last_modified`, `last_checked_at`, `last_success_at`, `next_check_at`, `failures`, `last_error`) and `podcast_episodes` (`podcast_id` cascade, `guid_hash` + `guid` unique per podcast, title, description, `enclosure_url`, mime, size, duration, image_url, season, number, `published_at`, `played_at`). The bookmark is in `playback_positions` (kind `episode`, no foreign key).

## Left out, unverified

- **Not built:** a server-side proxy for enclosures a renderer cannot fetch (HTTPS or redirects); downloading episodes; OPML import; Atom feeds; chapters; per-podcast "new episode" notifications; an artwork/MQTT/Last.fm path for podcasts (the now-playing of a stream URL is the episode title as the renderer reports it); the queue (an episode plays alone, it is not added to a device queue).
- **Unverified on hardware:** playing an enclosure on real renderers (UPnP, Sonos, ASE) and the seek timing after the start; the UPnP driver announces `audio/mpeg` for every stream URL whatever the enclosure type is. Tests use fake drivers and `Http::fake` feeds only.
