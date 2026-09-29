<?php

namespace App\Events\Device;

use App\Domain\Device\State;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by DeviceCache::updateState() only when the cached state actually
 * transitions (e.g. playing → paused), not on every heartbeat write.
 */
class DeviceStateChanged
{
    use Dispatchable;

    public function __construct(
        public int $deviceId,
        public State $state,
        public ?State $previous,
    ) {}
}
