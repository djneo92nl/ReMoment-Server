# Developer tools (`/settings/developer`)

An admin-only page (admin login, like every `/settings/*`) with eight tabs, each a Livewire component in `app/Livewire/Developer/` over classes in `app/Domain/Developer/`.

| Tab | Component | What it does |
|-----|-----------|--------------|
| Quality test | `QualityTest` | Pick one of your own tracks and a player, then play the original or a rung of the quality ladder (grouped: lossless FLAC 16-bit 44.1/48/96 kHz and 24-bit 44.1 up to 192 kHz, ALAC, uncompressed WAV up to 24/192, MP3 320/V0/192/128/64, AAC 256/128, Opus 128, plus the player profiles `mp3-mid`, `mp3-lo`). The transcode is made synchronously with ffmpeg (`Proxies::generate`); the result shows the URL that was sent and how long the call took. |
| Capability probe | `CapabilityProbe` | The same ladder on one player: play a rung, listen, mark it OK or Broken. The verdicts are kept as device meta `capability_probe` (`CapabilityProfile`) with a summary per group and the highest sample rate that worked. A rung the driver refuses is marked broken by itself. |
| Playback tests | `PlaybackTests` | Test signals (1 kHz sine, 20 Hz to 20 kHz sweep, left/right channel check, pink noise; `Tones`, 30 s WAV made once by ffmpeg, served at `/dev/tone/{name}.wav`, played with `PlayStreamUrlInterface`, so players with `stream_url`); seek to 10/25/50/90% or a second (`SeekInterface`); a gapless pair (the chosen track and the next own-file track of its album as one queue, optionally at a ladder quality). |
| Simulate | `SimulateNowPlaying` | Sends a made-up now playing, progress, state or volume through the same events a listener fires (`NowPlayingUpdated`, `ProgressUpdated`, `DeviceStateChanged`, `VolumeUpdated`, `NowPlayingEnded`); the form can be filled from a play in the history, with progress steps of 0/25/50/75/100%. A running listener overwrites it at its next poll. |
| MQTT | `MqttConsole` | Publishes any topic/payload (QoS 0/1, retain; empty retained clears) through `MqttService`, and a live feed of `remoment/#` over the browser's own WebSocket connection (Alpine, nothing rendered by the server). |
| Devices | `DeviceInspector` | The server's view of a device (state, listener, capabilities, volume, modes, battery, now playing, meta), raw driver calls (play, pause, stop, next, previous, volume ±) that show the exception class and message a driver throws, and the latency of the recent calls from this page (`CallTimings`, last 30 per device, also fed by the quality test, probe and playback tests). |
| Streams | `StreamUrlTester`, `StreamDiagnosticsPanel` | The base address speakers get, the plain and with-extension URLs for a track and profile, a HEAD plus a one-KB range request against each; the `/stream` request log; fault injection. |
| Maintenance | `Maintenance` | Transcode cache size per profile with delete and prune, clear one device's cache, `alerts:check`, a test alert (kind `dev_test`) raised and resolved, restart of the queue workers. |

## How the quality test reaches a driver

Drivers build the stream URL themselves (`Track::getDlnaUrl()` → `StreamUrl::for()`, UPnP via `StreamUrl::withExtension()`), so no driver contract carries a quality. Instead `StreamUrl::withProfile($profile, $callback)` makes every URL built inside the callback ask for `?profile=…` (and, for the extension form, the proxy's extension), and is cleared afterwards, also when the callback throws. The test plays through `LibraryPlayback::playTrack()` inside it, so ASE, Sonos, UPnP and ReMoment players all get that quality for the first track; later queue entries are plain again. `PlayerDevice::source()` honours the same override for ReMoment players.

`GET /stream/{track}?profile=` serves a ready proxy (`StreamController`), which the test made just before.

## Dev profiles

Profiles in `config/proxies.php` with `'dev' => true` are ladder rungs: `Proxies::profiles()` lists all of them (so `generate`, `readyPath`, `mime` and pruning treat them like any proxy), `Proxies::playerProfiles()` leaves them out and is what a player's automatic profile choice uses, `Proxies::devProfiles()` lists only them. Add a rung by adding a profile with `dev`, a `label`, `mime`, `extension` and ffmpeg `args`.

## Adding a tool

A new component in `app/Livewire/Developer/`, its view in `resources/views/livewire/developer/`, a tab in `resources/views/settings/developer.blade.php`, and a test in `tests/Feature/DeveloperPageTest.php`.

## Stream log and fault injection

`StreamDiagnostics` (cache keys `dev:stream:*`) hooks `StreamController::show()`. **Logging** (off by default, 60 minutes at most, last 100 requests): time, track, caller's address and user agent, `Range`, `profile`, status, size, and the time until the answer starts. This is how to learn whether a speaker follows the redirect of a remote file, sends range requests for a format, or reads the whole file. **Faults** (15 minutes at most): a delay of up to 10 s, or HTTP 404, 500 or 503, on a percentage of every `/stream` request, whoever makes it. With both off a stream request costs two cache reads.

## Not built

Restarting one device listener (`device:listen` has no per-device restart to call) and Home Assistant discovery messages (not built yet, see `home-assistant.md`).
