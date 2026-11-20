# Supported hardware

Which devices work with ReMoment Server, and how well each has been checked. A device marked *unverified* is built from the vendor's documentation or protocol and has never run against the real thing here: it may work, and a report either way helps (see [troubleshooting.md](troubleshooting.md)). Per-brand setup notes: [adding-devices.md](adding-devices.md).

<!-- hardware-table:start -->

Generated from `config/devices.php`, the driver contracts and `config/hardware-status.php` by `php artisan hardware:table`; do not edit between the markers.

Status key:

- verified on hardware
- built against the vendor spec, unverified
- fake-tested only

### Bang & Olufsen

| Model | Driver | Status | Checked | Note | What the server can do |
|---|---|---|---|---|---|
| BeoSound Essence | ASE | verified on hardware | 2026-10-04 | Essence Mk2 in the lab; mapped with ase:map. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `multi_room`, `library_playback`, `queue`, `queue_add`, `queue_next`, `queue_remove`, `queue_move`, `queue_clear`, `power`, `sound_adjustment`, `bluetooth`, `device_info`, `network_settings`, `wired_speakers`, `sleep_timer`, `stream_url` |
| BeoSound Moment | ASE | built against the vendor spec, unverified | 2026-11-14 | Usually switched off in the lab; the WiSA endpoints are not confirmed. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `multi_room`, `library_playback`, `queue`, `queue_add`, `queue_next`, `queue_remove`, `queue_move`, `queue_clear`, `power`, `sound_adjustment`, `bluetooth`, `device_info`, `network_settings`, `wireless_speakers`, `wired_speakers`, `sleep_timer`, `stream_url` |
| Beoplay M5 | ASE | verified on hardware | 2026-10-04 | In the lab; mapped with ase:map. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `multi_room`, `library_playback`, `queue`, `queue_add`, `queue_next`, `queue_remove`, `queue_move`, `queue_clear`, `power`, `sound_adjustment`, `bluetooth`, `device_info`, `network_settings`, `sleep_timer`, `stream_url` |
| Beoplay M3 | ASE | verified on hardware | 2026-10-04 | In the lab; mapped with ase:map. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `multi_room`, `library_playback`, `queue`, `queue_add`, `queue_next`, `queue_remove`, `queue_move`, `queue_clear`, `power`, `sound_adjustment`, `bluetooth`, `device_info`, `network_settings`, `sleep_timer`, `stream_url` |
| Beoplay V1 | ASE | verified on hardware | 2026-10-04 | Sources and waking by sending a source seen; remote keys are not confirmed. | `media_controls`, `volume_control`, `source_control`, `source_activation`, `multi_room`, `library_playback`, `power`, `sleep_timer`, `digits` |
| BeoLink Converter NL/ML | ASE | built against the vendor spec, unverified | 2026-11-14 | Usually switched off in the lab; its sources were only seen through the V1. | `source_control`, `device_info` |
| Beoconnect Core | Mozart | built against the vendor spec, unverified | 2026-11-14 | Built from B&O's OpenAPI spec without a device; the checklist for a real one is docs/architecture/mozart-hardware-test.md. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `multi_room`, `library_playback`, `seek`, `queue_add`, `queue_next`, `queue_clear`, `shuffle`, `repeat`, `power`, `sound_adjustment`, `bluetooth`, `device_info`, `battery` |
| Beolab 8 | Mozart | built against the vendor spec, unverified | 2026-11-14 | Built from B&O's OpenAPI spec without a device; the checklist for a real one is docs/architecture/mozart-hardware-test.md. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `multi_room`, `library_playback`, `seek`, `queue_add`, `queue_next`, `queue_clear`, `shuffle`, `repeat`, `power`, `sound_adjustment`, `bluetooth`, `device_info`, `battery` |
| Beolab 28 | Mozart | built against the vendor spec, unverified | 2026-11-14 | Built from B&O's OpenAPI spec without a device; the checklist for a real one is docs/architecture/mozart-hardware-test.md. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `multi_room`, `library_playback`, `seek`, `queue_add`, `queue_next`, `queue_clear`, `shuffle`, `repeat`, `power`, `sound_adjustment`, `bluetooth`, `device_info`, `battery` |
| Beosound 2 3rd gen | Mozart | built against the vendor spec, unverified | 2026-11-14 | Built from B&O's OpenAPI spec without a device; the checklist for a real one is docs/architecture/mozart-hardware-test.md. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `multi_room`, `library_playback`, `seek`, `queue_add`, `queue_next`, `queue_clear`, `shuffle`, `repeat`, `power`, `sound_adjustment`, `bluetooth`, `device_info`, `battery` |
| Beosound A5 | Mozart | built against the vendor spec, unverified | 2026-11-14 | Built from B&O's OpenAPI spec without a device; the checklist for a real one is docs/architecture/mozart-hardware-test.md. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `multi_room`, `library_playback`, `seek`, `queue_add`, `queue_next`, `queue_clear`, `shuffle`, `repeat`, `power`, `sound_adjustment`, `bluetooth`, `device_info`, `battery` |
| Beosound A9 5th gen | Mozart | built against the vendor spec, unverified | 2026-11-14 | Built from B&O's OpenAPI spec without a device; the checklist for a real one is docs/architecture/mozart-hardware-test.md. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `multi_room`, `library_playback`, `seek`, `queue_add`, `queue_next`, `queue_clear`, `shuffle`, `repeat`, `power`, `sound_adjustment`, `bluetooth`, `device_info`, `battery` |
| Beosound Balance | Mozart | built against the vendor spec, unverified | 2026-11-14 | Built from B&O's OpenAPI spec without a device; the checklist for a real one is docs/architecture/mozart-hardware-test.md. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `multi_room`, `library_playback`, `seek`, `queue_add`, `queue_next`, `queue_clear`, `shuffle`, `repeat`, `power`, `sound_adjustment`, `bluetooth`, `device_info`, `battery` |
| Beosound Emerge | Mozart | built against the vendor spec, unverified | 2026-11-14 | Built from B&O's OpenAPI spec without a device; the checklist for a real one is docs/architecture/mozart-hardware-test.md. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `multi_room`, `library_playback`, `seek`, `queue_add`, `queue_next`, `queue_clear`, `shuffle`, `repeat`, `power`, `sound_adjustment`, `bluetooth`, `device_info`, `battery` |
| Beosound Level | Mozart | built against the vendor spec, unverified | 2026-11-14 | Built from B&O's OpenAPI spec without a device; the checklist for a real one is docs/architecture/mozart-hardware-test.md. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `multi_room`, `library_playback`, `seek`, `queue_add`, `queue_next`, `queue_clear`, `shuffle`, `repeat`, `power`, `sound_adjustment`, `bluetooth`, `device_info`, `battery` |
| Beosound Premiere | Mozart | built against the vendor spec, unverified | 2026-11-14 | Built from B&O's OpenAPI spec without a device; the checklist for a real one is docs/architecture/mozart-hardware-test.md. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `multi_room`, `library_playback`, `seek`, `queue_add`, `queue_next`, `queue_clear`, `shuffle`, `repeat`, `power`, `sound_adjustment`, `bluetooth`, `device_info`, `battery` |
| Beosound Theatre | Mozart | built against the vendor spec, unverified | 2026-11-14 | Built from B&O's OpenAPI spec without a device; the checklist for a real one is docs/architecture/mozart-hardware-test.md. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `multi_room`, `library_playback`, `seek`, `queue_add`, `queue_next`, `queue_clear`, `shuffle`, `repeat`, `power`, `sound_adjustment`, `bluetooth`, `device_info`, `battery` |

