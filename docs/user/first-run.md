# First run

You have installed ReMoment ([Docker](install-docker.md), [Ansible](install-ansible.md) or [by hand on a Pi](install-raspberry-pi.md)) and the web page opens.

## The admin login

ReMoment has exactly one shared admin login. There are no personal accounts and no registration. The install creates it (`php artisan db:seed`, run by the Ansible deploy and by the Docker steps) with the email `admin@admin.com` and the password `admin`.

The login is only needed for setup and configuration: everything under `/settings`, the setup wizard, connecting Spotify and Last.fm, and approving client devices. Everything else (device list, playback controls, history, library, `/receiver`) works without logging in, and so does the REST API. Apps that cannot use a browser session sign in with the same password at `POST /api/admin/login` and get a token; the signed-in apps are listed under Settings › Admin & Apps (`/settings/users`).

Sign in at `/login`.

## The setup wizard (`/setup`)

While no device exists, opening `/devices` after signing in sends you to `/setup` (once you finish or dismiss the wizard, this never happens again). It is a checklist; every item links to a normal settings page.

**Profile.** At the top are three cards, Control, Standard and Full, with what each runs and needs. The card of the profile that runs (from `PROFILE` in `.env`) is marked *Running*. Clicking *Choose* only records your intent; the environment wins. If your choice differs from what runs, the page says what to change (`PROFILE` in `.env`, `COMPOSE_PROFILES`, restart the containers, `profile:upgrade` for Control to Standard or Full). A lower profile is not supported.

**Step 1: add your devices** (required). Links to `/settings/devices`, where *Scan* looks for devices on the network and lists what it finds; per row you can rename, *Add* or *Ignore* (or *Add all*). Devices that scanning cannot find are added by IP at the same page or at `/devices/create`. A background scan also runs hourly. Per brand: [adding-devices.md](adding-devices.md).

**Step 2: client devices** (can be skipped). Wall panels, ESP32 touch remotes and apps register themselves with the server (`POST /api/clients/register`) and show a pairing code; you approve them at `/settings/clients` and choose which devices each may see. ESP32 and other firmware authors: [client-devices API](../api/client-devices.md). Clients find the server by mDNS (`_remoment._tcp`, advertised by Avahi on the host; the Ansible `setup.yml` installs it), then read `GET /api/info` for the MQTT address.

**Step 3: set up your library** (can be skipped; not shown in Control). Links to:

- `/settings/dlna`: find DLNA servers and scan them.
- `/settings/storage`: add folders with your own music as storage locations (`MUSIC_DIR` and `LIBRARY_DIR`) and scan them.
- Spotify: first enter your own app's Client ID and secret at `/settings/services` (each install uses its own; see [spotify-app-setup.md](../architecture/spotify-app-setup.md)), then connect. Last.fm scrobbling is at `/settings/lastfm`.

All keys for external services (Spotify, Last.fm, Discogs, AcoustID, TheAudioDB, Jellyfin) are entered at `/settings/services`; they are stored encrypted.

## Check that it works

Open `/devices`: each added device shows a card with its state. Start something on a device (from its own app or the card) and the card should update live. If not, see [troubleshooting.md](troubleshooting.md). `/settings/health` shows the broker, scheduler, queue worker and per-device listeners.
