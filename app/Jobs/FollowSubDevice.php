<?php

namespace App\Jobs;

use App\Domain\SubDevices\FollowRule;
use App\Models\DeviceLink;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** The follow rule after its debounce: the sub device started playing, so the host selects the link (App\Domain\SubDevices\FollowRule). */
class FollowSubDevice implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $linkId) {}

    public function handle(): void
    {
        $link = DeviceLink::find($this->linkId);

        if ($link === null) {
            return;
        }

        try {
            $result = FollowRule::follow($link);
        } catch (\Throwable $e) {
            Log::warning("Follow rule: link {$link->id} could not switch the host: {$e->getMessage()}");

            return;
        }

        Log::debug("Follow rule: link {$link->id}: {$result}");
    }
}
