<?php

namespace App\Listeners\Device;

use App\Domain\Device\State;
use App\Domain\Playback\SyncGroups;
use App\Events\Device\DeviceStateChanged;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\ProgressUpdated;
use App\Jobs\SyncFollower;
use App\Models\SyncGroup;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps the members of a sync group with their leader (docs/architecture/sync-groups.md), from the events the
 * leader's listener already fires, so it costs no device traffic of its own:
 *
 * - state: pause / resume / stop of the leader is replayed on the members;
 * - track: when the leader starts another track, members that didn't move on by themselves are restarted
 *   on its queue after a short delay;
 * - progress: every few seconds the members' cached positions are compared with the leader's, and a member
 *   further off than the threshold is seeked.
 *
 * Registered after UpdateDeviceCache, so the cache already holds what the event reports. Commands go out as
 * queued SyncFollower jobs.
 */
class SyncGroupMirror
{
    public function handle(object $event): void
    {
        $deviceId = (int) ($event->deviceId ?? 0);
        $groupId = SyncGroups::leaders()[$deviceId] ?? null;

        if ($groupId === null) {
            return;
        }

        $group = null;
        $load = fn () => $group ??= SyncGroup::with('members')->find($groupId);

        match (true) {
            $event instanceof ProgressUpdated => $this->progress($groupId, $load),
            $event instanceof NowPlayingUpdated => $this->nowPlaying($groupId, $event, $load),
            $event instanceof DeviceStateChanged => $this->state($event, $load),
            default => null,
        };
    }

    private function progress(int $groupId, \Closure $load): void
    {
        // Cheap guard first: most progress events fall inside the interval.
        if (!Cache::add("sync:{$groupId}:tick", true, max(1, (int) config('sync.check_interval')))) {
            return;
        }

        if ($group = $load()) {
            app(SyncGroups::class)->correctDrift($group);
        }
    }

    private function nowPlaying(int $groupId, NowPlayingUpdated $event, \Closure $load): void
    {
        $key = SyncGroups::key($event->nowPlaying);

        if (Cache::get("sync:{$groupId}:track") === $key) {
            return;
        }

        Cache::put("sync:{$groupId}:track", $key, now()->addDay());

        foreach ($load()?->members ?? [] as $member) {
            SyncFollower::dispatch($groupId, $member->device_id, 'resync')->delay(now()->addSeconds((int) config('sync.track_change_delay')));
        }
    }

    private function state(DeviceStateChanged $event, \Closure $load): void
    {
        $action = match ($event->state) {
            State::Paused => 'pause',
            State::Playing => $event->previous === State::Paused ? 'play' : null,
            State::Standby => 'stop',
            // Unreachable is often a blip: the members keep playing.
            default => null,
        };

        if ($action === null || !($group = $load())) {
            return;
        }

        foreach ($group->members as $member) {
            SyncFollower::dispatch($group->id, $member->device_id, $action);
        }
    }
}
