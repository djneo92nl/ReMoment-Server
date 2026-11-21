<?php

namespace App\Listeners\Device;

use App\Domain\Profile\Profile;
use App\Domain\Scenes\SceneTriggers;
use App\Events\Device\DeviceStateChanged;
use App\Events\Device\NowPlayingUpdated;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Device events that can start a scene (docs/api/scenes.md, "Triggers"): the device changed state, or its source
 * changed. Runs inside the device listeners, so it only reads the cached trigger index and never throws; the scene
 * itself is queued (SceneTriggers, debounce and loop protection).
 */
class TriggerScenes
{
    public function __construct(private readonly SceneTriggers $triggers) {}

    public function handleState(DeviceStateChanged $event): void
    {
        if (!Profile::allows('scenes')) {
            return;
        }

        try {
            $this->triggers->deviceState($event->deviceId, $event->state);
        } catch (\Throwable $e) {
            Log::warning("Scene trigger (state) failed: {$e->getMessage()}");
        }
    }

    public function handleNowPlaying(NowPlayingUpdated $event): void
    {
        $deviceId = (int) $event->deviceId;
        $source = $event->nowPlaying->source;
        if ($source === null || !Profile::allows('scenes')) {
            return;
        }

        try {
            if (!SceneTriggers::hasSourceTrigger($deviceId)) {
                return;
            }

            $signature = ($source->name ?? '').'|'.($source->input() ?? '');
            $previous = Cache::get("scene_trigger_source_{$deviceId}");
            if ($previous === $signature) {
                return;
            }
            Cache::put("scene_trigger_source_{$deviceId}", $signature, 86400);

            // The first report of a device is not a change.
            if ($previous !== null) {
                $this->triggers->deviceSource($deviceId, [$source->name, $source->input()]);
            }
        } catch (\Throwable $e) {
            Log::warning("Scene trigger (source) failed: {$e->getMessage()}");
        }
    }
}
