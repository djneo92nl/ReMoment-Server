# Philips Streamium HiFi player (MCi900) driver

**Path:** `app/Integrations/Philips/Streamium/`. The generic [UPnP renderer](upnp-renderer.md) (library playback, server queue, volume, seek, polling listener) **plus** what the unit's own phone web remote (port 8889) adds: power, the ten internet-radio presets as sources, and the radio playing as now-playing. `Streamium\MusicPlayerDriver` extends `Upnp\MusicPlayerDriver`; `Streamium\Services\DeviceListener` extends the UPnP listener, the same shape as the [Philips TV](philips-tv.md) driver. The web remote's endpoints, formats and quirks are in the MCi900 section of upnp-renderer.md; this page is what the driver does with them.

## Enabling

Opt-in, like the TV: the config brand is `Philips Streamium` / `Wi-Fi Hi-Fi System`, which matches neither the renderer's manufacturer (`Royal Philips Electronics`) nor its model (`MCi`), so discovery still treats the unit as a generic UPnP renderer (it is also not advertised over SSDP: add it by `http://{ip}:7123/`, see upnp-renderer.md). Then:

```bash
php artisan streamium:enable {device id} [--url=http://192.168.3.33:8889]
```

checks that the web remote answers (`/HOMESTATUS`), stores `streamium_remote_url` in the device's meta and sets the driver. Restart the listeners (`device:listen`) afterwards.

## What it adds

| Capability | How |
|---|---|
| `power` | `standby()` is the web remote's `POST /CTRL$STANDBY` (a toggle that never answers). The unit has **no readable power state** (every endpoint keeps answering and counting), so the server remembers what it sent (`streamium:{id}:asleep`): `powerOn()` toggles only after the server put the unit to standby, otherwise it answers 422 (a toggle on an awake unit would put it to sleep). A unit put to standby by its own remote is unknown to the server. |
| `source_control`, `source_activation` | The inputs **AUX** (`aux`, `GET /aux`) and **Disc** (`disc`, `GET /dvd`), and the radio presets, `preset:{id}`, read from `/nav$03$02$001$0` (cached 10 min) and played by the address the page itself uses (`/nav$03$03$00N$1`, a GET that answers after the stream is up, so up to 20 s). The active source is read from the play screen's artwork (`RemoteClient::parseSource`); a unit with no saved presets lists only the two inputs. Not offered: FM (it could not be selected on the hardware). |
| now playing | While the UPnP transport holds nothing, the listener asks `/ELAPSE` (the status) and `/nowplay` (the `playItem`) every 3 s and reports the radio (or **AUX**, as a source with no track and the generated line-in logo; an empty disc drive is not reported, it answers like a playing one): station (`subTitle`) and logo as the radio, the stream's own "Station - Show - Artists" title minus the station as the track. The UPnP side wins while a UPnP stream plays. A unit the server put to standby is not asked (it keeps counting while asleep). |
| hi-res and AAC files | `enable` stores what the unit decodes as device meta `upnp_limits` (`max_bit_depth` 16, `max_sample_rate` 48000, `deny_codecs` `aac`, from the quality test of `/settings/developer`). `Upnp\StreamProfile` then plays a lossless file above those limits from a `flac-16` proxy (16-bit / 44.1 kHz FLAC, still lossless) and an AAC file from `mp3-high` (320 kbit/s MP3); everything else as it is. The proxy is **made before the unit is told to play** (the stream controller sends the original while one is still being made, which the unit cannot decode); when ffmpeg fails the original is sent. Any UPnP renderer can use it by having `upnp_limits`; one without plays originals as before. |
| radio stations of ReMoment | `enable` sets device meta `radio_relay` to `always` and the limits `no_tls` and `deny_codecs: [aac]`: the unit cannot resolve names, do HTTPS or decode AAC, so every station is converted by the server and played from there as a plain HTTP MP3 (`Upnp\\MusicPlayerDriver::playRadioStation`, [radio-relay.md](radio-relay.md); needs `RADIO_RELAY_ENABLED=true` and the `radio-relay` compose profile). The station logo is sent as the stream's art; per-song art is not possible on this unit. |
| `play`, `pause`, `stop`, `next`, `previous` | While a local source plays (`streamium:{id}:local`, refreshed by the listener, 15 s) they go to the web remote (`GET /CTRL$…`) and never fall through to the server queue; otherwise they are the UPnP driver's. |

**A live radio stream cannot be paused**: the unit's `PLAY_PAUSE` toggles *mute* (`play` stays 1, `mute` flips, the elapsed counter keeps going). The driver therefore pauses by muting and plays by unmuting, and the listener reports a muted radio as `paused`. A mute through the volume control looks the same, so it also shows as paused. Volume itself is shared: the UPnP RenderingControl and the web remote's volume are the same (0-64, scaled to 0-100).

## Pitfalls

- Commands are **GETs**; only standby is a form POST. A POST to `/CTRL$PLAY_PAUSE` is silently ignored.
- `/ELAPSE` alternates a `NOWPLAY` change signal with the status: `RemoteClient::status()` asks again, and a listener poll that gets only signals leaves what it reported alone (it must not read "no answer" as "nothing playing").
- The unit's web servers are slow while it connects to a stream; calls during that moment can time out.
- The stream URL a unit is given comes from the host the API request came in on (`StreamUrl`): call the API by the server's LAN address, not `localhost`, when playing library tracks (see upnp-renderer.md).

## Not built

FM (behind the DVD unit's UART, see the hardware notes), playing a disc (untested: no disc), a readable power state, library browsing of the unit's own USB disk beyond the DLNA scan (`library:scan --server={ip}`; its folder view duplicates tracks under their file names).

Tests: `tests/Feature/StreamiumTest.php` (fixtures of the real pages in `tests/Fixtures/streamium/`).
