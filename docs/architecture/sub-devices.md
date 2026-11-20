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

## Step 3: the follow rule and the Chromecast on the Beoplay V1

### The Chromecast as a device (`app/Integrations/Cast/`)

The Cast protocol pieces existed (`CastConnection`, `CastMessage`, `CastNowPlaying`, `cast:probe`); step 3 adds the device around them:

- Model `Google` / `Chromecast` in `config/devices.php`, driver `Cast\MusicPlayerDriver`, `standalone => false` (no grid card when linked, see above). Added by hand on `/devices/create` (no discovery yet: mDNS `_googlecast._tcp` is not built). A TV with Chromecast built in is not this model: Cast there is a second transport of the TV itself (the Philips listener already reads it).
- `Services\DeviceListener` (registered under `listeners`): every 4 s `CastNowPlaying::read()`; an app with or without a media session is `NowPlayingUpdated` (title, artwork, state, position, `ProgressUpdated`), the idle backdrop (`isIdleScreen`, app `E8C28D3C`), no app or standby is `Standby` (`NowPlayingEnded` when it was playing), a receiver that does not answer is `Unreachable` and backs off. `isActiveInput` is not used. **Ambient app: the backdrop is never a cast session**, so it never starts the follow rule.
- `Cast\MusicPlayerDriver` implements `MediaControlsInterface` and `SeekInterface` only: `CastControls` connects, finds the running app and its media session and sends the media-namespace command (`PLAY`, `PAUSE`, `STOP`, `QUEUE_NEXT`, `QUEUE_PREV`, `SEEK`). Nothing casting, or an app with no media session, is a 422 `unsupported`. No volume, power, sources or queue: the TV has those, which is why the host keeps them. **The commands are unverified on a real Chromecast.**

So, with the dongle linked to the V1 (`kind` `input`, `via` `hdmi_1`) and `hdmi_1` selected, `POST /api/devices/{V1}/pause` pauses the cast, the V1's card shows the cast's title and artwork, and its source list marks `hdmi_1` with the Chromecast.

### The follow rule (`App\Domain\SubDevices\FollowRule`)

The other direction of a link: the sub device starts playing, the host selects the link. Behind a TV that has no CEC this is what makes casting wake the TV: the Chromecast reports a cast session, and the rule activates `hdmi_1` on the V1, which both wakes it and switches it to that input.

Settings in the link's `meta`: `follow` `off` | `from_standby` | `always` (**default `from_standby`**) and `follow_off_after` (minutes, or null). Only `input` and `source` links follow (the rule activates the link's `via` on the host); the host's driver must implement `SourceActivationInterface`.

- **Trigger.** `TrackSubDeviceLinks` sees a sub device's state go to Playing from not playing (Standby, Unreachable, none: a pause and resume is not a new session) and `FollowRule::sessionStarted()` queues `Jobs\FollowSubDevice` after `follow_debounce_seconds` (`config/sub-devices.php`, `SUB_DEVICE_FOLLOW_DEBOUNCE`, default 5). One job per link and window, so a burst of app starts makes one. The job checks again that the sub device is still playing.
- **Guard.** `from_standby`: switch only when the host is in standby; a host already on the link's input is left as it is (`already_on_input`); a host on another input is never switched (`host_busy`), so a cast does not interrupt the set-top box. `always` also takes the screen from another input. `off` does nothing. An unreachable host is left alone. A host without source activation is `cannot_switch`.
- **Turning off.** With `follow_off_after` set, the host goes to standby (`PowerInterface::standby()`) that long after the sub device goes back to standby (its session ended; paused counts as a session), **only if the rule woke it**: the rule keeps `meta.state.woke_host` (and `woke_at`) on the link when it switched a host that was in standby. `always` taking over a host that was on does not set it. `Jobs\FollowStandby` re-checks when it runs: still woken by the rule, this is the latest stop, the sub device is still stopped, the link is still active (the host is still on that input; else the mark is cleared, `host_moved`). The mark is also cleared as soon as the link ends (`TrackSubDeviceLinks` on a deactivate transition: the host went to another input or to standby), so a TV someone turned on or moved later is never turned off.
- **Our own switch against someone else's.** Nothing needs to tell them apart: the rule's own switch produces a link that becomes active, which the host's events treat like any other change (link actions run, the mark stays); the mark is only cleared when the link ends. The one clash was the default input on wake: a wake the rule causes would have `SwitchToDefaultSource` take the screen back, so the rule marks the host (`device:{id}:follow_switched`, 60 s) and `SwitchToDefaultSource` leaves it alone for that long.
- **Needs.** The sub device must stay reachable while the host is in standby (a Chromecast powered from the TV's USB port goes off with it: it needs its own adapter), and the host must switch input by API (the V1 does).
- **Queue.** Both jobs run on the queue (the worker must be running); the delays are the queue's, so the sync queue of the tests runs them at once (a test with `Queue::fake()` checks the delay).

### The Philips TV

The Philips TV stays a **manual link with an unknown state**: its driver does not implement `ReportsActiveSource` (its HDMI input is not readable, see [philips-tv.md](philips-tv.md)), so `active` is `null`, nothing is routed and no link action or standby runs on a guess. The follow rule can still switch it through its `via` if source activation ever works for an input; until then `cannot_switch` or the driver's own failure is logged.

**After an upgrade** restart `device:listen` (the events now reach `TrackSubDeviceLinks`, and a Chromecast gets its listener) and the queue worker (the jobs are new).

## Unverified, summarised

Everything above is tested with fakes only. On hardware, still to check: the shape of an ASE player's `BeoZone/System/Products` (the suggestions); that the V1's `SOURCE` notification carries id `hdmi_1` and the connector for the SET-TOP BOX input, and that it arrives when the input is switched by the rule, a remote or the menu; the wake latency from `hdmi_1` activation to the input showing; what a Chromecast reports to a CASTV2 connection while its HDMI is dark and whether the dongle stays reachable with the V1 in standby (its power); the app id of the backdrop on the owner's dongle; the Cast media commands (`PAUSE`, `QUEUE_NEXT`, `SEEK`) on a real session; that the ASE listener's `NOW_PLAYING_ENDED` does not end a link whose source is music while a track merely ends (the caveat above); the converter's FM presets through the link.

## Owner decisions taken for the safe choice

1. A player with no link to a converter keeps being judged by the jid in `ConverterDigitTransport` (the fallback), so existing installs keep their presets until linked. Remove the fallback once every install has its link.
2. Links do not migrate automatically: there was no stored player-to-converter relation, and the Products answer shape is unverified, so links come from the card's "Ask the device" or by hand.
3. The host's MQTT topic does not mirror the sub device's now playing (the sub device publishes on its own topic); the host's REST detail, web card, mini player and player bar do show it.
4. A sub device whose model does not say `standalone` is standalone (keeps its grid card); only the Chromecast model says `false`.
5. The rule's default is `from_standby` for every `input` / `source` link, as decided; a link whose host cannot switch by source simply logs `cannot_switch`.
6. The `meta` of a link (actions, follow rule) is edited through the API and MCP only, not on the Connected devices card.
