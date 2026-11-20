<?php

namespace App\Jobs;

use App\Domain\SubDevices\LinkActions;
use App\Models\DeviceLink;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** The on_activate / on_deactivate actions of one link, queued because they talk to the sub device (docs/architecture/sub-devices.md). */
class RunLinkActions implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $linkId, public string $phase) {}

    public function handle(): void
    {
        $link = DeviceLink::with('device')->find($this->linkId);

        if ($link !== null) {
            LinkActions::run($link, $this->phase);
        }
    }
}
