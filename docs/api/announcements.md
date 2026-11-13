# Announcements

Play a short clip (a doorbell, "dinner is ready", a timer) on one or more speakers. Each speaker then goes back to what it was doing: the same queue at the same position, the radio station, the input, or standby, at the volume it had.

Playing an announcement is open, like the playback endpoints (so a Home Assistant `rest_command` or a scene can call it; the operation `announce` is in `config/scene-operations.php`). Uploading and removing clips needs the admin token.

**Status: built and tested with the test drivers; not tried on a real speaker.** See "Per brand" below for what to expect and what is unknown.

## Endpoints

```
GET  /api/announcements/clips   → { "data": [ { "name": "chime", "label": "Chime", "duration_ms": 2200, "builtin": true }, … ] }
POST /api/announcements         body: { "clip": "chime", "device_ids": [1, 4], "volume"?: 40 }
```

Answer of `POST`:

```json
{ "status": "partial", "clip": "chime", "devices": [
  { "id": 1, "name": "Kitchen", "status": "ok" },
  { "id": 4, "name": "Bedroom", "status": "error", "error": "unsupported", "message": "Bedroom cannot play an announcement." }
] }
```

`status` is `ok` (all devices), `partial` (some) or `failed` (none; the HTTP status is then `502`). A device's `error` is `unreachable`, `unsupported` (no `stream_url` capability) or `driver_error`. An unknown clip or device id is a `422` validation error. `volume` is the volume during the clip; it is never above the device's volume limit (`docs/api/device-settings.md`), and the earlier volume comes back afterwards.

Admin token:

```
POST   /api/admin/announcements/clips          multipart: file (max 20 MB), label?   → 201 { "data": Clip }
DELETE /api/admin/announcements/clips/{name}   → { "status": "ok" }   (404 for an unknown clip or the built-in chime)
```

An upload is converted to MP3 (44.1 kHz stereo, 192 kbit/s) and cut to 30 seconds; its `name` is a slug of the label. MCP tool: `announce` (`clips`, `play`). Web: Settings › Announcements (`/settings/announcements`, admin): upload, remove, and "Play" on a chosen speaker.

## What happens

1. **Remember.** Before the clip, `App\Domain\Playback\Announcer` remembers the device: what plays (`PlaybackCapture`, the same reading "move playback" uses: the library queue and position, the radio station, or Spotify), else the input it is on (line-in, TV, CD: matched to the device's source list), else nothing; plus the cached volume and whether it was in standby. Kept in the cache for 5 minutes (`announce:{device}`).
2. **Play.** Spotify is paused first (it would otherwise take the speaker back). The volume is set when `volume` is given. The clip is played with `PlayStreamUrlInterface::playStreamUrl` from `GET /announce/{name}.mp3` on this server (no session, like `/stream`; the address is `StreamUrl::base()`, so `STREAM_BASE_URL` applies).
3. **Restore.** Nothing tells the server that a clip has ended, so the job `RestoreAfterAnnouncement` runs after the clip's length plus 1.5 seconds. It sets the volume back and then: library, radio or Spotify go through `PlaybackMover::apply()` (the second half of a move, on the same device: the queue from the current track, a seek to the remembered position, paused again if it was paused); an input is activated again; a device that played nothing is stopped, or put back in standby when it was in standby and can be.

A second announcement on a device during the first keeps what the first one remembered, and only the last restore job acts, so the clip itself is never restored as "what was playing".

**The queue worker must run**: the restore is a delayed job on the default queue. With the queue down, the device stays silent after the clip.

## Limits

- The position is the listener's last report, so playback comes back up to a few seconds before where it was, plus the clip's length for radio (a live stream simply continues live).
- A track that is in the queue but not in the library, and an input that is not in the device's source list, cannot be restored; the device is then left stopped.
- On a device whose own queue the server cannot read fully (Mozart), only the current track comes back.
- No text-to-speech and no arbitrary URL as a clip yet.
- Clips are files on the server's local disk (`storage/app/private/announcements/`); they are not in the backup archive.

## Per brand (all unverified on hardware)

| Driver | How the clip is played | To check |
|---|---|---|
| Generic UPnP, Philips | `SetAVTransportURI` with the MP3, DIDL says `audio/mpeg` | The server-kept queue (`DeviceQueue`) survives the clip; that the renderer accepts a second URI while playing |
| Sonos | the URL as `x-rincon-mp3radio://`, Sonos's scheme for MP3 radio streams | Whether a file of a few seconds plays to the end under that scheme, and what Sonos does when it ends |
| ASE (B&O) | `playDlnaTrack` to the PlayQueue with `instantplay` (the stream path is itself unverified) | Whether the clip plays, and whether the PlayQueue is replaced (the restore re-queues from the library either way) |
| ReMoment player | `startStream`; the player reports the end of a stream as a drop (standby) | That the restore job still brings the queue back after that |
| Spotify on a speaker | pause, clip, then Spotify's transfer back to the same Connect device | Whether a transfer to the device it is already on resumes playback |
