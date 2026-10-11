# B&O ASE API map

What the ASE REST API (port 8080) offers beyond what `app/Integrations/BangOlufsen/Ase` uses today. The API is self-describing: responses carry `_links` (`/relation/modify`, `/relation/create`, `/relation/delete`, …) and `_capabilities` (`editable`, `value` enums, `range`), so most writes can be read off a resource.

Everything below was read with GET only. **Writes are inferred from `_capabilities`/`_links`, not yet tried on hardware** — verify each on a spare device before building on it.

## `php artisan ase:map`

```bash
php artisan ase:map 192.168.1.25                     # or a device id
php artisan ase:map 3 --compare=storage/app/ase-maps/192.168.1.25-beosound-essence-mk2.json
php artisan ase:map 192.168.1.40 --seed=BeoZone/Zone/Video   # extra paths for devices with more areas
```

`App\Integrations\BangOlufsen\Ase\Services\ApiMapper` does the work; the command prints a table and writes `storage/app/ase-maps/{ip}-{product}.json` (gitignored).

- GET-only. Starts from the root `services` list plus the zone areas (`BeoZone/Zone` answers with an empty feature list, so the areas are named in `DEFAULT_SEEDS`), follows every `_links.*.href`, and skips `BeoNotify` (a stream) and `Ping`.
- A zone area that only lists `features` is probed for children derived from the names: `SPEAKER_WIRELESS_SETUP` → `Sound/SpeakerWirelessSetup`, `SOUND_ADJUSTMENT` → `Sound/Adjustment`. A feature whose path doesn't follow that rule needs `--seed`.
- 404s are left out of the map. A 405 means the path exists but only takes writes (recorded as write-only).
- Members of a list (favourites, paired devices, …) are collapsed after 3 per template (`{id}` for numeric/hex/url-encoded segments); the summary says how many were skipped.
- Bodies under `credentials`/`Sessions` are not stored, and keys that look like secrets (`password`, `token`, `psk`, …) are replaced by `[redacted]`. Serial numbers and MAC addresses are kept, so don't share a map file unreviewed.
- `--compare` lists the paths only on one device, ids normalised.
- The NetworkLink/MasterLink converter can be mapped the same way if it serves the same root `services` document; otherwise pass its paths with `--seed`.

## Resources (Essence Mk2, firmware 6.7; M3/M5 identical unless noted)

### Bluetooth — `BeoDevice/bluetoothSettings/`
- `deviceSettings`: `deviceName`, `bluetoothOn` (read-only), `discoverabilityMode` (`notDiscoverable` | `discoverable`), `alwaysOpen`, `reconnectMode` (`manual` | `automatic` | `disabled`) — the last four editable via PUT.
- `device`: list of paired phones (empty when none; no delete link seen yet).
- Pairing a phone should be: PUT `discoverabilityMode: discoverable`, then read `device`.
- `BeoDevice/remoteControlPairing/` is separate: BeoRemotes (`settings.pairingMode`, `pairingFilter`, `suppressNotifications`, `paired[]` with delete links, max 5).

### Write bodies learned by probing
The device validates before it acts, and its error messages name the key it wants, so invalid values reveal the body without changing anything:
- `PUT BeoDevice/networkSettings/` → `{"profile": {"networkSettings": {"activeInterface": "wired"}}}` (a wrong value: `Invalid active interface value`).
- `PUT …/networkSettings/wired` → `{"wired": {"dhcp": bool, "ipv4": {…}}}`; `PUT …/networkSettings/wireless` → `{"wireless": {"activeNetwork": {"ssid": …}}}`.
- `POST`/`PUT …/wireless/networks` → `Method not allowed` / `Session ID is missing`: the known-networks list needs a `BeoSecurity` session; not used.
- `PUT BeoDevice/bluetoothSettings/deviceSettings` needs every field, including the read-only `bluetoothOn`.

### Network — `BeoDevice/networkSettings/`
- `activeInterface`: `none` | `wired` | `wireless`.
- `interfaces.wired`: `status`, `dhcp`, `ipv4.{address,subnetMask,defaultGateway,preferredDns,alternativeDns}` (all editable).
- `interfaces.wireless`: `status`, `activeNetwork`; `wireless/networks` is a create target — fields `configured`, `encryption`, `passphrase`, `dhcp`, `ipv4.*` (exact body unverified).
- `BeoDevice/powerManagement/wifiPowerSave` exists on M3/M5 only.

