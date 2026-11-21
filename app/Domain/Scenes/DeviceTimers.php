<?php

namespace App\Domain\Scenes;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Jobs\AdvanceDeviceTimer;
use App\Mcp\ApiBridge;
use App\Models\Device;
use App\Models\DeviceTimer;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Server-side timers of a device (workstream 8.1, docs/api/scenes.md), built from existing REST operations through
 * `ApiBridge` (volume, stop, power), so no device contract is added and every step gets the capability checks,
 * volume limits and error mapping of a client call.
 *
 * - **sleep**: for a device without a native `sleep_timer` (or one you want to fade): after N minutes the volume
 *   is faded down over `fade_seconds`, the device is stopped (or put in standby) and the volume put back to what it
 *   was, so tomorrow's first track is not silent.
 * - **ramp**: the alarm's volume ramp from a start to a target volume over N minutes (SceneAlarm).
 *
 * A timer is a list of timed events (`state.events`: `{at, volume?, finish?}`) on a `device_timers` row. One delayed
 * `AdvanceDeviceTimer` job per event does what is due and queues the next; a job that was late applies only the
 * newest volume of the events that are due. The row decides whether the timer is still wanted (`status`), so a cancel
 * needs no access to the queue. One active timer per device and kind: a new one replaces the old.
 */
class DeviceTimers
{
    public const SLEEP = 'sleep';

    public const RAMP = 'ramp';

    public function __construct(private readonly ApiBridge $bridge) {}

    public function active(Device|int $device, string $kind): ?DeviceTimer
    {
        return DeviceTimer::where('device_id', $device instanceof Device ? $device->id : $device)
            ->where('kind', $kind)->where('status', 'active')->latest('id')->first();
    }

    /**
     * Starts (or replaces) the sleep timer of a device.
     *
     * @param  string  $action  stop | standby
     */
    public function startSleep(Device $device, int $minutes, int $fadeSeconds, string $action = 'stop'): DeviceTimer
    {
        if ($old = $this->active($device, self::SLEEP)) {
            $this->cancel($old); // puts a faded volume back, so the new timer reads the real one
        }

        $now = now();
        $end = $now->copy()->addMinutes($minutes);
        $original = $this->readVolume($device->id);
        $events = [];

        if ($original !== null && $fadeSeconds > 0) {
            $steps = max(1, min((int) config('scenes.sleep.max_fade_steps'), (int) ceil($fadeSeconds / max(1, (int) config('scenes.sleep.fade_step_seconds')))));
            $fadeStart = $end->copy()->subSeconds($fadeSeconds)->getTimestamp();
            $last = $original;
            for ($i = 1; $i <= $steps; $i++) {
                $volume = (int) round($original * (1 - $i / $steps));
                if ($volume === $last) {
                    continue;
                }
                $events[] = ['at' => (int) round($fadeStart + ($i - 1) * $fadeSeconds / $steps), 'volume' => $volume];
                $last = $volume;
            }
        }
        $events[] = ['at' => $end->getTimestamp(), 'finish' => true];

        $timer = DeviceTimer::create([
            'device_id' => $device->id,
            'kind' => self::SLEEP,
            'status' => 'active',
            'starts_at' => $now,
            'ends_at' => $end,
            'state' => [
                'events' => $events, 'index' => 0, 'original_volume' => $original, 'faded' => false,
                'action' => $action, 'fade_seconds' => $fadeSeconds, 'last_volume' => null, 'log' => [],
            ],
        ]);
        $this->schedule($timer);

        return $timer;
    }

