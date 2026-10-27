# Sync groups ("fake multiroom")

Native multiroom (`MultiRoomInterface`: ASE, Sonos, Mozart) only joins devices of one platform, and a generic UPnP renderer has none. A **sync group** is the server-side substitute: a leader plays as always, the members play the same thing, and the server keeps them together. Nothing is stored on a device; the group is two tables and a listener.

Code: `App\Domain\Playback\SyncGroups` (make/end groups, start, drift), `App\Listeners\Device\SyncGroupMirror` (the events), `App\Jobs\SyncFollower` (one command to a member), models `SyncGroup` / `SyncGroupMember`, `config/sync.php`, API `docs/api/sync-groups.md`, UI the "Sync groups" section of `/multiroom` (`Livewire\SyncGroupManager`).

## What can be shared

Library tracks (own files, DLNA) and radio stations. **Not Spotify**: it plays on one Connect device, so a group whose leader plays Spotify starts no member (`SyncGroupException`). A device can follow when it plays library tracks (`LibraryPlaybackInterface`) or radio (`RadioControlInterface`): capability `sync_group`. A device is in one group only (leader or member).

## How it works

- **Start.** A member is started with the *move* machinery (`PlaybackMover`, docs/api/playback-move.md): the leader's queue from the current track, started on the member, which then seeks to where the leader is *when it plays* (the mover asks for the position at that moment, not at capture) plus the member's offset. The leader is not touched.
- **State.** `DeviceStateChanged` of the leader: paused → members pause, playing again → members play, standby → members stop. `unreachable` is ignored (often a blip).
- **Track changes.** `NowPlayingUpdated` of the leader with another track (compared by name + artist, or the station): after `sync.track_change_delay` seconds every member that is not already playing it is restarted on the leader's queue. The delay matters: a UPnP/Sonos member advances its own queue when its track ends, usually within a second of the leader, and is then left alone. A "next" on the leader reaches the members this way too.
- **Drift.** `ProgressUpdated` of the leader, at most every `sync.check_interval` seconds: each member's cached position is compared with the leader's plus its offset, and a member off by more than `sync.drift_threshold` seconds gets a `seek`. A member just seeked is left alone for `sync.seek_cooldown` seconds, because its reported position lags. Radio and other live streams can't be seeked, so they start together and are never corrected.
- **Offset.** Per member, milliseconds, in the UI as −/+ 250 ms steps: positive plays ahead of the leader. It compensates a device that starts late or buffers more. Seeks are whole seconds, so the trim takes effect in whole seconds at the next correction.

## Precision, honestly

Positions are the listener's last report (polled each second) in whole seconds, and `seek` is whole seconds. Expect the members within about **±2 s** of the leader: fine for neighbouring rooms and background music, not for two speakers in one room (echo). A tighter threshold would only make members seek at every report, which is audible. Real accuracy needs a shared clock on the devices, which none of these protocols give.

## Where it runs

No loop of its own. The mirror is an event listener registered in `AppServiceProvider` (after `UpdateDeviceCache`, because it reads the cache that event wrote), so it runs inside the leader's `device:listen` child process and costs no device traffic: leaders are looked up from one cached map (`SyncGroups::leaders()`, forgotten when groups change), positions come from the cache. Every command to a member is a queued `SyncFollower` job, so the leader's poll never waits for a device. The queue worker picks it up within its `--sleep` (3 s): a correction lands a few seconds after the drift was seen.

## Limits and not yet

- A member that was started by hand elsewhere is taken over at the next track change.
- No per-member volume link: volumes stay independent.
- No automatic latency measurement; the offset is manual.
- The members' own listeners still advance their own queue at a track end (that is what keeps them close); a member that can't play a track the leader plays skips it, then looks mismatched and is restarted at each leader track change.