### Wireless speakers (WiSA) — `BeoZone/Zone/Sound/SpeakerWirelessSetup`
- `scan`: `notInitiated` | start/stop (editable), `speaker[]`, `standbyMode`, persist link. **Only the BeoSound Moment has the WiSA radio**: the endpoint exists on every ASE speaker (the Essence even lists `SPEAKER_WIRELESS_SETUP`), so ReMoment gates it on the model (`wisa => true`), not on the API.
- `SpeakerWiredSetup`: outputs `pl_1`, `pl_2` (`type`, `sound`: `none` | `localizationNoise`).
- `SpeakerDplSetup` and `SpeakerTestMode` are listed as features but answer 404 / 501.

### Sound — `BeoZone/Zone/Sound/`
- `Adjustment`: `bass`, `treble` (−10…10, step 1), `loudness`; `/reset` relation.
- `Mode` (active id), `SpeakerGroup` (active id) — already used by `VolumeControls`.
- `BufferSetup`: net radio `bufferTime` (0, 2–3, 5–20, 30–60 s).
- `Volume/Speaker/{Range,DefaultLevel}`, `ContinuousLevelAction`.

### Device
`BeoDevice` (friendly name editable), `lineInSettings` (`sensitivity`: high/medium/low/disabled), `regionalSettings` (country, region, language, dateTimeMode), `powerManagement` (`standby`, `idleTimeout`, `playTimeout`, `sleepTimer`), `softwareUpdate` (`mode`, `state`), `logReporting`, `modulesInformation`, `association/weak`, `termsAndConditions`.
Destructive, not planned for the UI: `factoryReset`, `softwareUpdate` triggers.

### Other areas
`BeoZone/Zone/Light` (feature only), `Stream`, `List`, `Stand`, `Snapshot`, `PlayQueue`, `BeoHome/trigger` (`timerList`, `triggerSequenceList`), `BeoContent/radio/netRadioProfile` (favourites, presets), `BeoSecurity`.

## Per-model differences
| Device | Result |
|---|---|
| Essence Mk2 (`BEOSOUND_ESSENCE_MK2`, fw 6.7) | Base map, above (84 endpoints) |
| M3 (`CA17`), M5 (`CA16`) | + `wifiPowerSave`, + wireless network entry; M5 has `tunein_account` credentials instead of deezer |
| BeoPlay V1 (`BEOPLAY_V1`, fw 1.0.11) | Much older and thinner API (29 endpoints), see below |
| BeoSound Moment (`BEOSOUND_MOMENT_SOUNDHEART`, fw 2.2) | 213 endpoints, see below |
| NetworkLink/MasterLink converter | Not mapped yet (not reachable). It is visible through the V1, see below. |

### BeoSound Moment (`BEOSOUND_MOMENT_SOUNDHEART`)
Mapped over wired Ethernet. The product type the device reports is `BEOSOUND_MOMENT_SOUNDHEART`; ReMoment's model name for it is "BeoSound Moment" (the `device_product_type` / `config/devices.php` key that `HardwareFeatures` matches on).

- **WiSA looks the same as on the Essence.** `SpeakerWirelessSetup` answers `scan: notInitiated`, `standbyMode: disconnect`, `speaker: []`, `scan` editable with `start`/`stop`. Nothing on the API tells the Moment apart: the Essence lists `SPEAKER_WIRELESS_SETUP` too. The speaker entries are still unseen (none paired or scanned), so the shape of a found speaker stays unverified.
- Its `Sound` features have no `SPEAKER_GROUP`, `SPEAKER_DPL_SETUP` or `SPEAKER_TEST_MODE` (the Essence has them), so no `Sound/SpeakerGroup` or `SpeakerTestMode`.
- **`SpeakerWiredSetup`** has two power-link outputs `pl_1` (left) / `pl_2` (right), `sense: true`, and an editable `type` (the connected speaker: `None`, `Beolab 1` … `Beolab 20`, `Beolab Penta`, `Beovox 1/2`, `Other`, `Line`) and `sound` (`none` | `localizationNoise`). A candidate for a settings capability.
- **DLNA on the device:** `BeoDevice/dlnaSettings` lists the DLNA servers the Moment sees (`id` = url-encoded UDN, `friendlyName`, `status: available`, `selected` — editable) plus `expertMode` and `browseForArtistImages`. `BeoContent/music/dlnaProfile/{album,artist,genre,playList,track}` is the device's own copy of the selected server's library, as paged lists (`offset`, `count`, `total`, `revision`). **All of them are empty (`total: 0`) while no server is `selected`**: selecting one makes the device harvest that server's library (`harvestSettings` has the per-service `action`: `none` | `harvest`, `reset` for TuneIn). Selecting is a write that triggers a full library import on the device and the server, so it has not been done; the item shapes are therefore unseen.
- **Mood wheel:** `BeoContent/music/moodWheelProfile/moodWheelItem` is a paged list (`total: 100`, 50 per page) of `{ "id": "0-65338", "ring": "core", "mood": { "name": "Hopeful / Breezy", "graceNote": { "id": 65338 } } }` — Gracenote mood ids, with a `/relation/track` link per mood (`…/{id}/track`, a `trackList`). Every mood's `trackList` is empty (`total: 0`) while no DLNA server is selected, so the moods are presumably matched against the harvested library's tracks. `ring` was only `core` on the first page; the other 50 items were not read. `…/moodWheelItem/track` without an id is `400 Invalid Mood Id`.
- **Deezer** appears as `BeoContent/music/deezerProfile/…` (charts, genres, albums) whether or not an account is set up: it is a built-in content profile, not a sign of a login. A crawl of it is huge (3000+ list members), so the mapper collapses any all-digit path segment to `{id}`.
- Missing compared to the Essence: `remoteControlPairing`, the per-service `credentials/credential/*` entries, `List/Repeat`, `List/Shuffle`, `BeoHome`-less radio favourites paths (`netRadioProfile/favoriteList/…`).

