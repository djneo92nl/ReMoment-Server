<?php

namespace App\Jobs;

use App\Domain\Playback\Announcer;
use App\Models\Device;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Runs when an announcement's clip has ended: puts the device back as it was (Announcer::restore). */
class RestoreAfterAnnouncement implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $deviceId, public string $run) {}

    public function handle(Announcer $announcer): void
    {
        $device = Device::find($this->deviceId);

        if ($device) {
            $announcer->restore($device, $this->run);
        }
    }
}
