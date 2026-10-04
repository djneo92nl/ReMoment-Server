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
| Moment, NetworkLink/MasterLink converter | Not mapped yet (not reachable). The converter is visible through the V1, see below. |

### BeoPlay V1 (mapped in standby)
Mapped while in standby, so `Sound`, `Stream` and `List` answered 500: their feature lists and children are **not yet seen** (that is probably where the extra TV commands live). Re-map with the V1 awake.

Missing compared to the Essence: Bluetooth, `factoryReset`, `softwareUpdate`, `lineInSettings`, `credentials`, `BeoHome`, `remoteControlPairing`, wired network write, `regionalSettings` sub-resources, sound adjustment/mode/speaker setup. `networkSettings` is read-only and `productFriendlyName` is write-only (405 on GET).

Extra on the V1:
- `BeoContent/tv/dvbProfile/{channel,favoriteList}` (DVB channel list, favourites; empty when mapped) and `BeoContent/hdmi_5/stbProfile/favoriteList` (set-top box; 500 in standby).
- `BeoZone/Zone/Digits` — write-only (405 on GET), presumably digit key presses.
- Sources carry `recommendedIrMapping`, `signalSensed`, `linkable`, `contentProtection`; the source name is `tv:{jid}` / `hdmi_5:{jid}`.
- `BeoZone/System/Products`: every product the V1 knows, each with editable `integrate`, `borrowSource`, `irReroute` and modify/delete links.

### NetworkLink/MasterLink converter
The V1 lists it in `BeoZone/System/Products` as "BeoLink Converter NL/ML" (offline when mapped), with sources `RADIO`, `A.MEM`, `N.MUSIC`, `CD`, `A.AUX`, `N.RADIO` and `LINE IN`, each with a `recommendedIrMapping` (format 0 = BeoLink commands, 11 = IR). So its source model is the normal ASE one. Map the converter itself with `ase:map` once it is powered.
