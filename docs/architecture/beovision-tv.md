# BeoVision / TV controls (design)

Design for controlling a Bang & Olufsen TV from ReMoment, starting from what the **BeoPlay V1** (`BEOPLAY_V1`, fw 1.0.11, mapped with `ase:map`, `storage/app/ase-maps/192.168.1.79-beoplay-v1.json`) offers. The generic TV model (contracts, capability strings) is in [tv-devices.md](tv-devices.md); this document is the BeoVision-specific part and the screen design. **Phase 1 (the TV page on existing capabilities) is built**, see "Phase 1: built"; the remote keys, wake probe and other TVs are not.

Status legend: **verified** = seen in the scan or on hardware, **docs** = in B&O's published one-way-command documentation but not tried on our devices, **unverified** = a guess to test.

## Scope

| Family | API | Status |
|---|---|---|
| BeoPlay V1 (a BeoVision-style video unit) | ASE REST on `:8080`, `Ase\VideoPlayerDriver` | the only one looked at |
| Newer BeoVision on the Mozart platform | Mozart REST (`beo-mozart-php`) | unverified, check its OpenAPI spec for sources, standby and any key endpoint before designing |
| Older Master Link BeoVision | no IP API; via an MLGW/BLGW gateway | out of scope (see `masterlink-gateway.md`) |

The first iteration targets ASE video devices, because that driver exists and a real device is at hand.

## What the V1 offers

`VideoPlayerDriver` already implements the following, so a TV page can be built on it without new API:

| Need | Call | Capability | Status |
|---|---|---|---|
| Inputs and apps | `GET BeoZone/Zone/Sources`, `POST BeoZone/Zone/ActiveSources` | `source_control`, `source_activation` | exists |
| Volume, mute | volume connector | `volume_control` | exists |
| Play / pause / next / previous | `POST BeoZone/Zone/Stream/{Play,Pause,Forward,Backward}` (405 on GET) | `media_controls` | exists |
| Standby | `PUT BeoDevice/powerManagement/standby` (`standby`, `allStandby`; no `on`) | `power` | exists, no wake |
| Sleep timer | `BeoDevice/powerManagement/sleepTimer` | `sleep_timer` | exists (V1 read-only) |
| Number keys | `POST BeoZone/Zone/Digits {digits: 0-9}` | `digits` | exists |
| Cursor, menu, colour keys | one-way commands, below | `remote_control` | **new** |

### Sources on the V1 (verified)

| id | label | `sourceType` | category | note |
|---|---|---|---|---|
| `tv` | TV TUNER | `TV` | TV | DVB; `BeoContent/tv/dvbProfile/channel` is an empty list |
| `dvb_radio` | RADIO | `RADIO` | RADIO | |
| `hdmi_1` | SET-TOP BOX | `HDMI`, connector 1, `externalSource.categoryList` `SET-TOP BOX` | TV | label is user-chosen |
| `hdmi_2`, `hdmi_4` | HDMI 2, HDMI 4 | `HDMI` | TV | |
| `hdmi_3` | SPELCONSOLE | `HDMI`, `GAMING CONSOLE` | TV | |
| `hdmi_5` | Amino Generic (All models) | `HDMI`, `stbProfile` | TV | `favoriteList` answers `500 PUC favourite channel lists not available` |
| `av` | AV | `AV IN` | TV | |
| `webmedia`, `youtube`, `homemedia` | WEBMEDIA, YOUTUBE, HOMEMEDIA | same name | TV / MEDIA | app-like |
| `netradio`, `deezer` | NETRADIO, DEEZER | | RADIO / MEDIA | streaming, not TV |

Each source has `signalSensed` (per-source signal flag) and `recommendedIrMapping` (Beo4 format 0 and IR format 11 codes, e.g. TV 128, HDMI 206). `inUse` is not "selected" (see `docs/ux/sources.md`).

## Remote keys: B&O one-way commands (docs)

The key endpoint is the one the converter transport already uses for `Forward`/`Backward`:

```
POST http://{ip}:8080/BeoZone/Zone/Stream/{command}
body (optional): { "toBeReleased": false }
```

