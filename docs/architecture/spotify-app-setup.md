# Spotify: your own app

Since February 2026 Spotify's Development Mode allows 5 authorized users per Client ID and needs the app owner on Premium (see [spotify-api-audit.md](spotify-api-audit.md)), so ReMoment ships no shared Client ID: **every install uses its own Spotify app**.

1. Open the [Spotify developer dashboard](https://developer.spotify.com/dashboard) (Premium account) and create an app. Select "Web API".
2. Add the redirect URI shown on **Settings › Spotify** (`{APP_URL}/settings/spotify/callback`). It must match exactly, including `http`/`https` and the port.
3. Copy the app's Client ID and Client secret into **Settings › Spotify › Your Spotify app** and save (admin login).
4. Press **Connect Spotify** and approve. The permissions asked are listed in `SpotifyTokenService::SCOPES` plus the optional `user-library-modify` (likes).

Up to 4 more people can be added under the app's *User management* in the dashboard (the owner counts as one); only the single ReMoment admin connects an account.

## Where the credentials live

- `Settings` rows `spotify_client_id` and `spotify_client_secret` (the secret encrypted with `APP_KEY`), set by the form; they win over `.env`.
- `SPOTIFY_CLIENT_ID` / `SPOTIFY_CLIENT_SECRET` in `.env` (Ansible: `vault_spotify_client_id` / `vault_spotify_client_secret`) stay a fallback for existing installs.
- Saving a different Client ID or a new secret disconnects the current account (its tokens belong to the old app). If `APP_KEY` changes, the stored secret is unreadable and `.env` is used, or the form must be filled again.
- `/settings/health` reports "No Spotify app configured" until credentials exist.

Code: `SpotifyTokenService::clientId()/clientSecret()/saveCredentials()`, `SpotifyAuthController::saveCredentials`. Test: `tests/Feature/SpotifyCredentialsTest.php`.
