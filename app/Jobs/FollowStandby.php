<?php

namespace App\Jobs;

use App\Domain\SubDevices\FollowRule;
use App\Models\DeviceLink;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** The follow rule's delayed standby: the host goes to standby `follow_off_after` minutes after the sub device stopped, if the rule woke it (App\Domain\SubDevices\FollowRule). */
class FollowStandby implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $linkId, public int $stoppedAt) {}

    public function handle(): void
    {
        $link = DeviceLink::find($this->linkId);

        if ($link === null) {
            return;
        }

        try {
            $result = FollowRule::standbyAfterStop($link, $this->stoppedAt);
        } catch (\Throwable $e) {
            Log::warning("Follow rule: link {$link->id} could not put the host in standby: {$e->getMessage()}");

            return;
        }

        Log::debug("Follow rule: link {$link->id} standby: {$result}");
    }
}
