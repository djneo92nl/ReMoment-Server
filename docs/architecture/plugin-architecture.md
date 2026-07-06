# Plugin Architecture for Device Integrations

## Context

Extract device integrations into loadable plugins so ReMomentServer only pulls in the drivers it needs. Users without B&O ASE won't get that code; Sonos-only users won't need ASE. Eventually every brand is an optional plugin. The B&O + Mozart packages are a natural first candidate.

---

## Proposed layer split

```
bangolufsen/ase          (pure PHP, no framework)
    HTTP connector to the ASE REST API
    All 7 Connectors/ traits
    AseDiscovery + MozartDiscoveryService

remoment/ase-driver      (depends on bangolufsen/ase + remoment contracts)
    MusicPlayerDriver  — implements ReMoment interfaces
    VideoPlayerDriver  — implements ReMoment interfaces
    DeviceListener     — fires ReMoment domain events

remoment/sonos-driver    (future — same pattern)
remoment/bose-driver     (future — same pattern)
```

ReMomentServer's `composer.json` requires only the driver packages it needs. Config in `config/devices.php` maps brand/model to the driver class; no driver package = that brand simply isn't available.

---

## Package 1: `bangolufsen/ase` — protocol only

**What moves in:**
- `app/Integrations/Common/HttpConnector.php`
- `app/Integrations/BangOlufsen/Common/MozartDiscoveryService.php`
- `app/Integrations/BangOlufsen/Ase/AseDiscovery.php`
- `app/Integrations/BangOlufsen/Ase/Connectors/` (all 7 traits)

**Coupling to resolve:**
- `AseDiscovery` uses `config()` helper → accept config array in constructor instead
- The connector traits reference `$this->httpConnector` (already set by the driver) → stays as-is; the package exposes a base class that sets it up

**Result:** Zero Laravel/framework dependencies. Anyone can `new MusicProtocol('192.168.1.10')` and call ASE endpoints.

**Effort: ~half a day** — mostly mechanical namespace moves.

---

## Package 2: `remoment/ase-driver` — ReMoment plugin

**What moves in:**
- `app/Integrations/BangOlufsen/Ase/MusicPlayerDriver.php`
- `app/Integrations/BangOlufsen/Ase/VideoPlayerDriver.php`
- `app/Integrations/BangOlufsen/Ase/Services/DeviceListener.php`
- A Laravel service provider to register the drivers

**Coupling to resolve (the real work):**
| Coupling | Currently | After |
|---|---|---|
| `App\Models\Device` injected into drivers | Eloquent model | Interface: `AseDeviceInterface` (id, ip, getMeta) |
| `App\Models\Media\Track` in LibraryPlayback | Eloquent model | Keep `LibraryPlaybackInterface` contract, pass url string or thin interface |
| `event()` helper in DeviceListener | Laravel global | Injected `EventDispatcher` (PSR-14 or simple interface) |
| `DeviceCache` / `Volume` static helpers | Wraps Laravel Cache | Injected PSR-16 cache |

ReMomentServer provides a **thin adapter** (`AseDeviceAdapter` wrapping the `Device` model) and wires everything via the service provider.

**Effort: ~1 day**

---

## Plugin structure in ReMomentServer

- Remove `BangOlufsen/` from the app source tree
- `composer.json`: `"remoment/ase-driver": "*"` (conditionally)
- `config/devices.php` remains unchanged — just references the moved class names
- Service provider auto-discovery registers the driver classes

**Effort: ~half a day**

---

## Total effort

| Task | Time |
|---|---|
| Package 1: extract protocol layer | ~0.5 day |
| Package 2: extract driver + adapter | ~1 day |
| Wiring + service provider + cleanup | ~0.5 day |
| **Total** | **~2 days** |

---

## Verification

- Existing behavior unchanged after extraction: play/pause/next/volume/sources/multiroom work on a B&O device
- Run `php artisan test` — integration tests pass
- Instantiate `bangolufsen/ase` in isolation (no Laravel bootstrap) and call a device endpoint
- Remove the package from `composer.json` and confirm the app starts cleanly (B&O simply absent from device list)
