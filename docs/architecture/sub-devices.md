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
| `LinkIndex` | which devices are a host and which a sub device, from the cache (dropped when a link or a device changes, shared with the listener processes), so a page of devices and every device event cost no query |
| `LinkMeta` | what a link's `meta` may hold, checked on every save (below) |
| `LinkTransitions` | sees a link become active or end from the host's events, and `LinkActions` / `RunLinkActions` run the actions (below) |
| `SubDeviceSources` | the host's source list with its links in it (below) |
| `LinkSuggestions` | links a device reports itself: an ASE player's `BeoZone/System/Products` entries that are our devices (`converter_jid`) as `borrow` links. The Products shape is **unverified**: a `[jid, {...}]` pair and a plain object are both read, `borrowSource` is read as a bool or `{value}` |

`App\Integrations\Contracts\ReportsActiveSource` is a marker contract for a driver whose listener reports the selected source (the two ASE drivers). A host without it, such as a Philips TV whose HDMI input is not readable, keeps its links with `active: null` and nothing is routed on a guess.

## What the API and the pages show

- `GET /api/devices/{id}` gains `host` (`{id, name, via}` of the host that has this device selected, else the oldest link; `null` for no host) and `sub_devices` (`[{id, name, via, active}]`, `active` null while unknown), and a host has the `sub_devices` capability. The list endpoint carries the capability only.
- Admin routes `GET /api/admin/devices/{id}/links`, `GET …/link-suggestions`, `POST /api/admin/device-links`, `PUT|DELETE /api/admin/device-links/{id}`, MCP tool `device_links` (ADMIN): [../api/sub-devices.md](../api/sub-devices.md). The devices routes belong to the always-on `devices` profile feature, so there is no profile gating.
- **Connected devices card** on the device edit page (`Livewire\ConnectedDevices`, shown to the admin only): the sub devices connected to this device and the hosts it is connected to, add from either side, remove, and "Ask the device" for the suggestions above (a network call, so only on that button).
- **Nesting.** Whether a sub device keeps a card in the `/devices` grid is the model flag `standalone` in `config/devices.php` (read by `DeviceModels::standalone()`; a model without the key is standalone, so nothing leaves the grid by accident). A sub device whose model is `standalone => false` (a Chromecast dongle) has **no card in the grid**: the host's card lists it, only while the link is active, and links to its page (it is still a full device: own page, API, MCP). A standalone sub device such as the converter (`standalone => true`) keeps its own card and is also listed on the host's card while the link is active. A sub device none of whose hosts is in the list (hidden host) keeps its card, so a hidden host never makes a device unreachable.

## The converter link

