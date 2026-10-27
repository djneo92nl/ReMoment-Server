<?php

namespace App\Jobs;

use App\Domain\Playback\SyncGroups;
use App\Models\Device;
use App\Models\SyncGroup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * One command to a sync group's member, queued so the leader's listener (which fires it) never waits for a
 * device: `play`, `pause`, `stop`, `seek` ($seconds) or `resync` (restart it on the leader's queue unless it
 * already plays what the leader plays).
 */
class SyncFollower implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public int $groupId,
        public int $deviceId,
        public string $action,
        public ?int $seconds = null,
    ) {}

    public function handle(SyncGroups $groups): void
    {
        $group = SyncGroup::with('leader')->find($this->groupId);
        $member = $group?->members()->with('device')->where('device_id', $this->deviceId)->first();

        // The group changed or ended since this was queued.
        if ($group === null || $member === null) {
            return;
        }

        try {
            match ($this->action) {
                'play', 'pause', 'stop' => $groups->control($member->device, $this->action),
                'seek' => $groups->seek($member->device, (int) $this->seconds),
                'resync' => $groups->resyncIfNeeded($group, $member),
            };
        } catch (\Throwable $e) {
            Log::warning("Sync group {$this->groupId}: {$this->action} on ".Device::find($this->deviceId)?->device_name.' failed: '.$e->getMessage());
        }
    }
}
