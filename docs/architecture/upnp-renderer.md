# Generic UPnP MediaRenderer driver

**Path:** `app/Integrations/Upnp/`. A fallback for network players that only speak standard UPnP/DLNA (WiiM, Yamaha/Denon/Marantz, Bluesound, Linn, Kodi, foobar2000, Volumio, most hi-fi streamers). It is the lowest tier: **a dedicated driver always wins** (ASE, Mozart, Sonos, any brand in `config/devices.php`).

## What it supports

| Capability | How |
|---|---|
| `media_controls` | AVTransport `Play`, `Pause`, `Stop`. `next` / `previous` move the server queue. |
| `volume_control` | RenderingControl `GetVolume` / `SetVolume` / `GetMute` / `SetMute`, scaled to 0-100 from the `Volume` range in the SCPD (`upnp_volume_max`). Only when the renderer lists those actions. |
| `seek` | AVTransport `Seek` (`REL_TIME`). Only when listed. |
| `library_playback`, `queue`, `queue_jump`, `shuffle`, `repeat` | Server-managed, see below. |
| `device_info` | Read-only (name, product); a renderer cannot be renamed from here. |

Not supported: multiroom, sources, power, radio, like, sound adjustment. `seek` and `volume_control` are per device: discovery records what the SCPDs list in the `upnp_capabilities` meta (`seek`, `volume_control`, `gapless`), and `HardwareFeatures::allows()` applies it, so the capability list and the API's 422 agree (same idea as the ReMoment player's optional capabilities). A renderer added by hand has no meta and is assumed to have both.

## Queue (server-managed)

UPnP has no usable queue, so the server owns it: `Common\DeviceQueue` (track ids, index, shuffle, repeat; cache key `player:{id}:queue`, a week), shared with the ReMoment player. The renderer only ever plays the one URL it is told to (`SetAVTransportURI` with DIDL-Lite metadata, then `Play`).

- `playLibraryTracks()` replaces the queue and starts index 0; `getUpNext()` lists what follows.
- The listener (below) starts the next track when one ends by itself: transport goes `STOPPED` within 3 s of the track's end (without a known duration: it was playing and we did not stop it). A `stop()` we sent (flag `upnp:{id}:stopped`) or a stop mid-track is no track ending.
- **Gapless:** when the renderer lists `SetNextAVTransportURI`, the listener hands it the queue's next track in the last 10 s of a track; when the renderer then reports that URI, the queue moves on without a new `SetAVTransportURI`. Without it there is a short gap between tracks.
- A track started from the renderer's own app is shown as now-playing but has no queue: `upnp:{id}:playing` (what the server last started) is dropped and `getUpNext()` answers `[]`.
- Every track is started with `Stop`, `SetAVTransportURI`, `Play`: after a track has played, a Philips TV answers the next `SetAVTransportURI` with UPnP error 705 until it has been stopped (the stop is flagged as ours, so the listener does not read it as a track ending; a failing stop is ignored).
- `SetAVTransportURI` and `Play` wait up to 30 s (`Renderer::WAKE_SECONDS`): a TV that has to open its media player first answers the first one late. Our own files are given as `/stream/{id}.{ext}` with DLNA headers (see file-storage.md).
- Tracks need a stream URL the renderer can reach: DLNA URLs work as they are; own files use `StreamUrl::for()`, so `STREAM_BASE_URL` (or `APP_URL`) must be the server's current LAN address: a stale one (the Mac got a new DHCP address) shows up as the TV saying the file cannot be played, with no `/stream` request in the server log.

## Listener

`Services/DeviceListener` polls once a second (unicast, no multicast, no inbound callback port): `GetTransportInfo`, `GetPositionInfo` (URI, DIDL metadata, duration, position) and, every 3 s, volume and mute. It fires `NowPlayingUpdated`, `ProgressUpdated`, `NowPlayingEnded`, `VolumeUpdated`, `PlaybackModesUpdated` (from the queue) and updates the state in `DeviceCache`. A renderer that drops our metadata gets title and artist from the queue's track. The control URLs come from the device's meta (`upnp_avtransport_url`, `upnp_renderingcontrol_url`); after an IP change, run `device:discover --brand=upnp` again (matched on `upnp_uuid`).

## Discovery

`UpnpDiscovery` (last under `discoverers`): SSDP search for MediaRenderers, then per renderer:

1. skipped when `DedicatedDrivers::claims(manufacturer, model)`: the model is listed under a dedicated driver, or the manufacturer is a brand in `config/devices.php` that has one (so every Sonos model, listed or not). A new brand's driver takes its devices off this one just by being in the config;
2. skipped when a stored device of another driver has the same `upnp_uuid` (never take over);
3. skipped without AVTransport, or when `ConnectionManager.GetProtocolInfo` lists no `audio/*` sink (TVs, video renderers); a renderer that does not answer is let through.

The renderer's own manufacturer and model become the device's brand and product; the driver is set explicitly. `config/devices.php` also has `UPnP / Generic MediaRenderer` so the manual form works. A device added by hand has no control URLs yet: on first use `RendererSetup::ensure()` finds the renderer at its IP by a unicast search (MediaRenderer, then `upnp:rootdevice`), reads its descriptor and stores the same meta discovery would (the audio-sink filter is not applied: you chose the device). If nothing answers, the listener reports the device unreachable and logs why.

## Not built yet / future research

- **Prefer a native queue when the device has one.** The descriptor already lists a device's services, so this is detectable. OpenHome (Linn, Naim, Volumio, BubbleUPnP, others) has `urn:av-openhome-org:service:Playlist:1` (`Insert`, `DeleteId`, `ReadList`, `SeekId`, `Next`, `Previous`, `SetShuffle`, `SetRepeat`): a real queue on the device, gapless, that survives the server going away and shows the same list in the device's own app. A device with it would get its own tier above this driver (`QueueInterface` / `QueueJumpInterface` / shuffle / repeat read from and written to the device, the listener subscribing to the service's events instead of polling), and the server queue stays for plain renderers. Sonos-style `Q:` queues on AVTransport are the same idea for the Sonos driver, which has it already.
- **What of multiroom, sources, power and radio is possible:**
  - *Radio:* possible on every renderer: play a station's stream URL through `SetAVTransportURI` (a `RadioControlInterface` with a `url` identifier on `RadioStation`). Now-playing comes from the stream's metadata where the renderer passes it on. The easiest of the four.
  - *Sources, power:* no standard for either on plain MediaRenderers. OpenHome's `Product` service has both: `SourceXml` / `SetSourceIndex` (sources, `SourcesInterface` / `SourceActivationInterface`) and `Standby` / `SetStandby` (`PowerInterface`). Otherwise vendor APIs only (Yamaha MusicCast and Denon HEOS have an HTTP API with sources, power and grouping; Bluesound has BluOS; WiiM has the LinkPlay HTTP API).
  - *Multiroom:* nothing standard in UPnP AV. OpenHome has Songcast (`Sender` / `Receiver` services) which can do it between OpenHome devices; the rest is vendor-specific as above. The server could also fake it by playing the same URL on several renderers, but they would not be in sync.
  - So: radio is cheap; sources, power and multiroom each mean a platform-specific tier (OpenHome first, then the vendor APIs of the brands you own), with this driver as the base.
- **GENA eventing** instead of polling: `SUBSCRIBE` to AVTransport / RenderingControl `LastChange` needs a callback route and renewals; only worth it if polling load or lag becomes a problem.
- Sound adjustment (RenderingControl `Bass`, `Treble`, `Loudness` and their ranges), editing the queue (add, remove, reorder), and a re-read of the descriptor when a control URL stops answering.