### BeoPlay V1 (mapped in standby and again powered on)
Re-mapped with `powerState: on` (HDMI "SET-TOP BOX" the active source): the map is **identical** to the standby one, still 29 endpoints. So `Sound`, `Stream` and `List` are not a standby effect: they answer `500 basic_string::substr` (a firmware bug, presumably on a missing path/parameter), and `hdmi_5/stbProfile/favoriteList` answers `500 PUC favourite channel lists not available`. The V1 offers nothing for sound adjustment, speaker setup or queue/shuffle/repeat on this firmware (1.0.11).

Readable but not used by the driver: `powerManagement/sleepTimer` (`{ duration: 0 }`, read-only: the write answers `404 Not implemented`; on newer devices it is editable, 0–60, and an out-of-range write is ignored with 200), `regionalSettings` (date/time, time zone, country, language), `dvbProfile/channel` and `favoriteList` (empty, no tuner configured), `Zone/Snapshot` (empty list).

Missing compared to the Essence: Bluetooth, `factoryReset`, `softwareUpdate`, `lineInSettings`, `credentials`, `BeoHome`, `remoteControlPairing`, wired network write, `regionalSettings` sub-resources, sound adjustment/mode/speaker setup. `networkSettings` is read-only and `productFriendlyName` is write-only (405 on GET).

Extra on the V1:
- `BeoContent/tv/dvbProfile/{channel,favoriteList}` (DVB channel list, favourites; empty when mapped) and `BeoContent/hdmi_5/stbProfile/favoriteList` (set-top box; 500 in standby).
- `BeoZone/Zone/Digits` — write-only (405 on GET, `Allow: POST`). Verified on the V1: `POST {"digits": N}` with N an integer 0–9 answers `200` with an empty body (one digit per call); 10+ gives `400 INVALID_ARGUMENT "Digit is out of range"`, a string or negative gives `400 CONVERSION_ERROR "An integer was expected. Key: digits"`. The effect on a set-top box source wasn't observed (no signal sensed); a channel number is presumably sent as consecutive digits.
- Sources carry `recommendedIrMapping`, `signalSensed`, `linkable`, `contentProtection`; the source name is `tv:{jid}` / `hdmi_5:{jid}`.
- `BeoZone/System/Products`: every product the V1 knows, each with editable `integrate`, `borrowSource`, `irReroute` and modify/delete links.

### NetworkLink/MasterLink converter
The V1 lists it in `BeoZone/System/Products` as "BeoLink Converter NL/ML" (offline when mapped), with sources `RADIO`, `A.MEM`, `N.MUSIC`, `CD`, `A.AUX`, `N.RADIO` and `LINE IN`, each with a `recommendedIrMapping` (format 0 = BeoLink commands, 11 = IR). So its source model is the normal ASE one. Map the converter itself with `ase:map` once it is powered.

**Mapped on hardware** (BeoLink Converter NL/ML, fw 1.4.3, `ase:map` against its port 8080): `BeoDevice` (+ `Peripherals`, `logReporting`, `powerManagement` with `standby` / `sleepTimer`), `BeoSecurity`, `BeoZone/System/Products`, `BeoZone/Zone/ActiveSources`, `Sources`, write-only `Digits`; `List`, `Sound` and `Stream` answer 500, `PlayQueue`, `Snapshot` and `Stand` 501. The converter's `ActiveSources` takes a **POST** (PUT is 405); `activeSources.primary` stays empty on the converter itself.

