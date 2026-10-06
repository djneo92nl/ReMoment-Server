# Device settings API

Settings that change how a device behaves rather than what it plays. Each lives behind its own capability, so a client only offers what `GET /api/devices` lists in `capabilities`. The API is the same for every platform; what a platform can't do is a capability it doesn't list, or a `writable: false` / `422 unsupported`.

No authentication, like the rest of the REST API. The web page `/devices/{id}/settings` is behind the admin login.

Errors, as for the controls: `503 unreachable`, `422 unsupported` (device lacks the capability, or the one operation), `422 invalid` (value outside the range / unknown mode / unknown paired device), `502 driver_error` (the device failed, or answered but did not apply the change — every write is read back). Validation errors are Laravel's `422 { message, errors }`.

| Capability | B&O ASE | Mozart | Sonos | Spotify |
|---|---|---|---|---|
| `sound_adjustment` | bass/treble ±10, loudness | bass/treble/loudness; ranges from `sound/features` | bass/treble ±10, loudness | – |
| `bluetooth` | pairing mode, reconnect mode, paired list, remove | paired list only (`writable: false`) | – | – |
| `device_info` | name, model, firmware, MAC; rename | model, firmware (`softwareupdate`); rename. The API can't read the name back, so ReMoment's own is shown | room name, model, firmware, MAC; **no rename** (`renamable: false`) | – |
| `network_settings` | wired/Wi-Fi status, switch interface, wired DHCP/static, join Wi-Fi | – | – | – |
| `wireless_speakers` | WiSA scan — **BeoSound Moment only** (see below) | – | – | – |
| `wired_speakers` | speaker type per wired output — **Essence and Moment** (models with external speakers) | – | – | – |

Mozart and Sonos are built from the Mozart OpenAPI spec and the Sonos library, **not yet tried on real hardware** (none was reachable). Treat their first run as unverified: report anything that looks off.

## Sound adjustment — `sound_adjustment`

```
GET /api/devices/{id}/sound-adjustment
→ { "bass":   { "value": 1, "min": -10, "max": 10, "step": 1 },
    "treble": { "value": 0, "min": -10, "max": 10, "step": 1 },
    "loudness": true }

PUT /api/devices/{id}/sound-adjustment     body: any of { "bass": 3, "treble": -2, "loudness": false }
→ the new state, as above
```

A part the device doesn't have is `null` (and `422 unsupported` if you set it). Ranges come from the device, so read them instead of assuming ±10.

## Bluetooth — `bluetooth`

```
GET /api/devices/{id}/bluetooth
→ { "enabled": true, "discoverable": false,
    "reconnect_mode": "manual", "reconnect_modes": ["manual", "automatic", "disabled"],
    "writable": true,
    "devices": [ { "id": "…", "name": "Remko iPhone", "address": "AA:BB:…", "connected": true } ] }

PUT /api/devices/{id}/bluetooth            body: any of { "discoverable": true, "reconnect_mode": "automatic" }
→ the new state

DELETE /api/devices/{id}/bluetooth/devices/{deviceId}     → the new state
```

To pair a phone: `PUT { "discoverable": true }`, pair from the phone, poll `GET` until it appears in `devices`, then `PUT { "discoverable": false }` (the device does not switch it off by itself). `reconnect_modes` is empty and `writable` false when the platform can only list devices.

A paired device's `id` is URL-encoded by the device (`FC%3aB2%3a14%3a…`); `DELETE` accepts it encoded or decoded. Verified on an Essence Mk2: the list shape (`id`, `deviceName`, `deviceAddress`, `connected`, `paired`; entries with `paired: false` are left out), toggling `discoverable`, pairing a device, and removing one through the item's own `_links./relation/delete`. The device answers the `DELETE` before it has applied it, so the driver waits (up to about 4 s) until the device is gone from the list and answers `502` if it never is. Not yet exercised on hardware: `reconnect_mode` changes.

## Device info — `device_info`

```
GET /api/devices/{id}/info
→ { "name": "Woonkamer Speakers", "product_type": "BEOSOUND_ESSENCE_MK2", "firmware": "6.7.58710.100086671", "mac_address": "B0:D5:…", "renamable": true }

PUT /api/devices/{id}/info                 body: { "name": "Kitchen" }     (1–64 characters)
→ the new info
```

`renamable` is false where the platform can't rename (Sonos: `PUT` answers `422 unsupported`); `product_type`, `firmware` and `mac_address` are `null` when the platform doesn't report them. A rename is written to the device and to ReMoment's own `device_name`.

## Network — `network_settings`

```
GET /api/devices/{id}/network
→ { "active_interface": "wireless", "interfaces": ["wired", "wireless"], "internet_reachable": true,
    "wired":    { "type": "wired", "status": "inactive", "dhcp": true, "address": null, "subnet_mask": null, "gateway": null, "preferred_dns": null, "alternative_dns": null, … },
    "wireless": { "type": "wireless", "status": "connected", "ssid": "IDiot", "frequency": "5ghz", "quality": "excellent", "signal": -55, "security": "wpa2PskTkip", "dhcp": true, "address": "192.168.1.103", … },
    "wifi_networks": [ { "ssid": "IDiot", "frequency": "2.4ghz", "security": "wpa2PskTkip", "configured": true, "active": true } ] }

PUT /api/devices/{id}/network/interface    body: { "interface": "wired" | "wireless" }
PUT /api/devices/{id}/network/wired        body: { "dhcp": true }   or   { "dhcp": false, "address", "subnet_mask", "gateway", "preferred_dns"?, "alternative_dns"? }
PUT /api/devices/{id}/network/wifi         body: { "ssid": "Guest", "passphrase": "…"?, "security": "wpa2PskTkip"? }
→ { "status": "ok", "message": "Applied. …" }
```