### Spotify

| Model | Driver | Status | Checked | Note | What the server can do |
|---|---|---|---|---|---|
| Spotify Connect (virtual) | Spotify | built against the vendor spec, unverified | 2026-11-14 | Virtual device on the Spotify Web API; no recorded run with a real account or speaker. | `media_controls`, `seek`, `queue`, `queue_jump`, `queue_add`, `shuffle`, `repeat`, `like` |

### ReMoment

| Model | Driver | Status | Checked | Note | What the server can do |
|---|---|---|---|---|---|
| Player (virtual) | ReMoment Player | fake-tested only | 2026-11-14 | Virtual player of this server; covered by tests. | `media_controls`, `volume_control`, `radio_control`, `library_playback`, `seek`, `queue`, `queue_jump`, `queue_add`, `queue_next`, `queue_remove`, `queue_move`, `queue_clear`, `shuffle`, `repeat`, `stream_url` |

### Sonos

| Model | Driver | Status | Checked | Note | What the server can do |
|---|---|---|---|---|---|
| Sonos Speaker | Sonos | built against the vendor spec, unverified | 2026-11-14 | Uses the duncan3dc/sonos library; no hardware run is recorded in the repository docs. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `multi_room`, `library_playback`, `seek`, `queue`, `queue_jump`, `queue_add`, `queue_next`, `queue_remove`, `queue_move`, `queue_clear`, `shuffle`, `repeat`, `sound_adjustment`, `device_info`, `battery`, `stream_url` |

### WiiM

