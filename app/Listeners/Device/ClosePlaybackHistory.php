<?php

namespace App\Listeners\Device;

use App\Events\Device\NowPlayingEnded;
use App\Models\Play;

class ClosePlaybackHistory
{
    public function handle(NowPlayingEnded $event): void
    {
        $deviceId = (int) $event->deviceId;
        if ($deviceId === 0) {
            return;
        }

        Play::query()
            ->where('device_id', $deviceId)
            ->whereNull('ended_at')
            ->latest('played_at')
            ->first()
            ?->update(['ended_at' => now()]);
    }
}