`converter_jid` (device meta) stays the converter's identity: it is what `ConverterListener` and `LinkMatcher` compare with. There was no stored player-to-converter relation to migrate (the borrow switch lives on the player: `borrowSource` in its `BeoZone/System/Products`), so the migration creates no rows. Links are created from the player's Products list (the card's "Ask the device" button, `LinkSuggestions`) or by hand (kind `borrow`, empty `via`). **Unverified:** the Products answer shape.

## Step 2: the host lists and routes its sub devices

### Routing (`App\Domain\Device\DeviceRouting`)

`SpotifyRouting` made one device of a speaker and the Spotify virtual device. That is now one of two **routing rules** (`Routing\RoutingRule`: `target($device, $contract)`, `capabilities($device)`) behind `DeviceRouting`, which every caller that sends a playback command or reports capabilities asks (the REST controllers through `GuardsDeviceAccess::withDriver()`, the web components, sync groups, announcements, playback moves). `SpotifyRouting` keeps the Spotify-only state (routed device, modes, the Spotify device in lists); `DeviceRouting::DELEGATED_CONTRACTS` (transport, seek, the queue contracts, shuffle, repeat, like) is the one list both rules delegate.

- `SubDeviceRoute`: while a link of the host is active (`LinkState` true), a delegated contract goes to the first active sub device whose driver implements it, else stays with the host. **Volume, mute, power, the other sources, multiroom, radio and library playback are not delegated**: they stay with the host. A sub device whose driver lacks a contract (a read-only Cast driver has no queue) leaves that contract with the host. With several active sub devices the oldest link wins. A link with an unknown state (`null`) routes nothing: no routing on a guess.
- Rules are asked in this order: sub device, Spotify (an input a person selected beats a speaker following a cloud session).
- `DeviceRouting::capabilities($host)` is the host's own plus the sub device's delegated ones while a link is active (`media_controls`, `seek`, `queue`, ... appear and go away with the link; the 422 check uses the same set). `modesDeviceId()` says whose shuffle/repeat/like a change belongs to: the sub device that executed it.
- **Now playing.** `DeviceRouting::nowPlayingFor($host)` (the device detail, the device card, the mini player and the player bar) is the active sub device's now playing while that device is playing or paused something with a track or a station, else the host's own (the input). The artwork follows because it is resolved from that now playing, and `now_playing.modes` are the sub device's. **MQTT is not mirrored**: the host's `/data` topic stays what the host reports, the sub device publishes on its own topic (a client that wants the cast on the host's topic is a later decision). History is not duplicated either: only the sub device's own listener records the play.

### The host's source list (`SubDeviceSources`)

`GET /api/devices/{id}/sources` of a host lists its links in its list: a source the host lists itself and a link names (the V1's `hdmi_1`) gets `sub_device: {id, name}` and, when the host does not say, `active` from the link; a link that names no listed source (an input the host does not list) gets an entry of its own (`AvailableSource` with `input` = `via`, `source_id` = `via`, `source_type` = the kind in capitals, category `link`, `sub_device`, `active` while the link is active), after the others, activated by `POST …/sources/activate` with its `source_id`, so a Philips TV's HDMI input would be switched in the same way once its switching is solved. A `borrow` link without `via` has no entry of its own: the sub device's sources are the host's borrowed ones, marked `sub_device`. The stored `device_sources` stay what the driver reported (the entries exist only in the answer).

### Link actions (`meta.on_activate` / `meta.on_deactivate`)

`LinkMeta` checks the whole `meta` on every save, unknown keys included: `on_activate` and `on_deactivate` are lists (at most five) of `{action: power_on | power_off | source, source_id?}` for the **sub device** (`PowerInterface::powerOn()` / `standby()`, `SourceActivationInterface::activateSource()`); a single action object is read as a list of one. Example: a CD changer behind a player's line-in, controlled by an ESP: `{"on_activate": [{"action": "power_on"}], "on_deactivate": [{"action": "power_off"}]}`.

They are run by the **host's events**, not by the call that changed the input, so a remote or the TV's own menu counts as much as a call of ours: `Listeners\Device\TrackSubDeviceLinks` (after `UpdateDeviceCache`, on `NowPlayingUpdated`, `NowPlayingEnded` and `DeviceStateChanged`) asks `LinkTransitions::observe($host)`, which compares each link's `LinkState` with the last one it saw (kept per link in the cache, `device_link:{id}:active`) and returns the links that changed; a link with actions queues `Jobs\RunLinkActions` (the queue worker is needed). Rules: an unreachable host or a host that does not report its source (`LinkState` null) changes nothing (the state is unknown, not inactive, so a network drop does not switch the changer off); standby ends a link; a link seen for the first time counts as inactive before, so an input that is already selected counts as just activated (the actions are meant to be repeatable); an action the sub device cannot do is skipped and logged, a failing one is logged and the next still runs. The Connected devices card does not edit `meta`: use the API or MCP.

`ActiveInput` is written by `UpdateDeviceCache` whenever a now playing carries a source and forgotten with the now playing. **ASE caveat, unverified:** the ASE listener answers `NOW_PLAYING_ENDED` with standby, so a link whose source is music (a borrowed converter source) can end when a track ends; an HDMI input is no track and does not.

### The converter link

`ConverterDigitTransport` (FM presets) now asks the link: the player has a link to a `ConverterDriver` device and one of them is active (the converter's source is selected on the player). A player with **no** link to a converter at all is still judged as before, by the product type `1790` in its source's jid, so an install that borrowed before links existed keeps its presets until the link is made (decision: remove the fallback once every install has its link).