| Model | Driver | Status | Checked | Note | What the server can do |
|---|---|---|---|---|---|
| WiiM Mini | WiiM | built against the vendor spec, unverified | 2026-11-15 | Built from the WiiM HTTP API document (1.2) and tested with fakes; no WiiM in the lab. `php artisan wiim:probe` output from a real one would confirm it. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `library_playback`, `seek`, `queue`, `queue_jump`, `queue_add`, `queue_next`, `queue_remove`, `queue_move`, `queue_clear`, `shuffle`, `repeat`, `power`, `device_info`, `battery`, `stream_url` |
| WiiM Pro | WiiM | built against the vendor spec, unverified | 2026-11-15 | Built from the WiiM HTTP API document (1.2) and tested with fakes; no WiiM in the lab. `php artisan wiim:probe` output from a real one would confirm it. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `library_playback`, `seek`, `queue`, `queue_jump`, `queue_add`, `queue_next`, `queue_remove`, `queue_move`, `queue_clear`, `shuffle`, `repeat`, `power`, `device_info`, `battery`, `stream_url` |
| WiiM Pro Plus | WiiM | built against the vendor spec, unverified | 2026-11-15 | Built from the WiiM HTTP API document (1.2) and tested with fakes; no WiiM in the lab. `php artisan wiim:probe` output from a real one would confirm it. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `library_playback`, `seek`, `queue`, `queue_jump`, `queue_add`, `queue_next`, `queue_remove`, `queue_move`, `queue_clear`, `shuffle`, `repeat`, `power`, `device_info`, `battery`, `stream_url` |
| WiiM Amp | WiiM | built against the vendor spec, unverified | 2026-11-15 | Built from the WiiM HTTP API document (1.2) and tested with fakes; no WiiM in the lab. `php artisan wiim:probe` output from a real one would confirm it. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `library_playback`, `seek`, `queue`, `queue_jump`, `queue_add`, `queue_next`, `queue_remove`, `queue_move`, `queue_clear`, `shuffle`, `repeat`, `power`, `device_info`, `battery`, `stream_url` |
| WiiM Ultra | WiiM | built against the vendor spec, unverified | 2026-11-15 | Built from the WiiM HTTP API document (1.2) and tested with fakes; no WiiM in the lab. `php artisan wiim:probe` output from a real one would confirm it. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `library_playback`, `seek`, `queue`, `queue_jump`, `queue_add`, `queue_next`, `queue_remove`, `queue_move`, `queue_clear`, `shuffle`, `repeat`, `power`, `device_info`, `battery`, `stream_url` |

### UPnP

| Model | Driver | Status | Checked | Note | What the server can do |
|---|---|---|---|---|---|
| Generic MediaRenderer | UPnP | verified on hardware | 2026-10-22 | Verified on a Philips Streamium MCi900 only; other renderers are untested, a WiiM is built from documentation. | `media_controls`, `volume_control`, `radio_control`, `library_playback`, `seek`, `queue`, `queue_jump`, `queue_add`, `queue_next`, `queue_remove`, `queue_move`, `queue_clear`, `shuffle`, `repeat`, `device_info`, `stream_url` |

### Google

| Model | Driver | Status | Checked | Note | What the server can do |
|---|---|---|---|---|---|
| Chromecast | Cast | fake-tested only | 2026-11-20 | What is casting and its transport, built on the Cast v2 protocol and tested with fakes; the receiver status read (cast:probe) was only run against a TV with Chromecast built in, never a dongle. | `media_controls`, `seek` |

### Philips TV

| Model | Driver | Status | Checked | Note | What the server can do |
|---|---|---|---|---|---|
| Android TV | Philips | verified on hardware | 2026-10-27 | Pairing, apps as sources and standby seen on real TVs; remote keys, other apps and waking from full sleep are not. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `library_playback`, `seek`, `queue`, `queue_jump`, `queue_add`, `queue_next`, `queue_remove`, `queue_move`, `queue_clear`, `shuffle`, `repeat`, `power`, `device_info`, `digits`, `remote_control`, `stream_url` |

### Philips Streamium

| Model | Driver | Status | Checked | Note | What the server can do |
|---|---|---|---|---|---|
| Wi-Fi Hi-Fi System | Streamium | verified on hardware | 2026-10-22 | MCi900 web remote (power, radio presets) tested on the unit. | `media_controls`, `volume_control`, `radio_control`, `source_control`, `source_activation`, `library_playback`, `seek`, `queue`, `queue_jump`, `queue_add`, `queue_next`, `queue_remove`, `queue_move`, `queue_clear`, `shuffle`, `repeat`, `power`, `device_info`, `stream_url` |

Capabilities are what the driver offers when the device supports it; a device can list fewer (a generic UPnP renderer declares its own).

<!-- hardware-table:end -->