    /** Starts (or replaces) the volume ramp of an alarm; its first level is set at once. */
    public function startRamp(Device $device, int $minutes, int $from, int $to, ?int $sceneId = null): DeviceTimer
    {
        if ($old = $this->active($device, self::RAMP)) {
            $this->cancel($old);
        }

        $now = now();
        $end = $now->copy()->addMinutes($minutes);
        $total = $minutes * 60;
        $steps = max(2, min((int) config('scenes.alarm.max_steps'), (int) ceil($total / max(1, (int) config('scenes.alarm.step_seconds')))));
        $events = [];
        $last = null;
        for ($i = 0; $i <= $steps; $i++) {
            $volume = (int) round($from + ($to - $from) * $i / $steps);
            if ($volume === $last && $i < $steps) {
                continue;
            }
            $events[] = ['at' => $now->getTimestamp() + (int) round($total * $i / $steps), 'volume' => $volume] + ($i === $steps ? ['finish' => true] : []);
            $last = $volume;
        }

        $timer = DeviceTimer::create([
            'device_id' => $device->id,
            'kind' => self::RAMP,
            'status' => 'active',
            'scene_id' => $sceneId,
            'starts_at' => $now,
            'ends_at' => $end,
            'state' => ['events' => $events, 'index' => 0, 'last_volume' => null, 'from' => $from, 'to' => $to, 'log' => []],
        ]);
        $this->advance($timer->id, $now); // the first level now

        return $timer;
    }

    /** Ends a timer that is still active. A faded sleep timer puts the volume back; a ramp leaves it where it is. */
    public function cancel(DeviceTimer $timer, string $status = 'cancelled'): void
    {
        if (!$timer->isActive()) {
            return;
        }

        $state = $timer->state;
        if ($timer->kind === self::SLEEP && ($state['faded'] ?? false) && ($state['original_volume'] ?? null) !== null) {
            $this->restoreVolume($timer->device_id, (int) $state['original_volume']);
        }

        $timer->forceFill(['status' => $status, 'finished_at' => now()])->save();
    }

    /** Does what is due for a timer and queues the next event. Safe to call early, late or twice. */
    public function advance(int $id, ?CarbonInterface $now = null): void
    {
        $timer = DeviceTimer::find($id);
        if (!$timer || !$timer->isActive()) {
            return;
        }

        $now ??= now();
        $horizon = $now->getTimestamp() + (int) config('scenes.timer_tolerance_seconds');
        $state = $timer->state;
        $events = $state['events'];
        $index = (int) $state['index'];

        $due = [];
        while ($index < count($events) && $events[$index]['at'] <= $horizon) {
            $due[] = $events[$index++];
        }
        if ($due === []) {
            return;
        }

        $firstOfRamp = (int) $state['index'] === 0;
        $volume = null;
        $finish = false;
        foreach ($due as $event) {
            $volume = $event['volume'] ?? $volume;
            $finish = $finish || ($event['finish'] ?? false);
        }

        $timer->state = ['index' => $index] + $state;
        $status = 'active';

        if ($timer->kind === self::RAMP) {
            $status = $this->advanceRamp($timer, $volume, $firstOfRamp);
        } else {
            if ($volume !== null) {
                $this->fade($timer, $volume);
            }
            if ($finish) {
                $this->finishSleep($timer);
                $status = 'done';
            }
        }

        if ($status === 'active' && $finish) {
            $status = 'done';
        }
        if ($status !== 'active') {
            $timer->status = $status;
            $timer->finished_at = now();
        }
        $timer->save();

        if ($status === 'active') {
            $this->schedule($timer);
        }
    }

    /** The timer as the API shows it. */
    public static function resource(DeviceTimer $timer): array
    {
        $state = $timer->state;

        return [
            'id' => $timer->id,
            'device_id' => $timer->device_id,
            'kind' => $timer->kind,
            'status' => $timer->status,
            'started_at' => $timer->starts_at?->toIso8601String(),
            'ends_at' => $timer->ends_at?->toIso8601String(),
            'remaining_seconds' => $timer->isActive() ? max(0, (int) now()->diffInSeconds($timer->ends_at, false)) : 0,
        ] + ($timer->kind === self::SLEEP
            ? ['action' => $state['action'] ?? 'stop', 'fade_seconds' => $state['fade_seconds'] ?? 0, 'original_volume' => $state['original_volume'] ?? null]
            : ['scene_id' => $timer->scene_id, 'from' => $state['from'] ?? null, 'to' => $state['to'] ?? null]);
    }

