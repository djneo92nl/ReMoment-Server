# Device Drivers

ReMomentServer uses a driver pattern to support multiple device brands under a common interface. Adding a new brand means creating a driver class that implements the relevant contracts.

---

## Contracts

All contracts live in `app/Integrations/Contracts/`.

### `MusicPlayerDriverInterface`

Required for all drivers. Provides current playback state.

```php
public function __construct(Device $device);
public function getCurrentPlayingAttribute(): array;
```

`getCurrentPlayingAttribute` should return the result of `DeviceCache::getNowPlaying($device->id)?->toArray() ?? []`. The cache is kept fresh by the device listener — the driver itself does not need to poll the device.

### `MediaControlsInterface`

Implement this to enable transport control (play, pause, skip).

```php
public function play(): void;
public function pause(): void;
public function stop(): void;
public function next(): void;
public function previous(): void;
```

### `VolumeControlInterface`

Implement this to enable volume control.

```php
public function getVolume(): int;
public function setVolume(int $volume): int;  // returns actual volume set
public function incrementVolume(): void;
public function decrementVolume(): void;
public function mute(): void;
public function unmute(): void;
```

---

## Existing Drivers

### Bang & Olufsen ASE

**Path**: `app/Integrations/BangOlufsen/Ase/MusicPlayerDriver.php`

Communicates with the B&O ASE REST API on port 8080 via a custom `HttpConnector`. Functionality is split into traits:

| Trait | File | Provides |
|-------|------|---------|
| `DeviceControls` | `Connectors/DeviceControls.php` | `standby()` |
| `MediaControls` | `Connectors/MediaControls.php` | Transport commands |
| `VolumeControls` | `Connectors/VolumeControls.php` | Volume commands (with cache) |

Device API endpoints used:

| Action | Method | Endpoint |
|--------|--------|----------|
| Play | POST | `BeoZone/Zone/Stream/Play` |
| Pause | POST | `BeoZone/Zone/Stream/Pause` |
| Stop | POST | `BeoZone/Zone/Stream/Stop` |
| Next | POST | `BeoZone/Zone/Stream/Forward` |
| Previous | POST | `BeoZone/Zone/Stream/Rewind` |
| Get volume | GET | `BeoZone/Zone/Sound/Volume/Speaker/Level` |
| Set volume | PUT | `BeoZone/Zone/Sound/Volume/Speaker/Level` |
| Mute | PUT | `BeoZone/Zone/Sound/Volume/Speaker/Muted` |
| Standby | PUT | `BeoDevice/powerManagement/standby` |

Real-time state updates arrive via a long-running HTTP stream handled by `app/Integrations/BangOlufsen/Ase/Services/DeviceListener.php`.

### Sonos

**Path**: `app/Integrations/Sonos/MusicPlayerDriver.php`

Uses the `duncan3dc/sonos` library (local fork at `packages/duncan3dc/sonos`, branch `laravel12`). Device discovery uses `Sonos\Network::getControllerByIp($device->ip_address)`.

---

## Adding a New Driver

### 1. Create the driver class

Create `app/Integrations/{Brand}/MusicPlayerDriver.php`:

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

    public function getCurrentPlayingAttribute(): array
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

Only implement the interfaces that the device actually supports. The API will advertise capabilities based on `instanceof` checks — no other configuration is needed.

### 2. Register the driver in `config/devices.php`

```php
'Your Brand' => [
    'Model Name' => [
        'driver_name' => 'PROTOCOL',   // short identifier shown in UI/API
        'driver'      => \App\Integrations\YourBrand\MusicPlayerDriver::class,
        'speaker'     => 'internal',   // or 'external'
    ],
],
```

### 3. Create a device listener (optional)

If the device can push real-time state notifications, create a listener service at `app/Integrations/{Brand}/Services/DeviceListener.php`. Its job is to receive notifications and fire the appropriate events:

```php
NowPlayingUpdated::dispatch($deviceId, $nowPlaying, $sourceType);
ProgressUpdated::dispatch($deviceId, $progress);
NowPlayingEnded::dispatch($deviceId);
VolumeUpdated::dispatch($deviceId, $volume);
```

The event system takes care of updating the cache, storing history, and publishing to MQTT — the listener only needs to parse the device's notification format and fire the event.

### 4. Create a discovery command (optional)

If the device is discoverable on the network, create a console command at `app/Console/Commands/` and register it in `routes/console.php`. See `app/Console/Commands/DeviceDiscovery.php` (UPnP/SSDP) or `app/Console/Commands/SonosDeviceDiscovery.php` for examples.

Discovery commands should upsert a `Device` model with `ip_address`, `device_brand_name`, `device_product_type`, `device_name`, `device_driver`, `device_driver_name`, and `last_seen`. Store any device-specific identifiers (e.g. UPnP UUID, JID) in the `device_meta` table.

---

## `HttpConnector`

A lightweight cURL wrapper for JSON APIs is available at `app/Integrations/Common/HttpConnector.php`:

```php
$client = new HttpConnector('192.168.1.10:8080');

$client->get('path/to/resource');
$client->post('path/to/resource', ['key' => 'value']);
$client->put('path/to/resource', ['key' => 'value']);
$client->delete('path/to/resource');
```

It automatically sets `Content-Type: application/json` and decodes JSON responses.
