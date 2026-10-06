# Source controls

Some sources have physical controls of their own that play/pause/next don't cover: a CD changer (change disc), a tape deck, an FM tuner (presets). The server describes them once as a **control profile** and shows the same controls whichever device plays the source:

- a B&O NL/ML converter playing its own CD (the converter is an ASE-like device),
- an ASE/Mozart player that **borrowed** the converter's source (commands go to the player, not the converter),
- a CD changer on a speaker's AUX input, bridged by an ESP.

## How it resolves

1. The device's active source (`now_playing.source`: type, name, connector, category) is matched against the `match` words of each profile in `config/control-profiles.php` (same word matching as `SourceLogo`). First profile wins: `cd`, `tape`, `fm`.
2. A **transport** (`App\Domain\Control\ControlTransport`, listed under `transports` in the config) must support the device. It turns the abstract command id into the real call (ASE one-way command, ESP HTTP call). No transport, no controls.
3. When both hold, the device lists the `source_controls` capability (dynamic, like `battery`).

Profiles are data: add a profile or a command in `config/control-profiles.php`. Command ids are abstract (`next_disc`, `prev_preset`, …).

## Endpoints

```
GET  /api/devices/{id}/controls            → { "profile": "cd", "label": "CD", "controls": [ Control, … ] }
POST /api/devices/{id}/controls/{control}  → { "status": "ok", "control": "next_disc" }
```

`GET` returns `{ "profile": null, "label": null, "controls": [] }` while the active source has no (deliverable) controls. `Control` is `{ id, label, icon, type: "button", group }`; clients may cluster by `group`.

`POST` errors: `422` `unsupported` (no controls for the active source, or the id isn't in the profile), `503` unreachable, `502` `driver_error` (the transport failed).

Clients should re-read `capabilities` / `GET …/controls` when the source changes.

## Status

Phase 1 only: profiles, resolver, capability and API. The ASE one-way transport (needs the command catalog from a real device) and the ESP transport are still to be written, so `transports` is empty and no device reports `source_controls` yet.
