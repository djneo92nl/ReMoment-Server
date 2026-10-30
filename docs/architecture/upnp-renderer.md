# Generic UPnP MediaRenderer driver

**Path:** `app/Integrations/Upnp/`. A fallback for network players that only speak standard UPnP/DLNA (WiiM, Yamaha/Denon/Marantz, Bluesound, Linn, Kodi, foobar2000, Volumio, most hi-fi streamers). It is the lowest tier: **a dedicated driver always wins** (ASE, Mozart, Sonos, any brand in `config/devices.php`).

## What it supports

| Capability | How |
|---|---|
| `media_controls` | AVTransport `Play`, `Pause`, `Stop`. `next` / `previous` move the server queue. |
| `volume_control` | RenderingControl `GetVolume` / `SetVolume` / `GetMute` / `SetMute`, scaled to 0-100 from the `Volume` range in the SCPD (`upnp_volume_max`). Only when the renderer lists those actions. |
| `seek` | AVTransport `Seek` (`REL_TIME`). Only when listed. |
| `library_playback`, `queue`, `queue_jump`, `queue_add`, `queue_next`, `queue_remove`, `queue_move`, `queue_clear`, `shuffle`, `repeat` | Server-managed, see below. Edits (`Common\EditsDeviceQueue`: `DeviceQueue::append/insertNext/remove/move/clear`) need something playing from the queue; one that changes the next track re-hands the renderer its preload (`refreshPreloaded`). |
| `device_info` | Read-only (name, product); a renderer cannot be renamed from here. |

Not supported: multiroom, sources, power, like, sound adjustment. `seek` and `volume_control` are per device: discovery records what the SCPDs list in the `upnp_capabilities` meta (`seek`, `volume_control`, `gapless`), and `HardwareFeatures::allows()` applies it, so the capability list and the API's 422 agree (same idea as the ReMoment player's optional capabilities). A renderer added by hand has no meta and is assumed to have both.

## Queue (server-managed)

UPnP has no usable queue, so the server owns it: `Common\DeviceQueue` (track ids, index, shuffle, repeat; cache key `player:{id}:queue`, a week), shared with the ReMoment player. The renderer only ever plays the one URL it is told to (`SetAVTransportURI` with DIDL-Lite metadata, then `Play`).

- `playLibraryTracks()` replaces the queue and starts index 0; `getUpNext()` lists what follows.
- The listener (below) starts the next track when one ends by itself: transport goes `STOPPED` within 3 s of the track's end (without a known duration: it was playing and we did not stop it). A `stop()` we sent (flag `upnp:{id}:stopped`) or a stop mid-track is no track ending.
- **Gapless:** when the renderer lists `SetNextAVTransportURI`, the listener hands it the queue's next track in the last 10 s of a track; when the renderer then reports that URI, the queue moves on without a new `SetAVTransportURI`. Without it there is a short gap between tracks.
- A track started from the renderer's own app is shown as now-playing but has no queue: `upnp:{id}:playing` (what the server last started) is dropped and `getUpNext()` answers `[]`.
- Every track is started with `Stop`, `SetAVTransportURI`, `Play`: after a track has played, a Philips TV answers the next `SetAVTransportURI` with UPnP error 705 until it has been stopped (the stop is flagged as ours, so the listener does not read it as a track ending; a failing stop is ignored).
- `SetAVTransportURI` and `Play` wait up to 30 s (`Renderer::WAKE_SECONDS`): a TV that has to open its media player first answers the first one late. Our own files are given as `/stream/{id}.{ext}` with DLNA headers (see file-storage.md).
- Tracks need a stream URL the renderer can reach: DLNA URLs work as they are; own files use `StreamUrl::for()`, so unless `STREAM_BASE_URL` is left unset (then the address the request came in on is used, which follows the machine between networks, but only for requests made through an IP or real hostname, not `localhost` or `*.test`) it, or `APP_URL`, must be the server's current LAN address: a stale one (the Mac got a new DHCP address) shows up as the TV saying the file cannot be played, with no `/stream` request in the server log.

## Listener

`Services/DeviceListener` polls once a second (unicast, no multicast, no inbound callback port): `GetTransportInfo`, `GetPositionInfo` (URI, DIDL metadata, duration, position) and, every 3 s, volume and mute. It fires `NowPlayingUpdated`, `ProgressUpdated`, `NowPlayingEnded`, `VolumeUpdated`, `PlaybackModesUpdated` (from the queue) and updates the state in `DeviceCache`. A renderer that drops our metadata gets title and artist from the queue's track. The control URLs come from the device's meta (`upnp_avtransport_url`, `upnp_renderingcontrol_url`); after an IP change, run `device:discover --brand=upnp` again (matched on `upnp_uuid`).

