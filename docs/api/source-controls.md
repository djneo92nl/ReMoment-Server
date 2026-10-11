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

## Transports

A transport (listed under `transports` in `config/control-profiles.php`, first that `supports()` the device wins) also says which commands of the profile it can really send (`commands()`); the others are left out of `GET …/controls` and answer `422 unsupported`.

### `ConverterDigitTransport` (FM presets of a converter's tuner)

For a B&O NL/ML converter's **RADIO** source played on an ASE player that has **borrowed** it (`App\Integrations\BangOlufsen\Ase\Control\ConverterDigitTransport`). The source is recognised by its type (`source_types: ['RADIO']`, exact — a net radio is `NET RADIO`) and by the converter's product type `1790` in the source's jid; the player must be an ASE `MusicPlayerDriver`/`VideoPlayerDriver`.

- Commands: `preset_1` … `preset_9`. Each is `POST BeoZone/Zone/Digits {"digits": N}` **on the player**, not on the converter. `prev_preset`, `next_preset`, `tune_up` and `tune_down` are not offered: the API has only single digits and no relative step.
- Why the player: the player forwards the key to the converter as a Beo4 telegram (key `0x01…0x09`, then release `0x7e`), which the converter puts on the Master Link bus. The same call sent to the converter itself is accepted (`200`) but logged as an internal IR command the tuner ignores. Verified on a BeoSound Moment (converter at the Ouverture's Master Link).
- Borrowing must be switched on for the converter on that player (`PUT BeoZone/System/Products/{converter jid}` with `{"products": {"borrowSource": true}}`) and RADIO selected on the player (`POST BeoZone/Zone/ActiveSources`). Details in `docs/architecture/ase-api-map.md`.

## Status

Profiles, resolver, capability and API are in place. The ASE converter transport above exists; the generic ASE one-way command transport (needs the command catalog from a real device) and the ESP transport are still to be written, so only a player playing a converter's RADIO source reports `source_controls`.
