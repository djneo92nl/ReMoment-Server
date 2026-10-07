# Mozart Hardware Test Checklist

The Mozart driver (`packages/remoment/mozart-driver`, protocol in `packages/djneo92nl/beo-mozart-php`) was built against B&O's official OpenAPI spec, without hardware. This is the list to work through when a real device arrives. See [device-drivers.md](device-drivers.md#bang--olufsen-mozart) for what the driver implements.

Connecting proves the REST client works; it says nothing about the notification stream, discovery or the endpoints below marked **unverified**. Start with sections 1 and 2.

## 1. Discovery and connecting (highest risk)

- [ ] `php artisan device:mozart-discovery` finds the device. **Unverified:** assumes Mozart is a UPnP MediaRenderer like ASE. If not, add it manually at `/devices/create` and note what SSDP actually returns.
- [ ] REST works: device info, volume read and sources list return data.
- [ ] WebSocket connects on port 9000 (`config('mozart.*')`). **Unverified:** the spec documents no real-time connection info; 9000 is a community convention. Run `php artisan device:listen-single {id}` and confirm `/settings/health` shows the listener alive.
- [ ] Listener reconnects after the device reboots or the network drops.

## 2. Now playing and listener

- [ ] Track change updates title, artist, album, duration and artwork (web, `GET /api/devices/{id}`, MQTT `/data`).
- [ ] Progress is published every second; a seek shows up correctly.
- [ ] Playing, paused, standby and unreachable map to the right `State`; MQTT `/data` clears on standby.
- [ ] Non-track sources (BeoRadio, line-in, Bluetooth) show a name and a source logo, not an empty card.
- [ ] Volume and mute changed on the device itself arrive via the listener (MQTT `/volume`).
- [ ] Battery appears on a portable model (`GET /api/v1/battery`, MQTT `/battery`).
- [ ] No parse errors in `storage/logs`. Save one raw WebSocket notification per source type as a test fixture.

## 3. Playback controls

- [ ] Play, pause, stop, next, previous.
- [ ] Seek (`PUT /api/v1/playback/seek`).
- [ ] Shuffle and repeat (`PUT /api/v1/playback/queue/settings`). **Unverified:** current values are sniffed from notifications by their `{shuffle, repeat}` keys; modes may stay `null` until changed through the API.
- [ ] Standby works; `PUT /api/devices/{id}/power` with `on: true` returns 422. Check whether the device really has no wake endpoint.

## 4. Sources

- [ ] Source list loads; hide/sort preferences survive a refresh.
- [ ] Source activation switches between at least two sources, and `now_playing.source` follows.

## 5. Multiroom (Beolink JID)

- [ ] The Mozart JID is read and stored in `device_meta` under `multiRoomMetaKey()`.
- [ ] Mozart joins an active ASE device (`joinSession`): audio plays, `getCurrentPeerIds` shows the group.
- [ ] An ASE device joins Mozart. This goes through ASE's `MultiRoomControls`; the JID-to-device lookup knows `ase_jid` and `sonos_uuid`, so confirm it also finds Mozart's meta key.
- [ ] JIDs are mostly interchangeable between ASE and Mozart, but not for every source. Known: **Spotify cannot be multiroomed to a BeoSound Moment**. Test a join per source (Spotify, BeoRadio, DLNA, line-in, Bluetooth) in both directions and record which combinations fail.
- [ ] A refused or impossible join is visible to the user. Mozart's `joinSession` is fire-and-forget (the result arrives later as `WebSocketEventBeolinkJoinResult`), and the web card's "same-brand" fallback offers devices that can't actually join, so check that nothing fails silently.
- [ ] `leaveSession` works from both sides.
- [ ] `joinable` and `listeners` are right on both devices, and the UI follows grouping changes made on the device.

## 6. Library, radio, settings

- [ ] DLNA library playback: a single track, then an album or playlist.
- [ ] Radio station play (`beoradio` key).
- [ ] Sound adjustment: bass, treble and loudness ranges and writes.
- [ ] Bluetooth lists paired devices only (`writable: false`).
- [ ] Device info shows name, firmware and MAC; renaming works.
- [ ] `/devices/{id}/settings` shows only the cards the capabilities justify.

## 7. Cross-checks

- [ ] `GET /api/devices` lists the expected `capabilities`.
- [ ] Spotify routing works with Spotify Connect mapped to the Mozart.
- [ ] Plays appear in `/history`; Last.fm scrobbles if enabled.

## Day-one tips

- Probe with GET-only requests first (safe), and compare real response shapes with the spec the driver was built from.
- Anything that differs from the spec: fix it in `packages/djneo92nl/beo-mozart-php`, add a fixture-based test, and update the "unverified" notes in `device-drivers.md` and `CLAUDE.md`.
