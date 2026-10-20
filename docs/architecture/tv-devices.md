# TV devices

How a TV (or set-top box) is modelled. A TV is a **media device with extra controls**, like the ASE `VideoPlayerDriver`: it plays things, has inputs and apps, takes remote keys, and sits in standby. The contracts below are what a TV driver implements; nothing here is new machinery for a speaker.

| What a TV does | Contract | Status |
|---|---|---|
| Switch inputs (HDMI 1-4, TV tuner, AV) and open apps | `SourcesInterface` + `SourceActivationInterface`. An input or app is an `AvailableSource`: `sourceType` `HDMI` / `TV` / `APP` (or the platform's own), `input` = the physical connection ("HDMI 2"), `active` = selected. The API and pages need nothing new. | exists (ASE; Philips offers apps) |
| Control playback | `MediaControlsInterface` (play, pause, stop, next, previous), `SeekInterface` | exists |
| Volume, mute | `VolumeControlInterface` | exists |
| Standby (and wake where possible) | `PowerInterface` (`powerOn()` throws `UnsupportedOperationException` where a TV can't be woken) | exists |
| Number keys / channels | `DigitsInterface` | exists (ASE V1) |
| **Remote keys** (cursor, OK, back, home, menu, info, guide, transport, volume, channel, colour keys) | **`RemoteControlInterface`** with `RemoteKey` (`supportedKeys()`, `sendKey()`) | contract added, no driver yet |
| What is on now (an app, an input, a channel) | `NowPlaying::$source` (`Source` with `sourceType`, `category` `video`), as Sonos reports its TV input and the Philips listener its app in front | exists |

Capability string for the new contract: `remote_control` (`Capabilities::MAP`). Keys that a platform lacks are simply not in `supportedKeys()`; a driver maps each `RemoteKey` to its own key code.

## Not done yet (the TV phase)

1. **Expose remote keys.** A `POST /api/devices/{id}/keys` endpoint (`{ "key": "ok" }`, 422 for a key not in `supportedKeys()`), the OpenAPI entry, an MCP tool next to the others in `app/Mcp/Tools/` (`App\Mcp\ApiBridge` calls the API), and the device page (a remote card for devices with `remote_control`). Follow `GuardsDeviceAccess::withDriver()` like every device endpoint.
2. **Implement it per platform.**
   - *Philips Android TV* (`app/Integrations/Philips/`): `POST /6/input/key` already carries `Standby`; the same call takes the other key names (`CursorUp`, `Confirm`, `Back`, `Home`, `Play`, `VolumeUp`, ...). Map `RemoteKey` to those; `supportedKeys()` from what the firmware answers. HDMI inputs are not switchable through the API on current firmware; options to investigate are the `Source` key plus cursor/OK, and TV-input intents launched through `activities/launch`.
   - *BeoVision* (Bang & Olufsen TVs): not looked at yet. Newer ones are on the Mozart platform (see the Mozart driver and its REST spec for sources, standby and any key/remote endpoint), older ones behind the ASE/BeoLink APIs. Find out first which of sources, power and remote keys their API offers, then implement the same contracts; `ConverterDigitTransport` (digits through a player that borrowed the converter) is the precedent for keys sent through another device.
3. **Inputs in the UI.** The device page's source list already works for any `SourcesInterface`; check that HDMI sources get sensible names, icons and the `input` label, and that the "default input on wake" setting (`SwitchToDefaultSource`) works for a TV that can only be woken by other means.
4. **Wake.** Wake-on-LAN from the stored MAC or CEC through another device would let `powerOn()` work; neither exists.