What was learned controlling it (the converter is only a bridge to the Master Link master, here a BeoSound Ouverture):
- Requests to the converter are accepted (`200`, empty body) and relayed to the ML bus, but their effect depends on the ML master being on and answering. `ActiveSourceType` accepts any type (an unknown one falls back to "B&O Radio").
- `POST BeoZone/Zone/Digits` on the converter becomes an internal "IR command" (digit 1 → 6, 2 → 7, then 54) for ML product 193 that the tuner does not act on. **Digits and presets work when sent to an ASE player that borrowed the converter's source**, which forwards real Beo4 key codes (see `docs/api/source-controls.md`, `ConverterDigitTransport`).
- Borrowing: on the player, `BeoZone/System/Products` lists the converter with editable `borrowSource` / `borrowPriority`; `PUT BeoZone/System/Products/{jid}` with `{"products": {"borrowSource": true}}` (a bare `{"borrowSource": true}` is `400 Illegal structure … Key: products`). The player then lists the converter's sources in `BeoZone/Zone/Sources` with `borrowed: true`; `POST BeoZone/Zone/ActiveSources` with the converter's source id starts the stream (`NL:Connect` in the converter's log).
- Multiroom (verified): while the Master Link master plays a source, the converter's `ActiveSources` has `primaryJid` = its own jid and a `primaryExperience.listenerList` whose `_capabilities.value["listener.jid"]` lists the joinable players. `POST BeoZone/Zone/ActiveSources/primaryExperience {"listener": {"jid": "<player jid>"}}` on the **converter** adds the player (it then reports the converter's source as its primary); `DELETE …/ActiveSources/primaryExperience/?jid=<player jid>` (the listener's `/relation/delete` link) removes it, the player goes idle and the tuner keeps playing. A player's own `stop` only drops it from the listener list, it does not stop the tuner. `ConverterDriver::addListener()` does the POST.
- The other way does not work: the converter is not in a player's joinable list (`listener.jid` values), and adding it as a listener on a player (`POST …/primaryExperience` with the converter's jid) answers 200 but changes nothing. A "join" key on a Beo4 only makes the Master Link master select A.AUX; Master Link has no IP session to join. So a player joins the converter's source, never the other way round.
- Power: `PUT BeoDevice/powerManagement/standby` accepts `standby` and `allStandby` (no `on`). `standby` is what the converter already reports and changes nothing; `allStandby` flips the reported `powerState` to `allStandby` but nothing on the Master Link system (Ouverture) or the Network Link players visibly reacted when tested. The converter's own log shows `Sending All Standby to link` only for a standby key pressed on a real remote. `ConverterDriver` therefore has no power control.
- Borrowed sound: when a player borrowed the converter's source and plays it, the converter sends the audio to that player (`Setting ML sound switch to ML->NL`). With the Master Link master (Ouverture) **in standby** its own speakers stay silent and only the player plays; with the Ouverture **on**, both play. The converter's `SOURCE` / `SOURCE_EXPERIENCE_CHANGED` notifications carry a `listener` list (the player's jid, and the converter's own jid while the Ouverture itself plays), which tells the two apart: `ConverterListener` reports it as `now_playing.source.playsLocally` (own jid listed → `true`, only players → `false`; the converter's jid comes from the device's `converter_jid` meta, else the source's product jid). Captured: `[converter]` alone, `[Moment, converter]` with the Moment joined, `[converter]` again after the Moment's stop; the standby case (`[Moment]` only) is inferred from the converter's log (`Local Jid removed as listener`), not captured.
- The converter pushes `VOLUME` (a `speaker.level` 0–90 that its own log says is not absolute: "ML doesn't support absolute volume"), `SOURCE`, `SOURCE_EXPERIENCE_CHANGED`, `NOW_PLAYING_NET_RADIO` (when a Network Link stream such as the M5's B&O Radio plays out on the Master Link speakers via a video-master key like TV: station, `liveDescription` with the song, image), `PROGRESS_INFORMATION` and `NOW_PLAYING_LEGACY {"trackNumber": N}` (about once a second for the Ouverture's own sources: the tuner preset, or the CD/A.MEM track). `BeoDevice` reports `proxyMasterLinkType: BEOSOUND_3000` for the master. See `device-drivers.md`, `ConverterDriver`.
- The BeoSound Moment's volume range is 20–50 (minimum not editable), so a level below 20 is `400 Volume is out of bounds`.
- The converter's own Master Link options matter: with the master (Ouverture) on the wrong option the converter's log shows `Getting experience from ML did timeout` and nothing reaches the tuner (fixed by option 2, one IR receiver in the room). Debug views: the converter's web UI system log, and `curl -N http://{ip}:8080/BeoNotify/Notifications` (`NOW_PLAYING_LEGACY.trackNumber` is the tuner preset).
