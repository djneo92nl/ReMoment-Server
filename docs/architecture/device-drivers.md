# Device Drivers

ReMomentServer uses a driver pattern to support multiple device brands under a common interface. Adding a new brand means creating a driver class that implements the contracts relevant to that device's capabilities.

## Contracts

All contracts live in `app/Integrations/Contracts/`.

| Interface | Methods | Required? |
|-----------|---------|-----------|
| `MusicPlayerDriverInterface` | `__construct(Device $device)` (live playback comes from the listener via `DeviceCache`, not from the driver) | Yes, for all drivers |
| `MediaControlsInterface` | `play()`, `pause()`, `stop()`, `next()`, `previous()` | If device supports transport control |
| `VolumeControlInterface` | `setVolume(int): int`, `getVolume(): int`, `incrementVolume()`, `decrementVolume()`, `mute()`, `unmute()`, `isMuted(): bool` | If device supports volume |
| `SeekInterface` | `seek(int $seconds)` | If device can jump within the current track (Sonos, Spotify, Mozart) |
| `QueueInterface` | `getUpNext(int $limit = 20): QueueItem[]` — tracks after the current one; `[]` when not playing from a queue | If device exposes its play queue (Sonos, Spotify) |
| `ShuffleInterface` | `setShuffle(bool $shuffle)` | If device can switch shuffle with an explicit on/off (Sonos, Spotify, Mozart) |
| `RepeatInterface` | `setRepeat(RepeatMode $mode)` — `Off`, `All`, `One` | If device can set repeat explicitly (Sonos, Spotify, Mozart) |
| `LikeInterface` | `setLiked(bool $liked)` — like/save the playing track | If the service has a per-user library (Spotify) |
| `PowerInterface` | `powerOn()`, `standby()` | If device can be put in standby remotely (ASE, Mozart). `powerOn()` may throw `App\Integrations\Common\UnsupportedOperationException` when only standby works (Mozart) — the API answers 422 |
| `SoundAdjustmentInterface` | `getSoundAdjustment(): SoundAdjustment`, `setSoundAdjustment(?int $bass, ?int $treble, ?bool $loudness)` — throws `InvalidArgumentException` outside the device's range, `UnsupportedOperationException` for a missing part | If device has bass/treble/loudness (ASE, Mozart, Sonos) |
| `BluetoothInterface` | `getBluetooth(): BluetoothState`, `setBluetooth(?bool $discoverable, ?string $reconnectMode)`, `removeBluetoothDevice(string $id)` | If device has Bluetooth pairing (ASE). `BluetoothState::$writable` is false for a platform that can only list paired devices (Mozart's `setup/bluetooth/devices`) |
| `BatteryInterface` | `getBattery(): ?BatteryStatus` | Portable models report charge level and charging state (Mozart `GET /api/v1/battery`, Sonos `:1400/status/batterystatus`); null when this model has no battery. The live value comes from the listener's `BatteryUpdated` event, see CLAUDE.md |
| `SleepTimerInterface` | `getSleepTimer(): SleepTimer`, `setSleepTimer(int $minutes)` | Sleep timer, 0–60 min (ASE). `SleepTimer::$writable` is false on the BeoPlay V1, whose write answers 404; the device silently ignores out-of-range values, so the driver validates and reads back |
| `DigitsInterface` | `sendDigit(int 0-9)` | Number keys for a TV / set-top box source (ASE `VideoPlayerDriver`) |
| `DeviceInfoInterface` | `getDeviceInfo(): DeviceInfo`, `setDeviceName(string)` | If device reports/changes its name, model, firmware (ASE, Mozart, Sonos). `DeviceInfo::$renamable` is false where the platform can't rename (Sonos) |
| `NetworkSettingsInterface` | `getNetwork(): NetworkState`, `setActiveInterface(string)`, `setWiredAddress(bool $dhcp, array $ipv4)`, `joinWifi(string $ssid, ?string $passphrase, ?string $security)` — `InvalidArgumentException` for an interface/setup the device can't use | If device has network settings (ASE). The writes aren't read back: the device may move to a new address |
| `WirelessSpeakersInterface` | `getWirelessSpeakers(): WirelessSpeakersState`, `startWirelessScan()`, `stopWirelessScan()` | WiSA speaker setup (ASE driver, but only listed for models with the hardware: `HardwareFeatures` reads `wisa => true` from `config/devices.php`; only the BeoSound Moment) |
| `WiredSpeakersInterface` | `getWiredSpeakers(): WiredSpeakersState`, `setWiredSpeaker(string $id, ?string $type, ?string $sound)` — `InvalidArgumentException` for an unknown output/type/sound | Speaker type per wired output (ASE driver, but only listed for models with external speakers: `HardwareFeatures` reads `speaker => 'external'` from `config/devices.php`; Essence and Moment) |
| `SourcesInterface` | `getSources(): AvailableSource[]` | If device exposes a source list (ASE only) |
| `SourceActivationInterface` | `activateSource(string $sourceId)` | If device supports switching sources (ASE only) |
| `MultiRoomInterface` | `multiRoomMetaKey()`, `getMultiRoomId()`, `getJoinablePeerIds()`, `getCurrentPeerIds()`, `joinSession(Device $host)`, `leaveSession()` | If device supports multiroom grouping (ASE, Sonos) |
| `SessionHostInterface` | `addListener(string $peerId)` — the host side of a multiroom join: expand this device's experience to the peer with that ID. A guest joins by asking its host to do this | If device can take listeners (ASE: POST on `primaryExperience`; Mozart: `beolink/expand/{jid}`) |
| `LibraryPlaybackInterface` | `playLibraryTrack(Track $track)`, `playLibraryPlaylist(Playlist $playlist)` | If device can stream a local DLNA track/playlist |
| `RadioControlInterface` | `radioPlatform(): string`, `canPlayRadioStation(RadioStation $station): bool`, `playRadioStation(RadioStation $station)` | If device can tune radio stations |
| `DiscoveryInterface` | `discover(): DiscoveredDevice[]` | Implemented by a discovery service, not the driver itself |

A driver covers a whole platform, but some features need hardware only some models have (WiSA on the Moment). `App\Domain\Device\HardwareFeatures` reads such per-model flags from `config/devices.php`, matched on the device's product type; `DeviceCapabilities::for()` drops a capability the model lacks and the settings API answers `422` for it.

The API and UI check these interfaces to determine a device's capabilities at runtime — no separate capability configuration is needed. `App\Domain\Device\Capabilities::forDriver()` maps each contract to its REST capability string (`seek`, `queue`, …) from the driver *class name*, so listing devices never instantiates a driver or touches the network. The current shuffle/repeat/liked values are not read through the driver: the device's listener fires `PlaybackModesUpdated` with a `PlaybackModes` (`shuffle` ?bool, `repeat` ?`RepeatMode`, `liked` ?bool; null = unknown) whenever what it observes changes, which feeds `now_playing.modes` (cache `device:{id}:modes`) and the MQTT `/modes` topic. `MultiRoomInterface::getMultiRoomId()` also writes the platform ID (JID, UUID) to `device_meta` under the key returned by `multiRoomMetaKey()`, so devices can be looked up by peer ID later.

## Existing Drivers

### Bang & Olufsen ASE

**Path:** `app/Integrations/BangOlufsen/Ase/`

- `MusicPlayerDriver` — media controls, volume, sources, source activation, multiroom, radio. No seek or queue: the ASE API's play-queue/seek endpoints haven't been verified against hardware. No shuffle/repeat capability either: ASE only has toggle commands (`POST BeoZone/Zone/List/Shuffle` / `Repeat`, in `MediaControls`) and no known way to read the current state, so an explicit on/off can't be set. Power (both drivers, via `DeviceControls`): `PUT BeoDevice/powerManagement/standby` with `powerState` `standby`, or `on` to wake — the `on` value is unverified against hardware.
- `VideoPlayerDriver` — HDMI/video plus library playback

Communicates with the B&O ASE REST API on port 8080 via `HttpConnector`. Functionality is split into traits under `Connectors/` (e.g. `MediaControls`, `VolumeControls`, `MultiRoomControls`, `SourcesControls`). Real-time state updates arrive via a long-running HTTP stream handled by `app/Integrations/BangOlufsen/Ase/Services/DeviceListener.php`.

### Bang & Olufsen Mozart

**Path:** `packages/djneo92nl/beo-mozart-php` (protocol) + `packages/remoment/mozart-driver` (ReMoment driver), wired as local Composer path packages — see [Plugin Architecture](plugin-architecture.md).

Covers B&O's newer Mozart platform (Beoconnect Core, Beolab 8/28, Beosound 2 3rd gen/A5/A9 5th gen/Balance/Emerge/Level/Premiere/Theatre), distinct from the older ASE-generation products above.

- `djneo92nl/beo-mozart-php` — pure PHP REST client (`MozartClient`, `Api/{Playback,Sources,Volume,Power,Beolink}Api`) plus a hand-rolled WebSocket notification client (`WebSocket/NotificationClient`). Zero Laravel dependency; runs and tests standalone.
- `remoment/mozart-driver` — `MusicPlayerDriver` implements `MediaControlsInterface`, `VolumeControlInterface`, `SourcesInterface`, `SourceActivationInterface`, `MultiRoomInterface` (via B&O's "Beolink" JID system), `LibraryPlaybackInterface`, `SeekInterface` (`PUT /api/v1/playback/seek`), `ShuffleInterface`/`RepeatInterface` (`PUT /api/v1/playback/queue/settings` with `shuffle` / `repeat: none|all|track`; current values come from a WebSocket notification sniffed by its `{shuffle, repeat}` keys — unverified on hardware, so modes may stay `null` until changed through the API), `PowerInterface` (standby via `PUT /api/v1/state/standby`; the spec has no power-on endpoint, so `powerOn()` throws `UnsupportedOperationException`), and `RadioControlInterface` (reusing the `beoradio` `RadioStationMeta` key ASE already uses — TuneIn is no longer used anywhere in the B&O ecosystem). `Services/DeviceListener` connects to the notification WebSocket and fires the same `NowPlayingUpdated`/`ProgressUpdated`/`NowPlayingEnded`/`VolumeUpdated` events as ASE's listener. `MozartDiscovery` shares ASE's SSDP/UPnP discovery (`UpnpMediaRendererDiscovery`).

Two assumptions are unverifiable without physical hardware and are `config('mozart.*')`-overridable (`packages/remoment/mozart-driver/config/mozart.php`):
- **WebSocket port 9000** — the Mozart OpenAPI spec documents no connection info for real-time notifications at all; port 9000 is a community convention (B&O's official client libraries).
- **SSDP/UPnP discovery** — no Mozart-specific discovery mechanism is documented; `MozartDiscovery` assumes Mozart devices are UPnP MediaRenderers like ASE devices.

Discovery runs through `device:discover` (`MozartDiscovery` is registered under `discoverers`). Its listener (`Services/DeviceListener`, a `DeviceListenerInterface`) is registered under `listeners` in `config/devices.php` and run by `device:listen` / `device:listen-single {id}`.

### Sonos

**Path:** `app/Integrations/Sonos/`

Implements media controls, volume, radio, multiroom, library playback, seek, queue (`getUpNext()` reads the Sonos queue after the current track; empty while streaming radio or line-in), shuffle and repeat. Shuffle and repeat share one AVTransport `PlayMode` string (`NORMAL`, `REPEAT_ALL`, `REPEAT_ONE`, `SHUFFLE_NOREPEAT`, `SHUFFLE`, `SHUFFLE_REPEAT_ONE`), mapped by `Sonos\PlayMode` since duncan3dc/sonos has no repeat-one; the listener reads it on every poll. Uses the `duncan3dc/sonos` library (v3) for UPnP/SOAP communication. **Inputs:** `SourcesInterface` / `SourceActivationInterface` (`Connectors/SourceControls`) for soundbars and line-in speakers. `Sonos\SonosInputs` maps the model (`device_product_type`) to its inputs: `tv` for Ray/Beam/Arc/Playbar/Playbase/Amp (optical or HDMI eARC, always one "TV" input), `line_in` for Five/Play:5/Port/Connect/Amp; other models list none. Activating sets the transport URI to the speaker's own stream (`x-sonos-htastream:{uuid}:spdif`, `x-rincon-stream:{uuid}`) and plays; a grouped follower leaves its group first. The listener reports a track-less transport on one of these URIs as a `NowPlaying` with a `Source` (TV / Line-in), so clients get a source logo. Sonos soundbars also switch to TV by themselves when "TV autoplay" is on in the Sonos app.

### Spotify

**Path:** `app/Integrations/Spotify/`

Virtual device — implements `MediaControlsInterface`, `SeekInterface`, `QueueInterface` (`GET /v1/me/player/queue`), `ShuffleInterface`, `RepeatInterface` (`off`/`context`/`track`) and `LikeInterface` (saves the playing track to Liked Songs; needs the `user-library-modify` scope, requested on connect but not required, so older connections must reconnect to like). The listener reports `shuffle_state`/`repeat_state` from every poll and looks up whether a track is liked once per track (cached under `spotify:liked:{track id}`, which `setLiked()` overwrites); while playback is routed to a mapped local speaker, modes are published for that speaker (`PlaybackModesUpdated` with `routed: true`), and when routing ends or moves the speaker gets its own driver's last values back (`Modes::own()`, else all `null`). Polls the Spotify Web API rather than a local network device; see [Spotify Connect → Local Device Mapping](../../CLAUDE.md) in CLAUDE.md for how it routes playback to a physical device.

#### Spotify routing (one device)

`App\Domain\Device\SpotifyRouting` makes the routed speaker and the Spotify device one device:

- `driverFor(Device, contract)` — the driver that executes a contract for a device. For the routed speaker, the contracts in `DELEGATED_CONTRACTS` (`MediaControlsInterface`, `SeekInterface`, `QueueInterface`, `ShuffleInterface`, `RepeatInterface`, `LikeInterface`) resolve to the Spotify device's driver; everything else (volume, mute, power, sources, multiroom, radio, library) to the speaker's own. The API `DeviceController` and the `DeviceCard` Livewire component pick drivers through it, so the `instanceof` (422) check sees the merged set.
- `capabilities(Device)` — `Capabilities::forDriver()` plus Spotify's delegated capabilities while routed; used by `DeviceListResource` (and so every endpoint returning devices) and the web device page.
- `visible(Collection)` — drops the Spotify device from a list while the routed speaker is in it; used by `GET /api/devices`, the client endpoints and `/devices`.
- `modesDeviceId(Device)` — where an API mode change is recorded (the routed speaker, also when requested on the Spotify device).
- Modes: `HoldOwnModesWhileSpotifyRouted` (first `PlaybackModesUpdated` listener) keeps each device's own reports in `Modes::own()` and holds them back while Spotify is routed to it; the Spotify listener's `moveModesTo()` calls `restoreOwnModes()` for the previous speaker when routing ends or moves, and clears the Spotify device's modes when playback moves onto a speaker.

The Spotify listener's per-poll logic is `DeviceListener::poll(int $spotifyDeviceId, SpotifyWebAPI $api)`, so it can be tested with a mocked API client (`tests/Feature/SpotifyRoutingTest.php`).

## Where console commands live

Generic commands sit under `app/Console/Commands/{Device,Library,Artwork,Easter}`, one folder per domain. A command that only makes sense for one brand lives in that driver (`app/Integrations/<Brand>/Console/`, e.g. `ase:map`, `spotify:sync-library`) and is registered by an `<Brand>ServiceProvider` (`$this->commands([...])` under `runningInConsole()`) listed in `bootstrap/providers.php`. Discovery is not a per-brand command: implement `DiscoveryInterface` and register it under `discoverers` in `config/devices.php`, and `device:discover` picks it up.

## Adding a New Driver

### 1. Create the driver class

```php
namespace App\Integrations\YourBrand;

use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;

class MusicPlayerDriver implements MusicPlayerDriverInterface, MediaControlsInterface, VolumeControlInterface
{
    public function __construct(public Device $device) {}

    public function play(): void   { /* call device API */ }
    public function pause(): void  { /* call device API */ }
    public function stop(): void   { /* call device API */ }
    public function next(): void   { /* call device API */ }
    public function previous(): void { /* call device API */ }

    public function getVolume(): int         { return 0; }
    public function setVolume(int $v): int   { return $v; }
    public function incrementVolume(): void  {}
    public function decrementVolume(): void  {}
    public function mute(): void   {}
    public function unmute(): void {}
    public function isMuted(): bool { return false; }
}
```

Only implement the interfaces the device actually supports — the API advertises capabilities based on `instanceof` checks, no other configuration is needed.

### 2. Register the driver in `config/devices.php`

```php
'Your Brand' => [
    'Model Name' => [
        'driver_name' => 'PROTOCOL',   // short identifier shown in UI/API
        'driver'      => \App\Integrations\YourBrand\MusicPlayerDriver::class,
    ],
],
```

### 3. Create a device listener (optional)

If the device can push real-time state notifications, create a listener service (e.g. `app/Integrations/{Brand}/Services/DeviceListener.php`) that parses the device's notification format and fires the domain events documented in CLAUDE.md's "Event-Driven Core" section (`NowPlayingUpdated`, `ProgressUpdated`, `NowPlayingEnded`). The event listeners take care of updating the cache, storing history, and publishing to MQTT.

### 4. Create a discovery service (optional)

If the device is discoverable on the network, implement `DiscoveryInterface::discover()` to return `DiscoveredDevice` value objects without persisting them, then add a console command under `app/Console/Commands/` that upserts a `Device` row from the results. See `app/Console/Commands/DeviceDiscovery.php` (UPnP/SSDP) or `SonosDeviceDiscovery.php` for examples, and [Device Discovery](device-discovery.md) for the full discovery flow.

## `HttpConnector`

A lightweight cURL wrapper for JSON APIs, at `app/Integrations/Common/HttpConnector.php`:

```php
$client = new HttpConnector('192.168.1.10:8080');

$client->get('path/to/resource');
$client->post('path/to/resource', ['key' => 'value']);
$client->put('path/to/resource', ['key' => 'value']);
$client->delete('path/to/resource');
```

It sets `Content-Type: application/json`, decodes JSON responses, and has a 10s timeout. `get()` swallows exceptions and returns `[]` on failure; `post`/`put`/`delete` throw `RuntimeException` on a cURL error.

### Default input on wake

`device_meta` `default_source` (set on `/devices/{id}/settings`, "Input") names a source id. `Listeners\Device\SwitchToDefaultSource` (queued, on `DeviceStateChanged`) activates it when a device wakes (standby/unreachable to playing/paused) while no track or radio station is playing, and skips Sonos when it already plays that input. Playback someone started is never overridden.