`toBeReleased: true` is for a continuous command (hold a key); it is released with a POST to the sub-resource `Release`. Commands in B&O's documentation:

- `Cursor/Select|Up|Down|Left|Right|Exit|Back|PageUp|PageDown|Clear`
- `Stream/Play|Stop|Pause|Wind|Rewind|Forward|Backward`
- `List/StepUp|StepDown|PreviousElement|Shuffle|Repeat`
- `Menu/Root|Option|Setup|Contents|Favorites|ElectronicProgramGuide|VideoOnDemand|Text|HbbTV|HomeControl`
- `Device/Information|Eject|TogglePower|Languages|Subtitles|OneWayJoin|Mots`
- `Record/Record`
- `Generic/Blue|Red|Green|Yellow`

**Unverified on the V1**: the firmware is old and thin (29 endpoints), so which of these it accepts, and what a set-top box or the TV does with them, must be tested on hardware (probe plan below). `Stream/Play|Pause|Forward|Backward` exist (405 on GET) and `Digits` works (200).

### Mapping `RemoteKey` to commands

The contract is `RemoteControlInterface` with `RemoteKey` (`app/Domain/Device/RemoteKey.php`). The ASE video driver maps each key to a command and lists only the keys it can send in `supportedKeys()`; volume and mute stay `VolumeControlInterface` (a volume key is not sent as a command), digits stay `DigitsInterface`.

| `RemoteKey` | Command | Note |
|---|---|---|
| `up` `down` `left` `right` | `Cursor/Up` ... | |
| `ok` | `Cursor/Select` | |
| `back` | `Cursor/Back` | |
| `exit` | `Cursor/Exit` | |
| `menu` | `Menu/Root` | |
| `home` | none | B&O has no home key; to decide: hide, or alias of `Menu/Root` |
| `info` | `Device/Information` | |
| `guide` | `Menu/ElectronicProgramGuide` | |
| `play` `pause` `stop` | `Stream/Play` ... | same as `media_controls` |
| `next` `previous` | `Stream/Forward`, `Stream/Backward` | on the tuner this steps the channel, as it steps the preset on the converter |
| `fast_forward` `rewind` | `Stream/Wind`, `Stream/Rewind` | |
| `channel_up` `channel_down` | `List/StepUp`, `List/StepDown` | unverified which of these B&O's tuner treats as channel |
| `red` `green` `yellow` `blue` | `Generic/Red` ... | |
| `volume_up` `volume_down` `mute` | not sent as a command | use the volume endpoints; leave out of `supportedKeys()` on ASE |

Decisions to take when this is built:

1. **Enum additions** the B&O set has and `RemoteKey` lacks: `option`, `setup`, `contents`, `favorites`, `text`, `subtitles`, `languages`, `record`, `page_up`, `page_down`, `clear`, `toggle_power`. Add only those the screens use; the API answers 422 for the rest.
2. **Per-model allow-list**: start with the full map, trim `supportedKeys()` to what the hardware answers 200 to.
3. **Hold-to-repeat**: an optional `hold` flag (`toBeReleased: true` then `Release`) for cursor and wind keys. Not in the first version; a client just sends repeated presses.

## Screens

Reference: the three-panel mockup (Devices, TV detail, Remote). Rule for all of it: **a section exists only when the device reports its capability** (`GET /api/devices/{id}`), never by brand. A TV is a media device with extra controls; there is no TV device class.

### 1. Devices list

A TV tile sits next to the speakers with the same state colours (green playing/on, orange standby, red unreachable). Status line: state, then `now_playing.source.input` ("HDMI 2"), then the source or app name. When the V1 knows nothing more than the source, the line is `On · SET-TOP BOX`; **never invent a title**. The tile icon is a TV when the device has a video source (`now_playing.type = video`, or a driver for video).

### 2. TV detail

Order of cards, each shown per capability:

