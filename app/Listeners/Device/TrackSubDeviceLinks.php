<?php

namespace App\Listeners\Device;

use App\Domain\Device\State;
use App\Domain\SubDevices\FollowRule;
use App\Domain\SubDevices\LinkActions;
use App\Domain\SubDevices\LinkIndex;
use App\Domain\SubDevices\LinkTransitions;
use App\Events\Device\DeviceStateChanged;
use App\Jobs\RunLinkActions;
use App\Models\Device;
use Illuminate\Support\Facades\Log;

/**
 * The link side of a device's events (workstream 2a, docs/architecture/sub-devices.md). Registered after
 * UpdateDeviceCache, so the cache already holds the source the event reports.
 *
 * - A host whose input changed (a source reported, standby): the links that became active or ended run their actions
 *   (`meta.on_activate` / `on_deactivate`), whoever changed the input; a link that ended clears the follow rule's mark that
 *   it woke the host.
 * - A sub device whose state changed: starting to play (from not playing) starts the follow rule, going to standby (its
 *   session ended, the idle backdrop included) starts the host's delayed standby (`FollowRule`).
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
                    if ($phase === LinkTransitions::DEACTIVATE) {
                        FollowRule::forgetWoke($link);
                    }

                    if (LinkActions::has($link, $phase)) {
                        RunLinkActions::dispatch($link->id, $phase);
                    }
                }
            }

            if ($event instanceof DeviceStateChanged && LinkIndex::isSub($deviceId) && ($sub = Device::find($deviceId)) !== null) {
                $playing = [State::Playing, State::Paused];

                if ($event->state === State::Playing && !in_array($event->previous, $playing, true)) {
                    FollowRule::sessionStarted($sub);
                } elseif ($event->state === State::Standby && in_array($event->previous, $playing, true)) {
                    FollowRule::sessionStopped($sub);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Sub device links of device {$deviceId}: {$e->getMessage()}");
        }
    }
}
