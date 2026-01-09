```

/$$$$$$$            /$$      /$$                                               /$$    
| $$__  $$          | $$$    /$$$                                              | $$    
| $$  \ $$  /$$$$$$ | $$$$  /$$$$  /$$$$$$  /$$$$$$/$$$$   /$$$$$$  /$$$$$$$  /$$$$$$  
| $$$$$$$/ /$$__  $$| $$ $$/$$ $$ /$$__  $$| $$_  $$_  $$ /$$__  $$| $$__  $$|_  $$_/  
| $$__  $$| $$$$$$$$| $$  $$$| $$| $$  \ $$| $$ \ $$ \ $$| $$$$$$$$| $$  \ $$  | $$    
| $$  \ $$| $$_____/| $$\  $ | $$| $$  | $$| $$ | $$ | $$| $$_____/| $$  | $$  | $$ /$$
| $$  | $$|  $$$$$$$| $$ \/  | $$|  $$$$$$/| $$ | $$ | $$|  $$$$$$$| $$  | $$  |  $$$$/
|__/  |__/ \_______/|__/     |__/ \______/ |__/ |__/ |__/ \_______/|__/  |__/   \___/  
                                                                                                                                                                           
```


# ReMoment

### A modular, network-based media controller layer

ReMoment is a Laravel-based PHP service that acts as a **universal controller** for networked audio (and eventually video) devices. It is designed as an abstraction layer between user interfaces, automation systems, and a growing ecosystem of network media players.

The goal is not to replace native apps, but to provide a **consistent, extensible control surface** for heterogeneous devices that were never designed to work together.

This project is still in development

---

## What ReMoment Is

ReMoment is intentionally **not** a media server and **not** a traditional remote.

It runs *next to* media servers, streaming services, and playback devices as a **coordination and intelligence layer**. Media stays where it already lives; ReMoment provides the logic that decides how that media moves, appears, and is controlled.

ReMoment exposes a unified API for controlling and querying media players across brands and protocols. It normalizes common concepts such as:

* Power and availability
* Source selection
* Transport control (play, pause, stop, next, previous)
* Volume and mute
* Now-playing metadata
* Playback history and state transitions

Under the hood, each supported ecosystem is implemented as a driver/interface that translates these generic commands into device-specific protocols.

In addition to real-time control, ReMoment **records playback activity** and enriches it with metadata to build a richer picture of what is being played across devices.

---

## Architecture Overview

ReMoment is not only reactive, but **observant**. It captures now-playing events, device state changes, and media identifiers, then resolves and stores enriched metadata for later use.

* **Framework**: Laravel (PHP)
* **Role**: Interface / orchestration layer
* **Clients**: Web UIs, embedded devices (ESP32), automation systems, scripts
* **Devices**: Network-connected speakers, TVs, and media players

ESP32-based controllers can connect to ReMoment over the network and act as physical control surfaces, displays, or sensors without needing to understand vendor-specific APIs.

Because ReMoment persists enriched playback data, these controllers can request **context-aware information** (track details, artwork references, source history) instead of raw device responses.

---

## Current Support

### Bang & Olufsen

* **ASE (Audio / Video Engine)** devices

### Sonos

* Core playback and device control

---

## Media Sources & Playback Targets

ReMoment does not store or stream media itself. Instead, it **orchestrates playback** by instructing capable devices to fetch, start, and route media from existing sources.

This keeps responsibilities clean:

* Media servers serve media
* Streaming services stream
* Devices decode and play
* **ReMoment decides and connects**

ReMoment is designed to evolve from a passive observer into an **active media selector**. Beyond device control, it aims to act as a source broker that can initiate playback across ecosystems.

ReMoment is designed to evolve from a passive observer into an **active media selector**. Beyond device control, it aims to act as a source broker that can initiate playback across ecosystems.

Planned capabilities include:

* Selecting and starting tracks, albums, and playlists from **Spotify** and **Deezer** (via device-supported or indirect control paths)
* Starting and switching **radio stations** (internet radio and device-native radio implementations)
* Indexing **DLNA / UPnP media servers** and exposing their libraries in a normalized form
* Routing local DLNA media to supported playback devices regardless of original vendor intent

This allows client devices (UIs, ESP32 controllers, automations) to request *what* should be played without needing to know *how* a specific device achieves it.

---

## Planned / Experimental Support

### Bang & Olufsen

* **Mozart** platform devices

### Other Ecosystems

* Logitech Squeezebox
* Bose SoundTouch (under investigation)

These targets may evolve depending on protocol accessibility and long-term device viability.

---

## Metadata & Enrichment

ReMoment tracks what is played over time and augments it with external or device-provided metadata where possible. This enables:

* Playback history across devices and ecosystems
* Normalized artist, album, and track data
* Artwork and extended media descriptors
* Smarter UI displays and future recommendation logic

Metadata enrichment is designed to be asynchronous and non-blocking, so device control remains fast and reliable.

---

## Native & Restored Device Capabilities

Many networked audio products lose functionality over time as companion apps change or disappear. ReMoment intentionally reintroduces and preserves **native-like features** by talking directly to device protocols.

Examples include:

* Speaker grouping for **Bang & Olufsen ASE televisions**
* Reconnecting and managing **WiSA speakers** with Beosound Moment-class devices
* Accessing configuration and routing features removed from official apps

These functions are exposed in a consistent, API-driven way so they can be reused by modern UIs and physical controllers.

---

## Project Status

ReMoment is under active development and is intentionally opinionated:

* Focused on **local network control**
* Designed to be **headless-first** (API before UI)
* Optimized for **long-lived hardware projects** rather than cloud dependency

Expect breaking changes while interfaces are refined and new device families are added.

---

## Naming

**ReMoment** reflects its origins as part of the *Beosound Moment Revival* project, but the scope has expanded into a more general-purpose controller layer.

---

## License & Use

License and contribution guidelines will be defined once the core interfaces stabilize.

## Usage of AI
___
Some code has been build with the help of AI
