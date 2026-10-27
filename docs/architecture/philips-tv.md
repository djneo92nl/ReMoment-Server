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
| `RemoteControlInterface` | `sendKey()` posts the mapped Jointspace key name to `POST /6/input/key`; `supportedKeys()` is a static list (the firmware cannot list its keys). Map: arrows `CursorUp/Down/Left/Right`, `ok` `Confirm`, `back` `Back`, `home` `Home`, `menu` `Options`, `source` `Source`, `info` `Info`, `play`/`pause`/`stop`/`next`/`previous`/`fast_forward`/`rewind` the same names, `volume_up`/`volume_down`/`mute` `VolumeUp`/`VolumeDown`/`Mute`, `channel_up`/`channel_down` `ChannelStepUp`/`ChannelStepDown`, `red`/`green`/`yellow`/`blue` `RedColour` etc. Not mapped: `guide`, `exit`. A 404 from the TV is 422 `unsupported`. |
| `DigitsInterface` | `sendDigit(n)` presses `Digit0`..`Digit9` (the "Number keys" part of the TV card). |
| now playing | While the TV plays nothing over UPnP, the app in front (every 3 s) is reported as a `NowPlaying` with a `Source` (e.g. Netflix), the way Sonos reports its TV input; the launcher is "nothing playing". |

Not offered: switching **HDMI inputs** (the probe below: `/6/sources` is 404 on the lab TV; the fallbacks are the `Source` key then the cursor keys and OK, which the remote card can now send by hand, or a TV-input intent through `activities/launch`; neither is automated because the on-screen list differs per model and nothing is read back), waking the TV, Ambilight.

## Cast (what plays, read-only)

The lab TV has Chromecast built in: `cast:probe 192.168.1.105 [--media]` (connect, `GET_STATUS`, nothing launched) answered the running app (`displayName` Jellyfin, `appType` ANDROID_TV), `isActiveInput`, `isStandBy` and the volume. `Cast\CastNowPlaying` turns the receiver status plus the app's media status into a `NowPlaying`. The Philips listener asks it every 5 s while an app is in front: a **casted** session (YouTube, a phone casting) gives title, artist/series, artwork, state and position, shown under the app's own name; an app with no media session (Jellyfin native on Android TV: `media` came back empty) stays the bare app name as before. A TV that does not answer Cast is left alone for 5 minutes. Cast is not used to control anything. Restart `device:listen` to pick it up.

## Verified and not

Written from the protocol as the open clients document it and tested against a faked TV (`tests/Feature/PhilipsTvTest.php`). Confirmed on a real TV (a `2k15MTK`-platform Philips TV DMR, Jointspace v6): pairing, the apps as sources, and standby. Probed 2026-10-27 on a second TV (Jointspace 6.4, `MSAF_2019_P`, see Probe results): `POST /6/input/key` with `Info` answered 200 with the paired login (the one key sent; the on-screen effect was not checked and no other key was tried, so the mapping above is from the key list the open clients document). Not confirmed: launching each kind of app, every key of the map, and the active-app now-playing across firmware. On that TV the app in front while on an HDMI input or a TV channel is `org.droidtv.xtv`. Different firmware answers some calls differently (`powerstate` 404, `Standby` 404 over plain HTTP in reports); `JointspaceClient` turns a 404 into `UnsupportedOperationException` (API answer 422) and a 401 into a "pair it again" error. `/6/system` is open without a login; every control call needs the digest login.

## Probe results (2026-10-27, GET only)

A Philips Android TV found at 192.168.1.105 (`name` "televisie", Dutch menu, Netherlands), **Jointspace API 6.4.0**, `nettvversion` 9.0.0, `os_type` `MSAF_2019_P` (a 2019 MediaTek Android TV; model, serial and software version are only given encrypted in `/6/system`). Paired through `philips:pair` as device 36 (the TV answers `/6/system` with a login too). Pairing is the only write that was done; everything below is a GET with the digest login.

| Path | Status | Shape | What it means for us |
|---|---|---|---|
| `/6/system` | 200 (open) | `name`, `country`, `menulanguage`, `api_version`, `featuring.jsonfeatures` (`inputkey: ["key"]`, `activities: ["intent"]`, `applications: TV_Apps, TV_Games, TV_Settings`, `ambilight`, `channels: preset_string`, `pointer: not_available`, `textentry: not_available`), `featuring.systemfeatures` (`pairing_type digest_auth_pairing`, `tvsearch intent`) | The feature list names `key` input and `intent` activities only: **no source/input switching feature**. |
| `/6/sources`, `/6/sources/current`, `/6/sources/hdmi1`, `/6/input/source`, `/6/tv/sources` | **404** | HTML "Not Found" | This firmware has **no HDMI input list or switch** in Jointspace. |
| `/6/applications` | 200 | `{ version: 0, applications: [{ label, intent: { component: { packageName, className }, action }, order, id, type: "app" }] }`, 18 apps (YouTube, Netflix, Prime Video, Videoland, NPO, Jellyfin, Play Store, `org.droidtv.playtv` "TV", `org.droidtv.channels` "TV-gids", `org.droidtv.contentexplorer` "Media", ...) | What the driver already reads. Some apps have `action: "empty"`; the driver launches the stored intent as it is. No "HDMI 1" or "Source" entry is listed. |
| `/6/activities/current` | 200 | `{ component: { packageName: "org.droidtv.xtv", className: "NA" } }` | The app in front. `org.droidtv.xtv` is the TV's own UI layer (an HDMI input or a TV channel shows as this, not as its own app), so the driver should treat it as "no app" rather than an unknown one. |
| `/6/powerstate` | 200 | `{ "powerstate": "On" }` | Available on this firmware. |
| `/6/channeldb/tv` | 200 | `channelLists` (one, `all`, mixed sources) and `favoriteLists` 1-4; `/6/channeldb/tv/channelLists/all` lists `Channel[]` (`ccid`, `preset`, `name`, `type`, `serviceType`) | Channels exist; not needed for keys. |
| `/6/input/key` (GET) | 200, empty body | | A GET is harmless; only POST sends keys. Does not list the keys, so `supportedKeys()` is a static list. |
| `/6/input/pointer` | 200 | `{ "status": "disabled" }` | No pointer. |
| `/6/audio/volume` | 200 | `{ muted: false, current: 6, min: 0, max: 60 }` | Volume could be read and set through Jointspace; the UPnP RenderingControl already does it. |
| `/6/ambilight/mode`, `/6/ambilight/power` | 200 | `{ current: "internal" }`, `{ power: "Off" }` | Ambilight exists (not used). |
| `/6/context`, `/6/system/name`, `/6/recordings/list`, `/6/menuitems/settings/structure` | 200 | various | Not used. `context` answers `NA`. |
| `/6/applications/version` | 404 | | |

Consequences: remote keys go through `POST /6/input/key` (not yet tried on this TV, see below). HDMI switching has no API on this firmware; the fallbacks are the `Source` key then cursor and OK, or an intent through `activities/launch`.