    private function schedule(DeviceTimer $timer): void
    {
        $state = $timer->state;
        $next = $state['events'][$state['index']] ?? null;
        if ($next === null) {
            return;
        }

        AdvanceDeviceTimer::dispatch($timer->id)->delay(Carbon::createFromTimestamp(max($next['at'], now()->getTimestamp())));
    }

    /** @return string the timer's status afterwards */
    private function advanceRamp(DeviceTimer $timer, ?int $volume, bool $first): string
    {
        $state = $timer->state;

        if (!$first) {
            // Someone took over: the volume is not where the ramp left it.
            $last = $state['last_volume'];
            $read = $last === null ? null : $this->readVolume($timer->device_id);
            if ($read !== null && abs($read - $last) > (int) config('scenes.alarm.takeover_margin')) {
                $this->log($timer, 'takeover', true, null);

                return 'taken_over';
            }
            // The music was stopped or the device switched off: nothing left to wake up to.
            if (DeviceCache::getState($timer->device_id) === State::Standby) {
                $this->log($timer, 'standby', true, null);

                return 'stopped';
            }
        }

        if ($volume !== null) {
            [$status, $body] = $this->bridge->call('PUT', "devices/{$timer->device_id}/volume", ['volume' => $volume]);
            $ok = $status >= 200 && $status < 300;
            $state = $timer->state;
            if ($ok) {
                $state['last_volume'] = (int) ($body['volume'] ?? $volume);
                $timer->state = $state;
            }
            $this->log($timer, "volume {$volume}", $ok, $ok ? null : ($body['error'] ?? "http_{$status}"));
        }

        return 'active';
    }

    private function fade(DeviceTimer $timer, int $volume): void
    {
        [$status, $body] = $this->bridge->call('PUT', "devices/{$timer->device_id}/volume", ['volume' => $volume]);
        $ok = $status >= 200 && $status < 300;
        if ($ok) {
            $state = $timer->state;
            $state['faded'] = true;
            $timer->state = $state;
        }
        $this->log($timer, "fade to {$volume}", $ok, $ok ? null : ($body['error'] ?? "http_{$status}"));
    }

    /** Stop, put the volume back, then (optionally) standby: in that order, so nothing audible follows the restore. */
    private function finishSleep(DeviceTimer $timer): void
    {
        $id = $timer->device_id;
        $state = $timer->state;

        [$status, $body] = $this->bridge->call('POST', "devices/{$id}/stop");
        $this->log($timer, 'stop', $status >= 200 && $status < 300, $status < 300 ? null : ($body['error'] ?? "http_{$status}"));

        if (($state['faded'] ?? false) && ($state['original_volume'] ?? null) !== null) {
            $this->restoreVolume($id, (int) $state['original_volume']);
            $this->log($timer, 'restore volume', true, null);
        }

        if (($state['action'] ?? 'stop') === 'standby') {
            [$status, $body] = $this->bridge->call('PUT', "devices/{$id}/power", ['on' => false]);
            $this->log($timer, 'standby', $status >= 200 && $status < 300, $status < 300 ? null : ($body['error'] ?? "http_{$status}"));
        }
    }

    private function restoreVolume(int $deviceId, int $volume): void
    {
        $this->bridge->call('PUT', "devices/{$deviceId}/volume", ['volume' => $volume]);
    }

    private function readVolume(int $deviceId): ?int
    {
        try {
            [$status, $body] = $this->bridge->call('GET', "devices/{$deviceId}/volume");
        } catch (\Throwable) {
            return null;
        }

        return $status >= 200 && $status < 300 && isset($body['volume']) ? (int) $body['volume'] : null;
    }

    /** Keeps the last 20 things a timer did, for GET and for finding out why it did not do what was expected. */
    private function log(DeviceTimer $timer, string $step, bool $ok, ?string $error): void
    {
        $state = $timer->state;
        $state['log'] = array_slice([...($state['log'] ?? []), ['at' => now()->toIso8601String(), 'step' => $step, 'ok' => $ok, 'error' => $error]], -20);
        $timer->state = $state;
    }
}
