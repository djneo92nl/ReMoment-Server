# Credentials layer (workstream 14.4)

One registry and one settings page for the keys of the external services, so each service is not its own form, storage rule and health probe, and so a hosted relay can later stand in for the user's own keys without a rewrite. Every public install brings its own keys (Spotify Development Mode allows 5 users per Client ID; Last.fm, Discogs, AcoustID and TheAudioDB have their own terms).

Page: **Settings › Services & keys** (`/settings/services`, admin login, Livewire `App\Livewire\ServiceCredentialsManager`). Services in 1.0: Spotify, Last.fm, Discogs, AcoustID, TheAudioDB, and Jellyfin (a media server on your own network, for TV now playing).

## The registry

`App\Domain\Credentials\ServiceCredentials` holds one `ServiceDeclaration` per service (`declare()`), each with `CredentialField`s. A declaration has: `id`, `label`, `icon`, `purpose`, `fields` (name, label, `type` text|password|url, `secret`, `required`, validation `rules`, the settings key it is stored under, the `.env`/config fallback path), the default `baseUrl` (and an optional config path for an `.env` override), the `probes` (ids of the existing `ExternalServices` probes), setup `steps`, a `link`, `terms`, an optional `callbackRoute`, an `account` (the user-specific OAuth login that stays outside the registry: Spotify and Last.fm session) and an `onChanged` hook (Spotify disconnects the account when the app changes).

Everything reads through it:

