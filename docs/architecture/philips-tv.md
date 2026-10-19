# Philips Android TV (Jointspace) driver

**Path:** `app/Integrations/Philips/`. A video device like the ASE `VideoPlayerDriver`: a paired Philips Android TV is the generic [UPnP renderer](upnp-renderer.md) (playback, server queue, volume, seek, library playback, polling listener) **plus** what the TV's own HTTP API adds: standby, its apps as sources, and the app in front as the playing source. `Philips\VideoPlayerDriver` extends `Upnp\MusicPlayerDriver`; `Philips\Services\DeviceListener` extends the UPnP listener.

## Opt-in pairing

An unpaired Philips TV is an ordinary generic UPnP device (discovery does not claim it: the config brand is `Philips TV`, which neither the manufacturer `Philips` nor the model `Philips TV DMR` matches). Pairing switches it to this driver:

```bash
docker exec -it remomentserver-laravel.test-1 php artisan philips:pair {device id}
```

The TV has to be on. It shows a PIN; the command asks for it. Run it in the container, not on the host: the database is SQLite on a bind mount and must only be written from one side.

`Pairing` (Jointspace v6): `POST https://{ip}:1926/6/pair/request` (scope read/write/control and a device object with a random 16-character id) answers `auth_key` and `timestamp`; `POST .../pair/grant` carries the PIN and `auth_signature` = base64 of the **hex** HMAC-SHA1 of `timestamp + PIN`, keyed with the signing key every open client of this API uses, digest-authenticated with the device id and `auth_key`. Those two become the login of every later call and are stored as `philips_user` / `philips_password` (device meta); the device's `device_driver` becomes the Philips driver. The TV's certificate is self-signed, so verification is off.

## What it adds

| Contract | How |
|---|---|
| `PowerInterface` | `standby()` presses `Standby` (`POST /6/input/key`). `powerOn()` is unsupported: these TVs cannot be woken over the network. |
| `SourcesInterface` / `SourceActivationInterface` | The installed apps (`GET /6/applications`, cached 10 min) as sources (`sourceType` `APP`, category `video`, id = package); activating launches the app's intent (`POST /6/activities/launch`). `active` from `GET /6/activities/current`. |
| now playing | While the TV plays nothing over UPnP, the app in front (every 3 s) is reported as a `NowPlaying` with a `Source` (e.g. Netflix), the way Sonos reports its TV input; the launcher is "nothing playing". |

Not offered: switching **HDMI inputs** (the API has no call for it on current firmware; clients use key presses or TV-input intents that differ per model), waking the TV, Ambilight.

## Verified and not

Written from the protocol as the open clients document it and tested against a faked TV (`tests/Feature/PhilipsTvTest.php`). Confirmed on a real TV (a `2k15MTK`-platform Philips TV DMR, Jointspace v6): pairing, the apps as sources, and standby. Not confirmed: launching each kind of app, and the active-app now-playing across firmware. Different firmware answers some calls differently (`powerstate` 404, `Standby` 404 over plain HTTP in reports); `JointspaceClient` turns a 404 into `UnsupportedOperationException` (API answer 422) and a 401 into a "pair it again" error. `/6/system` is open without a login; every control call needs the digest login.
