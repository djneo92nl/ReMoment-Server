# Last.fm Integration

Scrobbles playback history to Last.fm and enriches artists with bios and similar-artist data pulled from Last.fm's public API.

## Configuration

`config/lastfm.php` reads two env vars, both from a Last.fm API app (https://www.last.fm/api/account/create):

| Key | Env var | Required for |
|-----|---------|---------------|
| `api_key` | `LASTFM_API_KEY` | Everything — auth, scrobbling, and artist enrichment |
| `shared_secret` | `LASTFM_SHARED_SECRET` | Authenticated calls only (session auth, `track.scrobble`, `track.updateNowPlaying`) |

Artist enrichment (`EnrichArtistLastfm`) only needs `api_key` — it calls the public `artist.getInfo` method directly and does not require a connected Last.fm account.

## Account Connection

`LastfmSessionService` implements Last.fm's web auth flow:

1. `GET /settings/lastfm/authorize` (`LastfmAuthController::authorize`) redirects to Last.fm with the app's `api_key` and a callback URL.
2. Last.fm redirects back to `GET /settings/lastfm/callback` with a `token` query param.
3. `LastfmSessionService::handleCallback()` exchanges the token for a session key via `auth.getSession` and stores `lastfm_session_key` / `lastfm_username` in the `Setting` key-value store.
4. `POST /settings/lastfm/disconnect` clears both settings.

Connection status and the connected username are shown at `/settings/lastfm` (`SettingsController::lastfm`).

## Signed Requests

`LastfmSessionService::signedRequest(array $params)` is the single entry point for authenticated API calls (used by scrobbling and now-playing updates, not by `EnrichArtistLastfm`). It:

- Adds `api_key` and, if connected, the stored session key (`sk`)
- Computes `api_sig` per Last.fm's signing algorithm (sorted params, concatenated, md5'd with the shared secret — `format` is excluded from the signature as required by the API)
- POSTs as a form to the Last.fm REST endpoint
- Throws `RuntimeException` on an HTTP failure *or* when the response body carries a Last.fm `error` code (the API returns HTTP 200 with an `error` field for things like an invalid/expired session key or rate limiting) — callers never see a silently empty result for a failed call

## Scrobbling

Two jobs, both `ShouldQueue` with `tries = 3` / `backoff = 60`, dispatched from the playback history listeners (`app/Listeners/Device/StorePlaybackHistory.php`, `app/Listeners/Device/ClosePlaybackHistory.php`):

- **`SendNowPlayingToLastfm`** — dispatched when a track starts playing; calls `track.updateNowPlaying`.
- **`ScrobbleToLastfm`** — dispatched when a `Play` ends; calls `track.scrobble` only if Last.fm's scrobble rule is met (listened at least half the track, capped at 4 minutes) and the track wasn't skipped.

Both no-op silently (no job dispatched to Last.fm) if no account is connected. If `signedRequest` throws, the queue worker retries the job per `tries`/`backoff` before giving up — a non-transient error (e.g. a revoked session key) will still burn all retries, since retryable and permanent Last.fm error codes aren't currently distinguished.

## Artist Enrichment

`EnrichArtistLastfm` (dispatched from `EnrichTrackMetadata::enrichFromLastfm()` the first time a track by a not-yet-enriched artist is played, and from the backfill command below) fetches `artist.getInfo` and stores:

- `bio` — the summary text, with Last.fm's trailing "Read more on Last.fm" link stripped
- `similar_artists` — a JSON array of similar artist names

Both are stored as `Metadata` rows (`metadatable_type` = Artist, `source` = `lastfm`), keyed so re-running enrichment updates rather than duplicates.

## Backfill

```bash
php artisan library:backfill-lastfm
```

Queues `EnrichArtistLastfm` for every artist that doesn't yet have `lastfm`-sourced metadata. Idempotent — safe to re-run. Scheduled daily in `routes/console.php` alongside `library:backfill-artwork`, to pick up artists whose live enrichment never fired (e.g. no track of theirs has been played since this feature shipped) or failed.
