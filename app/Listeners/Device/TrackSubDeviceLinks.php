<?php

namespace App\Listeners\Device;

use App\Domain\SubDevices\LinkActions;
use App\Domain\SubDevices\LinkIndex;
use App\Domain\SubDevices\LinkTransitions;
use App\Jobs\RunLinkActions;
use App\Models\Device;
use Illuminate\Support\Facades\Log;

/**
 * The link side of a device's events (workstream 2a, docs/architecture/sub-devices.md). Registered after
 * UpdateDeviceCache, so the cache already holds the source the event reports.
 *
 * - A host whose input changed (a source reported, standby): the links that became active or ended run their actions
 *   (`meta.on_activate` / `on_deactivate`), whoever changed the input.
 *
 * Runs in the device listener's poll loop, so a failure here is logged and never stops it; the actions themselves are
 * queued jobs.
 */
class TrackSubDeviceLinks
{
    public function handle(object $event): void
    {
        $deviceId = (int) ($event->deviceId ?? 0);

        if ($deviceId <= 0) {
            return;
        }

        try {
            if (LinkIndex::isHost($deviceId) && ($host = Device::find($deviceId)) !== null) {
                foreach (LinkTransitions::observe($host) as [$link, $phase]) {
                    if (LinkActions::has($link, $phase)) {
                        RunLinkActions::dispatch($link->id, $phase);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Sub device links of device {$deviceId}: {$e->getMessage()}");
        }
    }
}
