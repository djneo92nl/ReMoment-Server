# Receiver View (`/receiver`)

Full-screen now-playing display, designed for TVs, wall-mounted tablets, and Chromecast targets.

## Overview

A standalone Blade template with no Livewire dependency — pure polling JS hitting `GET /api/devices/{id}` every 3 seconds. Accepts a `?device={id}` query parameter to skip the picker. If only one device exists it auto-selects.

## Features

| Feature | Notes |
|---|---|
| Device picker | Shown on first load if `?device=` is absent |
| Album art crossfade | Two `<img>` layers (a/b) swap with opacity transition |
| Color theming | 5 dominant colors from `ArtworkCache`; accent on track name, muted on artist/album |
| Progress bar | Driven by `ProgressUpdated` MQTT payload via API; interpolated locally each second |
| Playback controls | Play/pause/next/previous via REST API; hidden on Chromecast UA |
| Volume control | Slider, debounced 400ms, hidden on TV UA and Chromecast |
| Lyrics view | Toggle button swaps meta panel ↔ 3-line teleprompter (previous / current / next) |
| Synced lyrics | LRC timestamps → binary search per second for current line |
| Plain lyrics | Proportional estimate when no timestamps available |
| Marquee | Long track names and long current lyric lines scroll via CSS animation |
| Ambient mode | After 30s idle, UI fades out; auto-switches to lyrics view if lyrics exist |
| Idle clock | Shown when no device is playing |
| Fullscreen button | `requestFullscreen()` API |

## Ambient Mode

After 30 seconds without mouse/touch/keyboard input while a device is playing, the page adds `body.ambient`. This fades out all UI chrome (controls, progress, buttons) with a 2.5s transition. Album art and — if lyrics are present — the lyrics view remain visible.

**Auto-switch behaviour:**
- When ambient activates: if lyrics are loaded and the meta view is active, the page automatically switches to lyrics view.
- When ambient deactivates (any mouse/touch/key): if ambient was the one that switched to lyrics, the page switches back to meta view. If the user manually activated lyrics view before ambient fired, it stays in lyrics view.

## Lyrics

Lyrics are provided by the `GET /api/devices/{id}` endpoint inside `now_playing.lyrics`:

```json
{
  "lyrics": {
    "plain": "Line one\nLine two\n...",
    "synced": "[00:12.34]Line one\n[00:15.80]Line two\n..."
  }
}
```

`synced` takes priority over `plain`. Both can be null. The lyrics button (`#btn-lyrics`) is only shown when at least one is available. See `app/Http/Resources/Api/DeviceDetailResource.php` for the resolution logic (external ID → name+artist fallback).

## Responsive Layout

The view adapts to portrait tablets and phones via two media-query breakpoints. No framework — plain CSS.

| Viewport width | Layout |
|---|---|
| > 900px | Side-by-side: 425px artwork left, meta/lyrics right |
| ≤ 900px (portrait tablet) | Stacked: 260px artwork centred, meta/lyrics below. Targets iPad portrait (768px) and Nexus 7 portrait (~600px CSS px) |
| ≤ 480px (phone) | Stacked: artwork 52vw, further reduced font sizes and padding |

The missing `<meta name="viewport">` would cause tablets to render at desktop scale and zoom out. It is set to `width=device-width, initial-scale=1`.

## Browser Compatibility

The minimum supported browser is **Chrome 55 / Safari 10.1 / Firefox 52 (all ~2017)**, set by `async/await` and `fetch`.

`?.`, `??`, and `clamp()` have been deliberately avoided so the view works on older engines.

The controlling features and their first-ship dates:

| Feature | Chrome | Safari | Firefox |
|---|---|---|---|
| `fetch` + `async/await` | 55 (Dec 2016) | 10.1 (2017) | 52 (2017) |
| `URLSearchParams` | 49 (2016) | 10.1 (2017) | 44 (2016) |
| CSS custom properties `var(--)` | 49 (2016) | 9.1 (2016) | 31 (2014) |
| CSS animations / `transform` | 36 (2014) | 9 (2015) | 16 (2012) |
| `backdrop-filter: blur()` | 76 (2019) | 9 (webkit-) | 103 (Aug 2022) |
| `document.fonts.ready` | 37 (2014) | 10 (2016) | 41 (2015) |

`backdrop-filter` degrades gracefully (picker overlay loses blur, layout unchanged). Everything else will work on any browser from ~2017 onward.

**Smart TV floor:**
- Samsung Tizen: Tizen 4.0+ (2017 models, Chromium 56) ✓
- LG webOS: webOS 4.0+ (2019, Chromium 53) — marginal; webOS 5+ (2020) ✓
- Apple TV: tvOS 11+ (2017) ✓
- Chromecast: controls are hidden via UA detection; lyrics and art still display

**Features deliberately not used:**
- Optional chaining `?.` (Chrome 80 / 2020)
- Nullish coalescing `??` (Chrome 80 / 2020)
- `css: clamp()` (Chrome 79 / 2020) — replaced with `font-size: 14vw` fallback + `clamp()` override for modern browsers

## Key DOM Elements

| ID | Role |
|---|---|
| `#picker` | Device selection overlay, hidden after device chosen |
| `#main` | Primary content area: artwork + meta/lyrics |
| `#art-a`, `#art-b` | Crossfading album art layers |
| `#meta` | Track name, artist, album, source info |
| `#lyrics-view` | 3-line lyrics teleprompter (`#lyric-prev`, `#lyric-curr`, `#lyric-next`) |
| `#lyric-curr-inner` | Inner `<span>` inside `#lyric-curr` — carries the marquee animation |
| `#progress-wrap` | Progress bar + timestamps |
| `#controls` | Play/pause/next/previous buttons |
| `#btn-lyrics` | Toggle between meta and lyrics view |
| `#btn-fullscreen` | Fullscreen toggle |
| `#idle-screen` | Clock shown when nothing is playing |

## Key JS Globals

| Variable | Type | Purpose |
|---|---|---|
| `deviceId` | number | Currently selected device |
| `currentLyricsData` | `{type, lines}` or null | Parsed lyrics for current track |
| `lyricsViewActive` | bool | Whether lyrics panel is shown |
| `ambientSwitchedToLyrics` | bool | Whether ambient mode triggered the lyrics switch (determines swap-back on wake) |
| `currentProgress` | number | Last known playback position in seconds |
| `progressLastPollTime` | number | `Date.now()` of last API poll, for local interpolation |
