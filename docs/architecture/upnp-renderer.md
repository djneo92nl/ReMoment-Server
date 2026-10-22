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

An old (2008-era firmware, Allegro RomPager 4.64) Streamium HiFi player; plays through this driver with album art and track info on its screen. What is particular about it:

- **Two UPnP devices on two ports, and only one is advertised.** Port 80 is a *MediaServer* (ContentDirectory; its own UDN, empty library when no disk content) and is what SSDP, multicast or unicast, shows. The *MediaRenderer* is at `http://{ip}:7123/` (7124 serves the same document) and answers no SSDP search, so `device:discover` never lists it. Add it by the description URL: `php artisan device:discover --host=http://192.168.3.33:7123/ --add`, or give that URL under "Known device IPs" (`UpnpDiscovery` takes any `http(s)://` entry as a descriptor, no search). The URL is then kept as the `upnp_location` meta, which `RendererSetup::ensure()` reads first, so a later IP change needs only that value updated. The rest of the box: 8889 is the presentation page, 21/23 (FTP/telnet) answer `execve failed!`, 139 and 50019 are silent.
- **Services** (control URLs on port 7123: `/UD/action?0` RenderingControl, `?1` ConnectionManager, `?2` AVTransport): AVTransport has `SetAVTransportURI`, `Play`, `Pause`, `Stop`, `Seek`, `Next`, `Previous`, but **no `SetNextAVTransportURI`**, so no gapless (the server queue and a short gap between tracks). With nothing loaded `GetCurrentTransportActions` lists only `Play,Stop,Pause`. RenderingControl has `GetVolume`/`SetVolume`/`GetMute`/`SetMute`; the volume range is 0-64 (`upnp_volume_max`). Discovery records `seek` and `volume_control`.
- **Formats** (`GetProtocolInfo` sink): MP3, AAC, M4A, WAV, WMA/ASF, Ogg, `audio/x-flac` and a few streaming types. MP3 verified; FLAC listed but untested.
- **Slow to react.** A `SetAVTransportURI` pointing at an unreachable address leaves the transport on `PLAYING` at 0:00 for roughly 15 s while the box retries it, before it takes a new URI; a stale `STREAM_BASE_URL` (see above) therefore looks like a player that accepts the track and never plays. Check the server log for a `/stream/{id}.mp3` request.
- The device clock is stuck in 2008 (`Date:` headers); nothing here depends on it. A freshly paired box plays at volume 2 of 64.

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