1. Header: name, model, state, power button (`power`; standby only on the V1, so "on" is disabled with the reason "can't be woken remotely" until a wake path exists).
2. Now playing hero: the source tile (`artwork.kind = source`, the generated TV/HDMI logo) with the source label and input badge; title and episode only when a driver reports them (Philips can; the V1 cannot).
3. Transport (`media_controls`) and volume with mute (`volume_control`).
4. **Input** (`source_activation`): the visible non-app sources as buttons, label plus `input` badge; selected = outlined and bold, availability = dot from `signalSensed` (`docs/ux/sources.md` rules 1-6 apply: server order, hidden sources stay in settings).
5. **Apps**: visible sources of type `WEBMEDIA`, `YOUTUBE`, `HOMEMEDIA`. Streaming services (Deezer, Tidal, Spotify, net radio) stay out, as rule 7 of `docs/ux/sources.md` says.
6. **Also**: sleep timer (`sleep_timer`), number pad (`digits`) and the link to device settings.

The Input and Apps split is a grouping of `GET /api/devices/{id}/sources` by `source_type` in the view; no new API field.

### 3. Remote

Only for `remote_control`: direction pad with OK, Back / Home / Menu (keys missing from `supportedKeys()` are not drawn), colour keys, Info and Guide, channel up/down, and the app launcher row from step 5. Reuses the sources already loaded; no extra call. On the web this is a card on the TV detail page (Livewire, same pattern as the `digits` section in `DeviceSettings`), on small clients its own screen.

## API, MCP, docs

Follows `tv-devices.md`, "Not done yet" 1:

- `POST /api/devices/{id}/keys` `{ "key": "ok" }` through `GuardsDeviceAccess::withDriver()`; `422 unsupported` for a key not in `supportedKeys()`, `503` unreachable, `502 driver_error`. Add the `remote_control` entry and the path to `docs/api/openapi.yaml`.
- An MCP tool in `app/Mcp/Tools/` via `ApiBridge`, and a row in `docs/api/mcp.md`.
- `docs/api/` gets a short `remote-keys.md` once the endpoint exists.

## Probe plan (needs someone at the TV; state-changing, so run on request only)

A checklist or an `ase:` command that POSTs each command on the V1 with the set-top box source active, and records the status and the visible effect. Order: harmless first (`Cursor/Up`, `Cursor/Select`, `Menu/Root`), then transport, then colour keys, then `Device/*`. Read a `400` message before a retry: the device names the key it wants (see `ase-api-map.md`). Questions the probe must answer:

1. Which commands return 200, which 400/404/501?
2. Do cursor keys reach a **set-top box** (HDMI 1 via the PUC) or only the TV's own UI?
3. Does `Digits` change channels on a real STB?
4. ~~Does `Device/TogglePower` wake the V1?~~ Not needed: a source wakes it.
5. Is the `active` flag from `ActiveSources` reliable, or must clients match `now_playing.source`?

## Wake

Verified on the V1: **sending a source turns the TV on.** `powerManagement/standby` accepts only `standby` and `allStandby`, so the ASE `VideoPlayerDriver::powerOn()` activates a source instead: the device's default input (`device_meta` `default_source`, the same key `SwitchToDefaultSource` uses), else the first visible own source that is not a streaming service. `PUT /api/devices/{id}/power {"on": true}` and the "Power on" button on the device card both go through it; a TV with no synced sources answers 422 ("sync the sources first"). `Device/TogglePower` stays an untested alternative and is no longer needed for wake.

## Chromecast on HDMI 1

The V1's `hdmi_1` ("SET-TOP BOX", a name the owner gave the input; the V1 reports `externalSource.categoryList` `SET-TOP BOX`) has a **Chromecast dongle** plugged in. That makes it the first sub device ([v1-roadmap.md](v1-roadmap.md), workstream 2a): the V1 is the host, the link is the HDMI input (`kind` `input`, `via` `hdmi_1`), and the Chromecast is its own device with the Cast driver (`app/Integrations/Cast/`, `cast:probe`).

What the V1 gives the link:
- **Selecting it:** activating `hdmi_1` switches the TV to the dongle, and wakes the TV when it is in standby (so "cast to the living room" can power the TV on).
- **Knowing it is active:** `active` on `hdmi_1` (reliability on the V1 is probe question 5) and the `source_changed` notification the listener already turns into `now_playing.source` with `input` "HDMI 1". `signal` is the V1's `signalSensed`; the scan found it false on this input with the TV in standby, so do not treat a missing signal as "dongle off" before it is checked with the TV on.
- **Remote keys:** the cursor keys of the one-way commands go to the TV; whether they reach a Chromecast over HDMI-CEC is a probe question (the Chromecast has its own remote protocol, a cast session needs none).