## Discovery

`UpnpDiscovery` (last under `discoverers`): SSDP search for MediaRenderers, then per renderer:

1. skipped when `DedicatedDrivers::claims(manufacturer, model)`: the model is listed under a dedicated driver, or the manufacturer is a brand in `config/devices.php` that has one (so every Sonos model, listed or not). A new brand's driver takes its devices off this one just by being in the config;
2. skipped when a stored device of another driver has the same `upnp_uuid` (never take over);
3. skipped without AVTransport, or when `ConnectionManager.GetProtocolInfo` lists no `audio/*` sink (TVs, video renderers); a renderer that does not answer is let through.

The renderer's own manufacturer and model become the device's brand and product; the driver is set explicitly. `config/devices.php` also has `UPnP / Generic MediaRenderer` so the manual form works. A device added by hand has no control URLs yet: on first use `RendererSetup::ensure()` finds the renderer at its IP by a unicast search (MediaRenderer, then `upnp:rootdevice`), reads its descriptor and stores the same meta discovery would (the audio-sink filter is not applied: you chose the device). If nothing answers, the listener reports the device unreachable and logs why.

## Verified on hardware: Philips Streamium MCi900

The driver built on this is described in [philips-streamium.md](philips-streamium.md).

An old (2008-era firmware, Allegro RomPager 4.64) Streamium HiFi player; plays through this driver with album art and track info on its screen. What is particular about it:

- **Two UPnP devices on two ports, and only one is advertised.** Port 80 is a *MediaServer* (ContentDirectory; its own UDN, empty library when no disk content) and is what SSDP, multicast or unicast, shows. The *MediaRenderer* is at `http://{ip}:7123/` (7124 serves the same document) and was **not advertised over SSDP** when first probed from another subnet, so `device:discover` did not list it. After a factory reset, from the same subnet, it did advertise (`MediaRenderer:1` at `:7123/DeviceDescription.xml`): do not rely on either. Add it by the description URL: `php artisan device:discover --host=http://192.168.3.33:7123/ --add`, or give that URL under "Known device IPs" (`UpnpDiscovery` takes any `http(s)://` entry as a descriptor, no search). The URL is then kept as the `upnp_location` meta, which `RendererSetup::ensure()` reads first, so a later IP change needs only that value updated. The rest of the box: 8889 is the presentation page, 21/23 (FTP/telnet) answer `execve failed!`, 139 and 50019 are silent.
- **Services** (control URLs on port 7123: `/UD/action?0` RenderingControl, `?1` ConnectionManager, `?2` AVTransport): AVTransport has `SetAVTransportURI`, `Play`, `Pause`, `Stop`, `Seek`, `Next`, `Previous`, but **no `SetNextAVTransportURI`**, so no gapless (the server queue and a short gap between tracks). With nothing loaded `GetCurrentTransportActions` lists only `Play,Stop,Pause`. RenderingControl has `GetVolume`/`SetVolume`/`GetMute`/`SetMute`; the volume range is 0-64 (`upnp_volume_max`). Discovery records `seek` and `volume_control`.
- **Formats** (`GetProtocolInfo` sink): MP3, AAC, M4A, WAV, WMA/ASF, Ogg, `audio/x-flac` and a few streaming types. Tested with the quality test (`/settings/developer`): **OK** MP3 (lo, mid, 320, V0), WAV 16-bit/44.1 kHz, FLAC 16-bit at 44.1 and 48 kHz; **broken** FLAC 16-bit/96 kHz, FLAC 24-bit/48 kHz and AAC 256 (although `audio/aac` is in the sink list). Lossless only up to 16-bit/48 kHz, no AAC.
- **Slow to react, and sometimes ignoring.** The first play request after a boot or a long idle was twice ignored (no stream request, `NO_MEDIA_PRESENT`) and the next one worked; it needs 10-15 s to start a track and 10-15 s to act on a stop (the API answers `ok` at once). After being off for a while it can sit in its Internet-radio source, retrying `radio1-bb-aac` then `-mp3` by itself (it resumes its last source), and then ignores UPnP tracks, even a stop or the home page of the web remote: a hard power cycle clears it (a factory reset also did). Without working internet on its network (a stale gateway or DNS after moving it) the radio streams never play.
- **Slow to react.** A `SetAVTransportURI` pointing at an unreachable address leaves the transport on `PLAYING` at 0:00 for roughly 15 s while the box retries it, before it takes a new URI; a stale `STREAM_BASE_URL` (see above) therefore looks like a player that accepts the track and never plays. Check the server log for a `/stream/{id}.mp3` request.
- The device clock is stuck in 2008 (`Date:` headers); nothing here depends on it. A freshly paired box plays at volume 2 of 64.

