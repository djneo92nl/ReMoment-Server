# TV devices

How a TV (or set-top box) is modelled. A TV is a **media device with extra controls**, like the ASE `VideoPlayerDriver`: it plays things, has inputs and apps, takes remote keys, and sits in standby. The contracts below are what a TV driver implements; nothing here is new machinery for a speaker.

| What a TV does | Contract | Status |
|---|---|---|
| Switch inputs (HDMI 1-4, TV tuner, AV) and open apps | `SourcesInterface` + `SourceActivationInterface`. Activating a source also wakes a B&O TV from standby. An input or app is an `AvailableSource`: `sourceType` `HDMI` / `TV` / `APP` (or the platform's own), `input` = the physical connection ("HDMI 2"), `active` = selected. The API and pages need nothing new. | exists (ASE; Philips offers apps) |
| Control playback | `MediaControlsInterface` (play, pause, stop, next, previous), `SeekInterface` | exists |
| Volume, mute | `VolumeControlInterface` | exists |
| Standby (and wake where possible) | `PowerInterface` (`powerOn()` throws `UnsupportedOperationException` where a TV can't be woken) | exists |
| Number keys / channels | `DigitsInterface` | exists (ASE V1) |
| **Remote keys** (cursor, OK, back, home, menu, info, guide, transport, volume, channel, colour keys) | **`RemoteControlInterface`** with `RemoteKey` (`supportedKeys()`, `sendKey()`) | contract added, no driver yet |
| What is on now (an app, an input, a channel) | `NowPlaying::$source` (`Source` with `sourceType`, `category` `video`), as Sonos reports its TV input and the Philips listener its app in front | exists |

Capability string for the new contract: `remote_control` (`Capabilities::MAP`). Keys that a platform lacks are simply not in `supportedKeys()`; a driver maps each `RemoteKey` to its own key code.

## Not done yet (the TV phase)

1. ~~**Expose remote keys.**~~ Done: `GET/POST /api/devices/{id}/keys` (`RemoteKeysController`), `supported_keys` in the device detail, the MCP tool `remote_keys`, OpenAPI, and the remote card (`App\Livewire\RemoteCard`) on the device page. Original plan: A `POST /api/devices/{id}/keys` endpoint (`{ "key": "ok" }`, 422 for a key not in `supportedKeys()`), the OpenAPI entry, an MCP tool next to the others in `app/Mcp/Tools/` (`App\Mcp\ApiBridge` calls the API), and the device page (a remote card for devices with `remote_control`). Follow `GuardsDeviceAccess::withDriver()` like every device endpoint.
2. **Implement it per platform.** Philips is done (see [philips-tv.md](philips-tv.md); `RemoteKey::Source` was added for it). The ASE `VideoPlayerDriver` has no `RemoteControlInterface` yet: the one-way commands are *unverified on the V1 firmware* (probe plan in beovision-tv.md), so nothing is sent from guessed paths. Still to do there: the probe, then `supportedKeys()` from what answers.
   - *Philips Android TV* (`app/Integrations/Philips/`): `POST /6/input/key` already carries `Standby`; the same call takes the other key names (`CursorUp`, `Confirm`, `Back`, `Home`, `Play`, `VolumeUp`, ...). Map `RemoteKey` to those; `supportedKeys()` from what the firmware answers. HDMI inputs are not switchable through the API on current firmware; options to investigate are the `Source` key plus cursor/OK, and TV-input intents launched through `activities/launch`.
   - *BeoVision* (Bang & Olufsen TVs): designed in [beovision-tv.md](beovision-tv.md) from the Beoplay V1 scan. The V1 is an ASE video device that already has sources, volume, transport, standby, sleep timer and digits; remote keys are B&O's one-way commands (`POST BeoZone/Zone/Stream/Cursor/Select`, `Menu/Root`, `Generic/Red`, ...), not yet tried on hardware. Newer Mozart-platform BeoVision is still unlooked-at (read the Mozart REST spec first). `ConverterDigitTransport` is the precedent for keys sent through another device.
3. ~~**Inputs in the UI.**~~ Done for ASE TVs (input/apps grids, `input` label, signal dot, TV marker `VideoDeviceInterface`), see [beovision-tv.md](beovision-tv.md) "Phase 1: built". Philips has the marker but no HDMI inputs to show.
4. **Wake.** Solved for B&O TVs (a source wakes them). For Philips, Wake-on-LAN from the stored MAC or CEC through another device would let `powerOn()` work; neither exists.
