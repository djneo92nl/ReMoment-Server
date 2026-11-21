<?php

namespace App\Integrations\Contracts;

use App\Domain\Media\NowPlaying;
use App\Models\Device;

/** Capability `sessions`: what the server says plays in its client app on a device (found from the server's own session list). */
interface ReportsClientNowPlayingInterface
{
    /** @return NowPlaying|null null when no session of that device plays something @throws \Throwable when the server does not answer */
    public function nowPlayingOn(Device $device): ?NowPlaying;
}