**Casting wakes the TV (the follow rule).** The V1 has no CEC, so a cast to the dongle does not wake the TV by itself. ReMoment can do it: the Chromecast's listener sees the cast session start and the link's follow rule activates `hdmi_1` on the V1, which wakes it and switches the input (design in the roadmap, 2a "Follow"). Defaults: only from standby (a cast never takes the screen from another input), the idle backdrop app is ignored, a short debounce, and an optional standby after the cast ends only when ReMoment woke the TV.

Things to settle with the hardware:
1. **Power of the dongle.** If the Chromecast is powered from the V1's USB port it goes off in standby and no cast can be seen; it needs its own mains adapter. Which is it on the V1? (`cast:probe` from the server with the V1 in standby answers it.)
2. **What the Chromecast reports** to a CASTV2 connection while its HDMI is dark: whether the receiver status (app id, playing state) arrives in time for the rule, and which app id the backdrop uses.
3. **Wake latency**: how long the V1 takes from `hdmi_1` activation until the TV shows the input, to pick the debounce and tell the user what to expect.
4. **Our own switch** versus someone else's: the listener must not treat the `source_changed` caused by the rule as a manual change.

Until the link model exists the pair is two ordinary devices; nothing in phase 1 depends on it.

## Phases

1. **TV page on what exists** (built, below): grouping of sources (Input / Apps), the TV status line, standby, wake by source, digits and sleep timer. No new endpoint.
2. **`/keys` endpoint** with the ASE key map and `RemoteControlInterface` on `VideoPlayerDriver`: transport and colour keys first, then cursor keys as the probe confirms them; the remote card; MCP tool.
3. ~~Wake~~ done in phase 1 (source activation).
4. **Other TVs**: newer Mozart BeoVision (read the Mozart OpenAPI spec first) and Philips (`philips-tv.md`) use the same screens through the same contracts.

## Unverified, summarised

Which one-way commands the V1 accepts and their effect; whether keys reach a set-top box; channel list (empty on the V1: `BeoContent/tv/dvbProfile/channel`), so channel names and favourites are not designed; every newer BeoVision.

## Phase 1: built

- **TV detection**: a driver implementing the marker `Contracts\VideoDeviceInterface` (ASE `VideoPlayerDriver`, Philips `VideoPlayerDriver`). It adds no method; what the TV can do is still told by the other contracts. (Capabilities in the API are unchanged; clients choose their own layout from them.)
- **Sources** (`DeviceSourceManager`, `partials/tv-source-grid`): for a TV the inline card shows **Input** and **Apps** as tiles (`Domain\Device\TvSources::group()`: inputs = HDMI / TV / AV, apps = WEBMEDIA / YOUTUBE / HOMEMEDIA / APP; streaming and radio stay out of the grids, the Manage modal still lists them). A tile: active = outlined, `input` badge, signal dot. Hidden/order rules are the household's, as before.
- **`input` and `signal`** on a source: `Domain\Device\SourceInput::label()` gives "HDMI 2" from `sourceType` + `connector` ("AV" for AV IN; the tuner has none, its label says it). `AvailableSource::$signal` is the device's `signalSensed`. Both are in `GET /api/devices/{id}/sources` (`input`, `signal`; OpenAPI updated), and `now_playing.source.input` is added for the status line ("On · HDMI 2 · Netflix"); the device card shows it instead of "HDMI · 1".
- **Wake**: above; a "Power on" button on a standby card for any `PowerInterface` device (Mozart and Philips answer that they cannot be woken).
- **Also** (`Livewire\TvExtras`, `livewire/tv-extras`): number keys (`digits`) and the sleep timer (`sleep_timer`; read-only where the firmware cannot write, as on the V1), each only with its capability.
- Tests: `tests/Feature/TvPageTest.php`, `tests/Unit/TvSourcesTest.php`, `tests/Support/FakeTvDriver.php`.
