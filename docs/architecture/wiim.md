# WiiM (LinkPlay) driver

Workstream 11. **Status: built from the vendor document, tested with fakes, unverified on hardware** (no WiiM in the device lab; `config/hardware-status.php` says `spec`). Code: `app/Integrations/Wiim/`.

Source: "HTTP API for WiiM Products", version 1.2 ([PDF](https://www.wiimhome.com/pdf/HTTP%20API%20for%20WiiM%20Products.pdf), read 2026-11-15). Fixtures in `tests/Fixtures/Wiim/` are that document's example answers, not device recordings.

## Shape

`MusicPlayerDriver` extends the generic UPnP driver (like Philips Streamium): what the **server** plays on the WiiM (library queue, radio, Spotify items, stream URLs) goes over UPnP with the server-managed queue. The WiiM's own HTTP API (`WiimClient`, `GET https://{ip}/httpapi.asp?command=...`, no login, certificate not verified for this host) adds the rest:

| Contract | How |
|---|---|
| `VolumeControlInterface`, `SeekInterface` | always the HTTP API (`vol:`, `mute:`, `seek:` in seconds); the discoverer adds `seek` and `volume_control` to the stored UPnP capabilities |
| `MediaControlsInterface` | queue-aware: while the server started what plays (`ours()`: library track, radio or Spotify item cached for the device) the UPnP/queue logic answers; otherwise `pause`/`resume`/`stop`/`next`/`prev` go to the WiiM |
| `ShuffleInterface`, `RepeatInterface` | same split; on the WiiM one `loopmode:n` carries both (see quirks) |
| `SourcesInterface`, `SourceActivationInterface` | `switchmode:` inputs (`line-in`, `bluetooth`, `optical`, `udisk`, `wifi`) and presets `preset:{n}` (`getPresetInfo`, played by `MCUKeyShortClick:n`); the active input is the `mode` of `getPlayerStatus` |
| `DeviceInfoInterface` | `getStatusEx` (`DeviceName`, `firmware`, `MAC`); not renamable (no command) |
| `PowerInterface` | standby only (`setShutdown:0`); no wake in the document |
| `BatteryInterface` | `battery` / `battery_percent` of `getStatusEx`, null when `battery` is 0; the charging flag is not in the document (reported false). The `battery` capability shows once the listener reported one |
| Equaliser | `eqPresets()`, `setEqPreset($name)`, `setEqEnabled()` on the driver (`EQGetList`, `EQLoad`, `EQOn/Off`). **No contract or capability**: named presets are not bass/treble, so `SoundAdjustmentInterface` does not fit |

Not built, because the document has no commands: multiroom (only the read-only `group`/`type` fields), a queue of the WiiM's own, a per-model list of sources, rename, wake. Audio output mode (`getNewAudioOutputHardwareMode`, `setAudioOutputHardwareMode`) and alarms are in the document and not wired (no contract fits yet); `wiim:probe` reads the output mode.

## Listener

`Services\DeviceListener` extends the UPnP listener (1 s UPnP poll, backoff when unreachable). Every 3 s it reads `getPlayerStatus` for volume and mute; while nothing of the server's plays over UPnP it reports what the WiiM plays itself from `getPlayerStatus` + `getMetaInfo` (title, artist, album, art, `mode` as the source: AirPlay, DLNA, Spotify Connect, TIDAL Connect, line in, Bluetooth, optical ...), the loop mode as shuffle/repeat, and progress. A group member (`type` 1, `mode` 99) is not reported: its leader plays it. The battery is read every 60 s.

## Discovery

`WiimDiscovery` (registered before the generic UPnP discoverer) searches MediaRenderers (multicast, plus given hosts by unicast) and keeps those whose descriptor manufacturer contains `linkplay` or `wiim`, or whose model starts with `WiiM`. `DedicatedDrivers::claims()` honours the `manufacturers` list of the models in `config/devices.php`, so the generic driver no longer reports them. **Unverified:** what a real WiiM puts in `manufacturer` and `modelName` (Linkplay is a guess from the platform); an unlisted model falls back to the `WiiM Mini` definition and keeps its reported product name. A WiiM missed by discovery can be added by hand with brand WiiM.

## Quirks handled

- `curpos` and `totlen` are **ms**, `seek` takes **seconds**; both can be 0 for a stream.
- Reported `loop`: 0 all, 1 single, 2 shuffle loop, 3 shuffle no loop, 4 none. Settable `loopmode`: 0 none, 1 single, 2 shuffle loop, -1 sequence loop. Shuffle with repeat off sends 3, which the document lists only as a reported value (**unverified** that it can be set).
- Keys in the document's examples have stray spaces (`" status"`, `"albumArtURI "`): the client trims keys.
- Colons in commands stay raw, other characters are escaped (`EQLoad:Bass%20Booster`).
- Control commands answer plain `OK`, EQ/shutdown `{"status":"OK"}`; anything else is an error.

## Probe

`php artisan wiim:probe {ip} [--out=]` asks only read commands (`getStatusEx`, `getPlayerStatus`, `getMetaInfo`, `getPresetInfo`, `EQGetStat`, `EQGetList`, `getShutdown`, `getNewAudioOutputHardwareMode`) and writes the raw answers to `storage/app/wiim-probes/`. The file holds the device's MAC and network details: look it over before sending. Compare it with `tests/Fixtures/Wiim/` and replace the fixtures.

## Still to verify on a device

Certificate and HTTPS on current firmware; manufacturer/model strings; whether UPnP playback of the server's queue and the native status agree during a handover; shuffle-without-repeat code; battery models and charging flag; whether `setShutdown:0` leaves the device reachable.