- **The writes do not read back.** A new interface, static address or Wi-Fi network can move the device to another IP, so the answer is only `{status: ok}`: wait, then `GET /network` (it may be on a new address; discovery will find it).
- A passphrase is accepted but never returned, by this API or the web page. A device's known networks are listed without it.
- `interfaces` never contains `none` (it would cut the device off), and `wireless` is refused with `422 invalid` when no Wi-Fi network is set up. A static setup is validated first (IPv4 addresses, contiguous mask, gateway inside the subnet); a bad one is `422 invalid` and nothing is sent.
- The device cannot scan for Wi-Fi networks and its known-networks list is session-protected (`Session ID is missing`), so joining means typing the name; forgetting a network is not offered.
- **Not yet applied on hardware.** The bodies come from the device's own error messages when probed with invalid values (they were rejected, nothing changed): `PUT BeoDevice/networkSettings/` takes `{"profile": {"networkSettings": {"activeInterface": …}}}`, `PUT …/wired` takes `{"wired": {dhcp, ipv4}}`, `PUT …/wireless` takes `{"wireless": {"activeNetwork": {ssid, passphrase, encryption, dhcp, configured}}}`. A device may still want more fields (Bluetooth did): the answer then names the missing key as a `502`.

## Wireless speakers (WiSA) — `wireless_speakers`

```
GET  /api/devices/{id}/wireless-speakers          → { "scan": "notInitiated", "scanning": false, "speakers": [ { "id": "…", "name": "…", "state": "…" } ] }
POST /api/devices/{id}/wireless-speakers/scan     body: { "action": "start" | "stop" }   → the new state
```

**Only the BeoSound Moment has the WiSA hardware.** Every ASE speaker answers these endpoints (the Essence even lists `SPEAKER_WIRELESS_SETUP`), so the driver can't tell: the model flag `wisa => true` in `config/devices.php` decides, matched on the device's *Product* (`device_product_type`). Any other model — Essence, V1, M3, M5 — does not list the capability and answers `422 unsupported`. A Moment whose Product is empty is not recognised: set it to "BeoSound Moment" on the device's edit page.

Unverified on hardware (the Moment was unreachable): the body of a scan (taken from the BeoNetRemote Postman collection), the scan states (anything but `notInitiated`/`stopped`/`done`/… counts as scanning) and the shape of a speaker entry (read from `id`/`address`/`name`/`friendlyName`/`state`/`status`).

## Wired speakers — `wired_speakers`

```
GET /api/devices/{id}/wired-speakers
→ { "speakers": [ { "id": "pl_1", "position": "left", "connected": true, "type": "Beolab 4", "types": ["None", "Beolab 1", … "Beolab Penta", "Beovox 1", "Beovox 2", "Other", "Line"], "sound": "none", "sounds": ["none", "localizationNoise"] }, { "id": "pl_2", "position": "right", … } ] }

PUT /api/devices/{id}/wired-speakers/{id}      body: { "type": "Beolab 4" }   and/or   { "sound": "none" }
→ the new state, as above
```

- `connected` is whether the device senses a speaker on the output. `types` is what the device lists; a value outside it is `422 invalid`. A write is read back, so a change the device ignores is a `502`.
- `sound: "localizationNoise"` plays a test noise on the output so you can tell which speaker it is. The web page never starts it; it only offers a Stop button when one was left on.
- **Which models:** the ones with external speakers, `speaker => 'external'` in `config/devices.php`: **BeoSound Essence and BeoSound Moment**. The Beoplay M3 and M5 have built-in speakers and no such outputs, but their firmware answers the same endpoint, so the model decides, not the API (`422 unsupported` for them). The device's *Product* must be set to the model name.
- The Essence reports an empty list of types (its own web UI still offers a choice), so the driver falls back to the 34 names a Moment lists. **Unverified on an Essence:** whether it accepts those names.
- **Verified on a Moment:** the body `PUT …/SpeakerWiredSetup/{id}` with `{"speaker": {"type": …}}` (a bad type: `Invalid speaker type`, a bad sound: `Invalid SpeakerSound`), and a real change of the left output to `Beolab 4`.

## ASE notes
- `BeoDevice/bluetoothSettings/deviceSettings` rejects partial bodies (`A string was expected. Key: deviceName`) and demands `bluetoothOn` although it is read-only, so the driver reads, merges and writes the whole object.
- `Sound/Adjustment` takes `{"adjustment": {bass, treble, loudness}}`.
- `PUT BeoDevice/networkSettings/…` bodies: see Network above.
- Only `MusicPlayerDriver` has these; the V1's `VideoPlayerDriver` has none of these resources (see `docs/architecture/ase-api-map.md`).

## Mozart and Sonos notes
- Mozart: a missing answer from the device is an error (`502`), never an empty list: the client returns `null` for a failed GET and the driver refuses to read that as "no devices". Bass/treble ranges are read from `GET /api/v1/sound/features` (first role that has the feature); writes are `PUT sound/settings/adjustments/{bass,treble,loudness}` with `{ "value": … }` and are not read back, so a `GET` straight after may still show the old value.
- Sonos: `RenderingControl` bass/treble (−10…10) and loudness; the name is the room name, firmware and MAC come from `DeviceProperties/GetZoneInfo` (left `null` if the speaker doesn't answer).
- The Mozart client additions (`SoundApi`, `ProductApi`, `BluetoothApi`) live in the `beo-mozart-php` git submodule, which needs its own commit.
