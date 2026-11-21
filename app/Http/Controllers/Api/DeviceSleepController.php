<?php

namespace App\Http\Controllers\Api;

use App\Domain\Device\DeviceCapabilities;
use App\Domain\Scenes\DeviceTimers;
use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The scene-level sleep timer of a device (workstream 8.1, docs/api/scenes.md): after N minutes the volume fades down,
 * the device stops (or goes to standby) and the volume is put back. Server-side, for any device that can be stopped;
 * distinct from the device's own `sleep_timer` capability (`/sleep-timer`, which the device runs itself).
 */
class DeviceSleepController extends Controller
{
    public function __construct(private readonly DeviceTimers $timers) {}

    public function show(Device $device): JsonResponse
    {
        $timer = $this->timers->active($device, DeviceTimers::SLEEP);

        return response()->json(['data' => $timer ? DeviceTimers::resource($timer) : null]);
    }

    public function start(Request $request, Device $device): JsonResponse
    {
        $maxMinutes = (int) config('scenes.sleep.max_minutes');
        $maxFade = (int) config('scenes.sleep.max_fade_seconds');
        $data = $request->validate([
            'minutes' => ['required', 'integer', 'min:1', "max:{$maxMinutes}"],
            'fade_seconds' => ['nullable', 'integer', 'min:0', "max:{$maxFade}"],
            'action' => ['nullable', 'in:stop,standby'],
        ]);

        $fade = (int) ($data['fade_seconds'] ?? config('scenes.sleep.default_fade_seconds'));
        if ($fade > $data['minutes'] * 60) {
            return response()->json(['message' => 'The fade cannot be longer than the timer.', 'errors' => ['fade_seconds' => ['The fade cannot be longer than the timer.']]], 422);
        }

        $capabilities = DeviceCapabilities::for($device);
        if (!in_array('media_controls', $capabilities, true)) {
            return response()->json(['error' => 'unsupported', 'message' => 'This device cannot be stopped, so a sleep timer cannot end its playback.'], 422);
        }
        $action = $data['action'] ?? 'stop';
        if ($action === 'standby' && !in_array('power', $capabilities, true)) {
            return response()->json(['error' => 'unsupported', 'message' => 'This device cannot be put in standby remotely.'], 422);
        }

        $timer = $this->timers->startSleep($device, $data['minutes'], $fade, $action);

        return response()->json(['data' => DeviceTimers::resource($timer)], 201);
    }

    /** Cancels the timer and puts a faded volume back. Cancelling when there is none is not an error. */
    public function cancel(Device $device): JsonResponse
    {
        $timer = $this->timers->active($device, DeviceTimers::SLEEP);
        if ($timer) {
            $this->timers->cancel($timer);
        }

        return response()->json(['status' => 'ok', 'cancelled' => $timer !== null]);
    }
}