### The web remote on port 8889

The renderer's `presentationURL` is the unit's own phone web remote (a Dutch-language page set, `index.htm` / `/index` / `/nowplay` and lists), the successor of the old Streamium app. It sees what the unit's screen shows and drives its **local** sources (internet radio, FM, AUX, disc; HDD only when a disk is fitted), which UPnP cannot: power, the radio presets and what is playing. Findings from the browser's own requests, on a unit with no disk and no FM antenna; send a `Host: {ip}:8889` header.

- **Commands are plain `GET`s**, answered `{'command':'SUCCESS','value':0}` (not JSON: single quotes, read it with a regex). Exception: **`POST /CTRL$STANDBY`** (form field `command`) toggles standby and gets no answer (the request times out although the unit acts). The page's other buttons are GETs; sending them as POSTs does nothing, which looked like a dead interface at first.

| GET | Does |
|---|---|
| `/CTRL$PLAY_PAUSE`, `/CTRL$STOP`, `/CTRL$NEXT`, `/CTRL$PREV` | transport. **On a live radio stream `PLAY_PAUSE` toggles mute** (`play` stays 1, `mute` flips; it pauses only a UPnP track or a disc). `NEXT` answers `{'command':'NOWPLAY'}`; its effect on a radio stream is unverified |
| `/VOLUME$VAL$n`, `/VOLUME$MUTE`, `/VOLUME$UNMUTE` | volume 0-64 (same scale as `upnp_volume_max`), mute |
| `/MODE$SHUFFLE_ON\|OFF`, `/MODE$REPEAT_ALL\|SINGLE\|OFF` | shuffle / repeat (meaningless for radio; the status numbering is unclear) |
| `/CTRL$ADD2FAV` | add the playing station to favourites |
| `/nav$03$01$001$0` | internet radio menu: presets, favourites, recent, search, all stations, my media (the unit's own menu cursor: the lists below the top level mirror its current list, they are not a directory) |
| `/nav$03$02$001$0` | the 10 radio presets (NPO Radio 1/2, NPO 3FM, 538, Qmusic, Sky Radio 101, Radio Veronica, SLAM!, 100% NL, Arrow Caz on this unit) |
| **`/nav$03$03$00N$1`** | **plays radio preset N (1-10)**; every list click on the page is just `window.location = target` |
| `/fmnav$06$01$001$0`, `/fmnav$06$02$001$0`, `/FMCTRL$MANUAL_TUNING`, `/FMCTRL$AUTO_STORE_CONFIRM` | FM menu, its 10 presets (all `(EMPTY)`), tuning, auto-store (found nothing without an antenna) |

- **State is readable:** `GET /ELAPSE` answers either `{'command':'NOWPLAY'}` (a "something changed" signal) or, on the next poll, the status `{'command':'ELAPSE','value':elapsed,'mute','shuffle','repeat','play','volume','rating','favstatus'}` with `play` 1 = playing, 2 = paused. Poll it in a loop until the status form arrives; it lags a command by one poll. `GET /nowplay` is the play screen with `var playItem = {title, subTitle, albumArt, volume, maxvolume, elapsetime, totaltime, playStaus, …}`: for radio the title carries the show and song from the stream (`'NPO 3FM - Barend & Benner - PowNed'`), `albumArt` is the station logo from TuneIn's old RadioTime CDN (`radiotime-logos.s3.amazonaws.com`, fetched by the unit itself), `totaltime` 65535 means no end. `/HOMESTATUS`, `/BUFFERSTATUS`, `/DVDSTATUS` are the screens' own polls. With nothing playing `/nowplay` is the home page. The elapsed counter of a radio stream was stuck at 190 until a new station was selected.
- **Power has no readable state**: `/CTRL$STANDBY` only toggles. In standby the unit still answers on 7123 and 8889, so it can be woken over the network, but not told from awake by asking; a driver would have to track its own guess.
- **Inputs:** opening `GET /aux` or `GET /dvd` **switches the unit to that input** (AUX confirmed on the physical display; Disc switches and asks for a disc, playing one is untested). Each leaves its signature in the play screen: `playItem.defaultAlbumArt` is `res/Home_AUX_nowplaying.jpg` for AUX, `res/Disc.jpg` for disc (an empty drive has title `-1`), `res/Internet_radio.jpg` for radio. A library track played over UPnP takes over from AUX at once. (They looked like inert templates while the unit was stuck in its radio, see above.)
- **Not verified:** selecting FM (the menu `/fmnav$06$01$001$0` returned an empty list and left the unit on its home screen; it may need an antenna), Streamium's "My media"/favourites server feature, anything on 21/23/139/50019. The service manual covers hardware only.

### MCi900 hardware (from the service manual's block diagram)

The manual covers hardware only, no software interfaces; the block diagram explains some of what the network side shows.

- **Main board:** an ADI Blackfin BF527 DSP (96 MB SDRAM, 128 Mbit flash), most likely running uClinux. That fits the Allegro RomPager web servers and the FTP/telnet ports that answer `execve failed!` (a cut-down busybox without its binaries): do not expect a way in there. Ethernet is an SMSC LAN8700; Wi-Fi is a USB 802.11b/g/n module on its own USB jack; a 3.5" TFT and an IR receiver are on the same board. The class-D amplifiers (TDA8932T) drive the speakers directly. AUX IN and the headphone jack are on the main board's ADAU1361 codec.
- **USB storage:** a second USB jack, labelled "Storage Device" (Renesas USB 2.0 dual-function controller), is probably what the web remote's "HDD" source means: a USB disk or stick, served by the built-in MediaServer on port 80 (its ContentDirectory was empty with nothing attached). Untested: plug in a stick with music and browse it again; it would be a Local-style library source on the unit itself.
- **DVD unit:** a separate board (MediaTek MT1389M, disc loader, FM tuner with RDS decoder, HDMI/YUV/CVBS outputs) linked to the main board by a UART (`DVD TX/RX`), tuner I2C, GPO and an eject key. Disc and FM selection are therefore commands to another module, which is why `/dvd` and `/fmnav$…` on the web remote behave as screen templates and selecting those sources was not confirmed.
- No serial or debug header is labelled in the diagram.

## Not built yet / future research

- **Prefer a native queue when the device has one.** The descriptor already lists a device's services, so this is detectable. OpenHome (Linn, Naim, Volumio, BubbleUPnP, others) has `urn:av-openhome-org:service:Playlist:1` (`Insert`, `DeleteId`, `ReadList`, `SeekId`, `Next`, `Previous`, `SetShuffle`, `SetRepeat`): a real queue on the device, gapless, that survives the server going away and shows the same list in the device's own app. A device with it would get its own tier above this driver (`QueueInterface` / `QueueJumpInterface` / shuffle / repeat read from and written to the device, the listener subscribing to the service's events instead of polling), and the server queue stays for plain renderers. Sonos-style `Q:` queues on AVTransport are the same idea for the Sonos driver, which has it already.
- **Radio (built):** `RadioControlInterface` with platform `url`. A station's stream URL goes through `playStreamUrl()` (Stop, `SetAVTransportURI` with DIDL-Lite `audio/mpeg`, Play); the driver remembers the station (`upnp:{id}:radio`: station and URI) and the listener then reports the station as the radio and the stream's ICY title as the song (`IcyReader`, refreshed every 15 s by `PollRadioTitle`, `StreamTitles`). Stations are added by hand or from the radio-browser.info search; B&O and Sonos still prefer their native BeoRadio / TuneIn id when the station has one. Not handled: HLS and playlist (m3u/pls) URLs, which the search skips.
- **What of multiroom, sources, power and radio is possible:**
  - *Radio:* possible on every renderer: play a station's stream URL through `SetAVTransportURI` (a `RadioControlInterface` with a `url` identifier on `RadioStation`). Now-playing comes from the stream's metadata where the renderer passes it on. The easiest of the four.
  - *Sources, power:* no standard for either on plain MediaRenderers. OpenHome's `Product` service has both: `SourceXml` / `SetSourceIndex` (sources, `SourcesInterface` / `SourceActivationInterface`) and `Standby` / `SetStandby` (`PowerInterface`). Otherwise vendor APIs only (Yamaha MusicCast and Denon HEOS have an HTTP API with sources, power and grouping; Bluesound has BluOS; WiiM has the LinkPlay HTTP API).
  - *Multiroom:* nothing standard in UPnP AV. OpenHome has Songcast (`Sender` / `Receiver` services) which can do it between OpenHome devices; the rest is vendor-specific as above. The server could also fake it by playing the same URL on several renderers, but they would not be in sync.
  - So: radio is cheap; sources, power and multiroom each mean a platform-specific tier (OpenHome first, then the vendor APIs of the brands you own), with this driver as the base.
- **GENA eventing** instead of polling: `SUBSCRIBE` to AVTransport / RenderingControl `LastChange` needs a callback route and renewals; only worth it if polling load or lag becomes a problem.
- Sound adjustment (RenderingControl `Bass`, `Treble`, `Loudness` and their ranges), and a re-read of the descriptor when a control URL stops answering.