| Call | Meaning |
|---|---|
| `value($id, $field)` | the settings row (decrypted for a secret), else the config/`.env` fallback, else `''` |
| `isSet`, `configured($id)`, `missing($id)`, `origin()` | set state; `origin` is `settings`, `env` or `null` |
| `baseUrl($id)` | the API base URL without trailing slash: setting `{id}_base_url`, then the declared config path, then the default |
| `source($id)` | `own` (the user's keys); `relay` is reserved and nothing branches on it |
| `save($id, $values)` | empty secret = keep; empty other field = clear; runs `onChanged` |
| `secretSettingKeys()` | the keys the backup and the diagnostics bundle must leave out |

The clients use it: `SpotifyTokenService` (`clientId()`/`clientSecret()` delegate), `SpotifyLibraryApi`, `LastfmSessionService`, `LastfmInfoClient`, `DiscogsClient`, `AudioDbClient`, `AcoustIdStage`, and the probes in `ExternalServices`. A new client reads its key and base URL the same way, never `config()` or a URL constant.

### Settings keys

| Service | Fields (settings key) | `.env` fallback | Default base URL |
|---|---|---|---|
| Spotify | `client_id` (`spotify_client_id`), `client_secret` (`spotify_client_secret`, secret) | `SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET`; `SPOTIFY_API_URL` for the address | `https://api.spotify.com/v1` |
| Last.fm | `api_key` (`lastfm_api_key`, secret), `shared_secret` (`lastfm_shared_secret`, secret) | `LASTFM_API_KEY`, `LASTFM_SHARED_SECRET` | `https://ws.audioscrobbler.com/2.0` |
| Discogs | `token` (`discogs_token`, secret) | `DISCOGS_TOKEN` | `https://api.discogs.com` |
| AcoustID | `api_key` (`acoustid_api_key`, secret) | `ACOUSTID_API_KEY` | `https://api.acoustid.org/v2` |
| TheAudioDB | `api_key` (`audiodb_api_key`, secret, optional) | `AUDIODB_API_KEY` (default `123`, the public test key) | `https://www.theaudiodb.com/api/v1/json` |

Base-URL override keys are `{id}_base_url`. An `.env`-only install behaves exactly as before: the setting is empty, the fallback is used.

## Adding a service (Deezer, ListenBrainz, Tidal)

Add one `new ServiceDeclaration(...)` to `ServiceCredentials::declare()` with its fields, base URL, probe ids and steps; add a probe to `ExternalServices::probes()` if it has none; make its client read `ServiceCredentials::value()`/`baseUrl()`. The page, the masking, encryption, backup exclusion and the Test button follow from the declaration. `tests/Feature/ServiceCredentialsTest` checks that every declared probe id exists.

## Storage and secrecy

- A `secret` field is encrypted with `Crypt::encryptString` in the `settings` table. A plain-text row (by hand, or an older version) is encrypted by the migration `encrypt_plaintext_service_secrets` (`ServiceCredentials::encryptPlaintext()`); a row that cannot be decrypted (APP_KEY changed) counts as not set, so the `.env` fallback or a new entry takes over.
- The UI shows "set" and, for values of 12+ characters, the last 2 characters; never the value. Secrets are never loaded into Livewire properties (only what the admin types travels), so they are not in the page's snapshot. An empty secret input on save means "keep".
- Health probe messages are scrubbed of every credential value (`ExternalServices::redact()`), because an HTTP error message can contain the request URL with the key.
- No API or JSON response returns a credential. There is **no admin API for credentials** in 1.0: not needed by the apps yet (they would only need set/not-set; add an entry in the `admin.token` group, `docs/api/openapi.yaml` and `docs/api/mcp.md` when one does, exposing declaration metadata and set state only).
- Writing is admin-only: the page is behind the `auth` middleware and the component refuses a guest on mount and on every request.
- **Backups:** `Snapshot::isSecretSetting()` leaves out every key in `ServiceCredentials::secretSettingKeys()` plus the old pattern (`token|secret|session_key|password`) as a safety net, unless `--with-secrets`. A restored install must enter its keys again (or have them in `.env`). See `backup-restore.md`.
- **Diagnostics bundle** (workstream 9b, not built yet): it must exclude the same keys by calling `ServiceCredentials::secretSettingKeys()` and must never include `.env`.

## Base URL and the relay hook

Every client takes its address from `ServiceCredentials::baseUrl()`, never a constant. A hosted relay (after 1.0) would set a service's source to `relay` and have `value()` return the relay token and `baseUrl()` the relay address in one place. **Not built:** the relay, the `relay` behaviour (the `{id}_source` setting is only read, nothing branches on it) and any UI for it. Not covered either: Spotify's OAuth host (`accounts.spotify.com`, fixed inside the `jwilsonc/spotify-web-api-php` `Session` and the probe) and the Last.fm authorization page (`www.last.fm/api/auth`); the user-specific sessions are the household's login, not part of the registry.

## Setup steps per service

Shown on the page (the declaration's `steps`); sources checked 2026-10-27.

| Service | Steps | Confirmed from |
|---|---|---|
| Spotify | Dashboard with a Premium account, create an app (Web API), add the redirect URI `{APP_URL}/settings/spotify/callback`, copy Client ID and secret, Connect. Development Mode: 5 users per Client ID | `spotify-app-setup.md` / `spotify-api-audit.md` (earlier research) |
| Last.fm | API account at `last.fm/api/account/create`, copy key and shared secret, Connect. Non-commercial use only; commercial or research use: contact partners@last.fm; limits are Last.fm's to set | `last.fm/api` and `last.fm/api/tos` (the create page itself needs a login, so its form fields were not read) |
| Discogs | Settings › Developers › Generate new token | the repository's earlier note (`config/metadata.php`); discogs.com answered 403 to automated fetching, so **limits and terms are unconfirmed**: "check the service's terms" |
| AcoustID | Register an application at `acoustid.org/new-application`; use the application key, not the personal one. Free for non-commercial use, 3 requests/second; commercial use via acoustid.biz | `acoustid.org/webservice` |
| Jellyfin | Dashboard › Advanced › API Keys, add a key, paste it; set the server address as Base URL. Used by the Philips TV listener (`Integrations/Jellyfin`) for the title the Jellyfin app plays | the Jellyfin REST API (`GET /Sessions`); **unverified against a real server**: no key was available |
| TheAudioDB | Key `123` works (30 requests/minute); Premium (100/minute, $8/month) key is on the profile page | `theaudiodb.com/free_music_api`; its terms page was not read |

## Not verified

Live services (no real key was used; probes are tested with `Http::fake`), a real browser rendering of the page (it follows `DESIGN.md` and the shared components but was only rendered in tests).
