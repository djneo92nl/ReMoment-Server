# Device Drivers

ReMomentServer uses a driver pattern to support multiple device brands under a common interface. Adding a new brand means creating a driver class that implements the contracts relevant to that device's capabilities.

## Contracts

All contracts live in `app/Integrations/Contracts/`.

| Interface | Methods | Required? |
|-----------|---------|-----------|
| `MusicPlayerDriverInterface` | `__construct(Device $device)`, `getCurrentPlayingAttribute()` | Yes, for all drivers |
| `MediaControlsInterface` | `play()`, `pause()`, `stop()`, `next()`, `previous()` | If device supports transport control |
| `VolumeControlInterface` | `setVolume(int): int`, `getVolume(): int`, `incrementVolume()`, `decrementVolume()`, `mute()`, `unmute()` | If device supports volume |
| `SourcesInterface` | `getSources(): AvailableSource[]` | If device exposes a source list (ASE only) |
| `SourceActivationInterface` | `activateSource(string $sourceId)` | If device supports switching sources (ASE only) |
| `MultiRoomInterface` | `multiRoomMetaKey()`, `getMultiRoomId()`, `getJoinablePeerIds()`, `getCurrentPeerIds()`, `joinSession(Device $host)`, `leaveSession()` | If device supports multiroom grouping (ASE, Sonos) |
| `LibraryPlaybackInterface` | `playLibraryTrack(Track $track)`, `playLibraryPlaylist(Playlist $playlist)` | If device can stream a local DLNA track/playlist |
| `RadioControlInterface` | `radioPlatform(): string`, `canPlayRadioStation(RadioStation $station): bool`, `playRadioStation(RadioStation $station)` | If device can tune radio stations |
| `DiscoveryInterface` | `discover(): DiscoveredDevice[]` | Implemented by a discovery service, not the driver itself |

The API and UI check `instanceof` against these interfaces to determine a device's capabilities at runtime — no separate capability configuration is needed. `MultiRoomInterface::getMultiRoomId()` also writes the platform ID (JID, UUID) to `device_meta` under the key returned by `multiRoomMetaKey()`, so devices can be looked up by peer ID later.

## Existing Drivers

### Bang & Olufsen ASE

**Path:** `app/Integrations/BangOlufsen/Ase/`

- `MusicPlayerDriver` — implements all interfaces above (media controls, volume, sources, source activation, multiroom, library playback, radio)
- `VideoPlayerDriver` — HDMI/video plus library playback

Communicates with the B&O ASE REST API on port 8080 via `HttpConnector`. Functionality is split into traits under `Connectors/` (e.g. `MediaControls`, `VolumeControls`, `MultiRoomControls`, `SourcesControls`). Real-time state updates arrive via a long-running HTTP stream handled by `app/Integrations/BangOlufsen/Ase/Services/DeviceListener.php`.

### Sonos

**Path:** `app/Integrations/Sonos/`

Implements media controls, volume, radio, multiroom, and library playback. Uses the `duncan3dc/sonos` library (local fork at `packages/duncan3dc/sonos`, branch `laravel12`) for UPnP/SOAP communication.

### Spotify

**Path:** `app/Integrations/Spotify/`

Virtual device — implements only `MediaControlsInterface`. Polls the Spotify Web API rather than a local network device; see [Spotify Connect → Local Device Mapping](../../CLAUDE.md) in CLAUDE.md for how it routes playback to a physical device.

## Adding a New Driver

### 1. Create the driver class

```php
namespace App\Integrations\YourBrand;

use App\Domain\Device\DeviceCache;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;

class MusicPlayerDriver implements MusicPlayerDriverInterface, MediaControlsInterface, VolumeControlInterface
{
    public function __construct(public Device $device) {}

    public function getCurrentPlayingAttribute()
    {
        return DeviceCache::getNowPlaying($this->device->id)?->toArray() ?? [];
    }

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
