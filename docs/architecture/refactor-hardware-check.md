# Hardware check after the duplicate-code cleanup

The October 2026 cleanup (phases 1 to 7, see `git log` from `[Refactor] Remove dead code…` to `[Test] MakesLibraryTracks…`) was verified by the test suite only, with fakes standing in for devices. This is the list to work through on real hardware. The code that talks to a device kept its behaviour on paper; these are the places where a fake can't prove it.

Work top to bottom: sections 1 to 3 are the highest risk, because a mistake there takes a device offline or silences playback.

**Before you start**

- Run `php artisan device:listen` (or the Listeners page) and the queue worker; several checks need both.
- The BeoPlay V1 stays in standby: use **read-only** checks on it (`php artisan ase:map <ip>`). Don't power it up for this list.
- For writes on your own speakers, prefer the same value back (volume 30 to 30, name to itself) and ask before a real change.
- Note the failures with the device model and what you did; a test can be written from each.

## 1. Device listeners (highest risk)

All four listeners now share `ListenerBackoff` (reconnect pause) and `DeviceCache::markListenerAlive()` / `forgetListener()` (the heartbeat the UI reads). Their loops are otherwise unchanged.

- [ ] **ASE** (Essence, M3, M5): start the listener; `/settings/health` shows it alive and the device card goes from Unreachable to Standby or Playing within seconds.
- [ ] ASE: unplug the speaker or switch off its Wi-Fi for a minute. The card turns Unreachable, and when the speaker is back it recovers by itself, in about 30 s at most (the pause doubles up to 30 s).
- [ ] ASE: after recovery, track, progress, volume and state all update again (not just the card; check MQTT `/data` and `/state`).
- [ ] **Sonos**: same two checks (kill the speaker's power, bring it back). The first failure is logged once, not on every poll.
- [ ] **Spotify** (virtual device): with Spotify disconnected the device stays Unreachable and the listener doesn't spin; connect at `/settings` and it starts without a restart.
- [ ] Spotify: revoke or break the token once (or wait for expiry). An API error marks it Unreachable, and it recovers after a refresh.
- [ ] **Mozart** (if a device is available): WebSocket reconnects after a reboot; see also `mozart-hardware-test.md`.
- [ ] Stop a listener with Ctrl-C: its card turns Unreachable after about 10 s (the heartbeat's lifetime), not immediately and not never.

## 2. Discovery

`AseDiscovery` and `MozartDiscovery` now share `UpnpMediaRendererDiscovery`; each reports only the models whose `driver_name` is its own. A full scan runs two SSDP searches.

- [ ] `php artisan device:discover` (no `--brand`) completes without an error and lists your ASE speakers. Before the cleanup `MozartDiscovery` could not even be built.
- [ ] `--brand=ase`, `--brand=sonos` and `--brand=mozart` each report only their own devices. An Essence, M3 or M5 must **not** appear under Mozart, and a Theatre or other Mozart model not under ASE.
- [ ] Time a full scan: about 4 to 8 s longer than before is expected.
- [ ] `/settings/devices` (the driver manager) loads; it used to throw. Scan per driver finds the devices, and the groups list the right models per driver (ASE, Mozart, Sonos, Spotify).
- [ ] Add a device by hand at `/devices/create` and edit one at `/devices/{id}/edit`: the brand and model pickers list the models, and a virtual device (Spotify Connect) needs no IP.

## 3. Playing the library on a device

ASE and Mozart now share `PlaysDlnaLibrary`: the first track starts at once, the rest is queued. Sonos keeps its own code.

- [ ] **ASE**: play a single DLNA track from the library on an Essence/M3/M5. It starts, and the device card shows it.
- [ ] ASE: play a whole album. Track 1 starts immediately, tracks 2 onward are queued in album order, and "Up next" (where the device supports it) lists them.
- [ ] ASE: play a playlist and an artist; shuffle on and off.
- [ ] ASE: play an album with one track that has no DLNA URL. The playable ones play and the flash message says "N of M tracks".
- [ ] **Sonos**: single track, album, playlist (unchanged code, but the web flash messages changed, see section 9).
- [ ] **Mozart**: single track and album: `playUri` for the first, `enqueue('track', 'dlna', url)` for the rest. **Unverified** on hardware, like the rest of the Mozart driver.
- [ ] A device that cannot play the library gets the "can't play" message, not an error page.

## 4. Volume and mute

`Volume::remember()` (cached volume, else read once from the device) and `DeviceVolume::set()` (clamp 0 to 100, set, remember) are shared by the drivers and the web components. A cached volume of 0 is now a real level, not a miss.

- [ ] ASE: open a device card for a speaker that has been idle for over 6 minutes (the cache has expired). The slider shows the true level, read from the device once.
- [ ] ASE: set volume to 0 from the card; the slider stays at 0 and doesn't jump to the device's old level on the next refresh.
- [ ] Set volume from the **device card**, the **sticky player bar** and a **room tile** in the multiroom picker. The speaker follows each, and the other views follow within a refresh.
- [ ] Mute and unmute from the card and the bar. A mute pressed on the device itself shows up in the web UI.
- [ ] A volume above 100 typed through the API (`PUT /api/devices/{id}/volume`) is rejected with 422; the web components clamp instead.
- [ ] Mozart: same volume read, and `incrementVolume` steps by `mozart.volume_step`.

## 5. Multiroom

The peer id (`ase_jid`, `mozart_jid`) is read through `MultiRoomId::remember()`, and the picker code in `ManagesMultiroom` was restructured.

- [ ] An ASE device reads its JID once and keeps it: `device_meta` has an `ase_jid` row and a second call doesn't hit the device (check the speaker's request log, or the time).
- [ ] Open the **multiroom picker** from a device card and from the sticky bar. It lists the joinable rooms (when not playing) or the members and invitable devices (when playing); the close × works.
- [ ] Join a session from a card, invite a device from the picker, remove a member, and leave. Each shows the success state, and a failure shows "Join failed: …" style text instead of nothing.
- [ ] Room volume tiles in the picker set the right room's volume.
- [ ] ASE joins ASE, and (if you have one) ASE and Mozart in both directions; see `mozart-hardware-test.md` section 5.
- [ ] MQTT `/multiroom` is published once on a change, not on every poll.

## 6. Sound adjustment and settings

Validation moved to `SoundAdjustment::assertCanSet()` / `AdjustmentRange::assertAccepts()` for ASE, Mozart and Sonos; the 422 answers should be identical.

- [ ] `GET /api/devices/{id}/sound-adjustment` on an ASE speaker, a Sonos and a Mozart (if present) returns ranges.
- [ ] `PUT` with the current value (the same value back) succeeds and a read-back matches.
- [ ] `PUT` a value outside the range (bass 99) gives 422 `invalid` with "bass must be between … (step …)".
- [ ] `PUT` a part the speaker doesn't have (treble on a model without it) gives 422 `unsupported`.
- [ ] `/devices/{id}/settings` shows only the cards the model justifies: the **Moment** has the wireless-speakers card and the **Essence** and **Moment** the wired-speakers card; the M3 and M5 have neither.
- [ ] Rename a device through the settings page to its own name (a same-value write), and confirm the name and firmware show.

## 7. REST API behaviour

Every device endpoint now answers through one guard (`GuardsDeviceAccess::withDriver()`). Mostly this is the same answers; a few cases changed.

- [ ] Power-off a speaker you can wake again, then call `POST /api/devices/{id}/play`: **503** `unreachable`.
- [ ] `GET /api/devices/{id}/volume` while the speaker is mid-reboot: **502** `driver_error` (it used to crash with a 500).
- [ ] `PUT /api/devices/{id}/power` with `on: true` on a Mozart: **422** `unsupported` with its message.
- [ ] `GET /api/devices/{id}/sources` twice after hiding one source and dragging another in the source manager: the hidden flag and your order **survive** (the API used to wipe them).
- [ ] `GET /api/devices/{id}/multiroom` and `GET /api/devices/{id}/sources` still answer for a device the cache calls unreachable (they skip the reachability check on purpose).
- [ ] Radio: `GET /api/devices/{id}/radio` lists stations, `POST …/radio/{id}` plays one.
- [ ] Queue, seek, shuffle, repeat, like on a Spotify-routed speaker still go to Spotify (see `docs/architecture/device-drivers.md`, Spotify routing).

## 8. Artwork

The web UI and receiver now show only our processed proxies, never the original DLNA, Spotify or radio image URL.

- [ ] Play a DLNA album from the NAS (a Jellyfin or MiniDLNA cover URL on your LAN). The card, the bar, history and the album page show the artwork.
- [ ] **First play of a never-seen cover**: the placeholder shows briefly, then the cover appears after the queue worker has processed it. With the worker stopped it stays a placeholder and the page doesn't error.
- [ ] View the page source of `/devices`, `/library`, `/albums/{id}`, `/history` and `/radio`: **no** `http://192.168…` or other LAN image URLs, only `/storage/artwork/…`.
- [ ] `/receiver`: artwork appears for a track, a radio station and a source logo; it no longer falls back to a raw image while a cover is processed.
- [ ] Radio station with an image: its logo shows; a station without one shows the generated radio logo.

## 9. Web UI

- [ ] **Device page** (`/devices/{id}`): the card shows lyrics (a track that has them) and "Add to library" (a streaming or radio track the library lacks); adding works and links to the album.
- [ ] A command that fails (pause on a speaker that just went offline) shows "Command failed: …" under the card's controls and clears on the next success. Grid cards didn't show errors before.
- [ ] Source buttons and icons on a card and in the source manager are unchanged (HDMI, line-in, DLNA, Bluetooth, CD).
- [ ] Play buttons on albums, artists, playlists, tracks, radio and the "More on Spotify" rows still play on the pinned player, or open the device picker.
- [ ] Play something a device can't play: the flash reads "&lt;device&gt; can't play "&lt;name&gt;"." (the wording is now the same everywhere).
- [ ] Durations: a 3:33 track shows `3:33` on album, playlist and "More on Spotify" lists; a 1 h 5 min total shows `1h 5m`.
- [ ] The now-playing title in the bar and in the picker rows: a track shows artist and album, a radio station "Radio", a source its name and type, an idle device "Standby" or "Unreachable".

## 10. MQTT

Four listeners publish through `MqttService::publishIfChanged()`. Use `mosquitto_sub -v -t 'remoment/player/#'` while you do the steps.

- [ ] `/volume`, `/modes`, `/battery` (a Mozart or portable Sonos) and `/multiroom` each publish **when the value changes** and not again for the same value.
- [ ] They are **retained**: a client that connects later gets the last value at once.
- [ ] A reconnecting listener (section 1) doesn't spam repeated identical messages.
- [ ] `/data` is cleared (empty retained payload) on standby or unreachable, as before.

## 11. History, metadata and Last.fm

`StorePlaybackHistory` and "Add to library" share `NowPlayingTrackResolver`, the metadata services share `MetadataHttp`, and enrichment jobs use shared savers.

- [ ] Play a track from Spotify on a speaker with Spotify Connect mapped: it shows up once in `/history`, with the right device (the speaker, not the Spotify device), and `ImportSpotifyAlbum` pulls in the album if the library has it switched on.
- [ ] With `library_add_played_tracks` off (`/settings/library`), a track the library doesn't have is logged as text; with it on, the artist, album and track are created.
- [ ] Radio with artist and title keeps its station on the play.
- [ ] `php artisan library:enrich --limit=5`: MusicBrainz, lyrics (LRCLIB), Discogs (with a token), TheAudioDB, Last.fm and the Cover Art Archive all run; check the queue worker log for 429 or 5xx **retries** rather than failures, and for the User-Agent in your own request logs (or set `METADATA_USER_AGENT`).
- [ ] Last.fm scrobble and now-playing still reach Last.fm (they go through `MetadataHttp::request()` for the User-Agent).
- [ ] The library album detail API lists `sources` (your change) and the `/api/library` artwork URLs are root-relative.

## 12. Things the cleanup removed, to confirm nothing used them

- [ ] The old `Nowplaying` Livewire component and `x-device.list-view`: no page of yours embedded them.
- [ ] The web route `devices.sources.activate`: source switching still works from a card (Livewire) and from `POST /api/devices/{id}/sources/activate`.
- [ ] `RegisteredUser`, password-reset and email-verification controllers: there is no registration, as intended; `/login` and the profile password change still work.
- [ ] `getCurrentPlayingAttribute()` is gone from every driver: a client or script of yours doesn't call `$device->currentPlaying`.

## If something fails

Each phase is its own set of commits on `main`, so a bad one can be reverted alone. The most likely suspects, by symptom:

| Symptom | Look at |
|---|---|
| A card stays Unreachable after a device comes back | `ListenerBackoff`, `DeviceCache::markListenerAlive()` and that device's `DeviceListener` |
| Volume slider jumps or shows 0 | `Volume::remember()` / `DeviceVolume::set()` |
| Album plays only one track on ASE or Mozart | `PlaysDlnaLibrary` and the driver's `startDlna()` / `enqueueDlna()` |
| Device found under the wrong brand | `UpnpMediaRendererDiscovery::driverName()` and `config/devices.php` |
| A settings card appears (or not) for the wrong model | `HardwareFeatures::allows()` and `DeviceModels` |
| 502 where there used to be 422 (or the other way) | `GuardsDeviceAccess::answerDriverCall()` |
| Raw image URL or no cover at all | `LibraryItemArtwork::proxy()`, `<x-artwork-thumb>` and the queue worker |
