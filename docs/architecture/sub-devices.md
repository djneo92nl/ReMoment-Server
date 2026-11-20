# Sub devices (workstream 2a)

A **sub device** is a device in its own right (own row, driver, id, card, API and MQTT topic) that is **connected to another device**, its **host**: a BeoLink Converter whose source a player borrows, a Chromecast plugged into a TV's HDMI input. The link adds the relation and the routing between the two; nothing about the sub device's own control changes. Design and decisions: [v1-roadmap.md](v1-roadmap.md) section 2a. Everything here is tested with fakes; **all hardware behaviour is unverified** unless a line says otherwise.

## The link

Table `device_links` (migration `2026_11_20_100000_create_device_links_table`, model `App\Models\DeviceLink`, schema notes in [schema-plan.md](schema-plan.md)):

| Column | |
|---|---|
| `device_id` | the sub device |
| `host_device_id` | the host, the device that selects the sub device |
| `kind` | `borrow` (a source the host borrowed from the sub device: a converter), `input` (an HDMI input), `source` (any other source of the host) |
| `via` | the host's source id or input label that selects the sub device (`hdmi_1`, `HDMI 2`); `''` for a `borrow` link that stands for every source the sub device provides. Never null, so the unique key works |
| `meta` | JSON: link actions and the follow rule (steps 2 and 3, below) |

Unique per `(device_id, host_device_id, kind, via)`. Many to many: a converter serves several players, a TV hosts several boxes. Deleting either device deletes its links (`ON DELETE CASCADE`), the other device is untouched, and removing a link leaves both devices working on their own.

**One level deep** (a decision, not a constraint): a device is a sub device or a host, never both. `DeviceLinks::validate()` runs on every save (`DeviceLink` `saving` hook), so the card, the API and discovery share it: unknown kind, a link to itself, a missing `via` for `input` / `source`, a host that is a sub device, a sub device that is a host all throw `InvalidDeviceLink` (a 422 `invalid` with the message in the API).

## Code (`app/Domain/SubDevices/`)

| Class | What |
|---|---|
| `DeviceLinks` | the one place that validates, creates and reads links: `add()`, `subLinks($host)`, `hostLinks($sub)`, `activeSubLinks($host)`, `activeHostLink($sub)`, `describe($device)` (the `host` and `sub_devices` of the device detail), `withoutNestedSubDevices()` (the grid) |
| `LinkMatcher` | does the host's selected source belong to a link? `via` is compared with the source's id, its input label (`HDMI 1`) and its name, ignoring case and punctuation (`hdmi_1` = `HDMI 1` = `hdmi1`); a `borrow` link with no `via` matches a source id that contains the sub device's own product id (device meta `converter_jid`, `ase_jid`, `mozart_jid`) |
| `ActiveInput` | the source or input a device last reported as selected, cached apart from its now playing (a track notification replaces the now playing and its `source`, the input stays what it was) |
| `LinkState` | `active($link)`: `true` while the host is playing or paused and its `ActiveInput` matches; `false` otherwise; `null` (unknown) when the host's driver does not report its selected source |
| `LinkSuggestions` | links a device reports itself: an ASE player's `BeoZone/System/Products` entries that are our devices (`converter_jid`) as `borrow` links. The Products shape is **unverified**: a `[jid, {...}]` pair and a plain object are both read, `borrowSource` is read as a bool or `{value}` |

`App\Integrations\Contracts\ReportsActiveSource` is a marker contract for a driver whose listener reports the selected source (the two ASE drivers). A host without it, such as a Philips TV whose HDMI input is not readable, keeps its links with `active: null` and nothing is routed on a guess.

## What the API and the pages show

- `GET /api/devices/{id}` gains `host` (`{id, name, via}` of the host that has this device selected, else the oldest link; `null` for no host) and `sub_devices` (`[{id, name, via, active}]`, `active` null while unknown), and a host has the `sub_devices` capability. The list endpoint carries the capability only.
- Admin routes `GET /api/admin/devices/{id}/links`, `GET …/link-suggestions`, `POST /api/admin/device-links`, `PUT|DELETE /api/admin/device-links/{id}`, MCP tool `device_links` (ADMIN): [../api/sub-devices.md](../api/sub-devices.md). The devices routes belong to the always-on `devices` profile feature, so there is no profile gating.
- **Connected devices card** on the device edit page (`Livewire\ConnectedDevices`, shown to the admin only): the sub devices connected to this device and the hosts it is connected to, add from either side, remove, and "Ask the device" for the suggestions above (a network call, so only on that button).
- **Nesting.** Whether a sub device keeps a card in the `/devices` grid is the model flag `standalone` in `config/devices.php` (read by `DeviceModels::standalone()`; a model without the key is standalone, so nothing leaves the grid by accident). A sub device whose model is `standalone => false` (a Chromecast dongle) has **no card in the grid**: the host's card lists it, only while the link is active, and links to its page (it is still a full device: own page, API, MCP). A standalone sub device such as the converter (`standalone => true`) keeps its own card and is also listed on the host's card while the link is active. A sub device none of whose hosts is in the list (hidden host) keeps its card, so a hidden host never makes a device unreachable.

## The converter link

`converter_jid` (device meta) stays the converter's identity: it is what `ConverterListener` and `LinkMatcher` compare with. There was no stored player-to-converter relation to migrate (the borrow switch lives on the player: `borrowSource` in its `BeoZone/System/Products`), so the migration creates no rows. Links are created from the player's Products list (the card's "Ask the device" button, `LinkSuggestions`) or by hand (kind `borrow`, empty `via`). **Unverified:** the Products answer shape.
