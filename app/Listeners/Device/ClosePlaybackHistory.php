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

        $play = Play::query()
            ->where('device_id', $deviceId)
            ->whereNull('ended_at')
            ->latest('played_at')
            ->first();

        if ($play === null) {
            return;
        }

        $endedAt = now();
        $listenedSeconds = $play->played_at->diffInSeconds($endedAt);

        // Plays under 30 seconds are treated as skips
        $skipped = $listenedSeconds < 30;

        $play->update(['ended_at' => $endedAt, 'skipped' => $skipped]);
    }
}
