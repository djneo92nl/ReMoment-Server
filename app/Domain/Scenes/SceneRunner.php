<?php

namespace App\Domain\Scenes;

use App\Mcp\ApiBridge;
use App\Models\Scene;
use Carbon\CarbonInterface;
use Closure;

/**
 * Runs a scene (workstream 8.1, docs/api/scenes.md): its steps in order, each through the REST API with
 * `ApiBridge`, so a step gets the validation, capability checks, error mapping and Spotify routing of a client call.
 *
 * A step is an operation `{ "operation": "setVolume", "device_id": 3, "params": { "volume": 30 } }` (an operationId of
 * docs/api/openapi.yaml, see SceneOperations) or a delay `{ "type": "delay", "seconds": 5 }`. Every step is validated
 * before the first one runs; the route's own validation can still refuse one at run time. The scene's
 * `stop_on_error` decides whether a failing step ends the run (the steps after it are reported as skipped).
 */
class SceneRunner
{
    public const MAX_STEPS = 50;

    public const MAX_DELAY = 60;        // seconds, one delay step

    public const MAX_TOTAL_DELAY = 300; // seconds, all delays of a scene

    private ?Closure $sleeper;

    private DeviceTimers $timers;

    public function __construct(private readonly ApiBridge $bridge, private readonly SceneOperations $operations, ?Closure $sleeper = null, ?DeviceTimers $timers = null)
    {
        $this->sleeper = $sleeper;
        $this->timers = $timers ?? new DeviceTimers($bridge);
    }

    public static function isDelay(array $step): bool
    {
        return ($step['type'] ?? null) === 'delay';
    }

    public static function hasDelay(array $steps): bool
    {
        return collect($steps)->contains(fn ($s) => is_array($s) && self::isDelay($s));
    }

    /**
     * Errors of a list of steps as `steps.N.field => message` (empty when valid).
     *
     * @return array<string, string>
     */
    public function validate(mixed $steps): array
    {
        if (!is_array($steps) || !array_is_list($steps) || $steps === []) {
            return ['steps' => 'A scene needs at least one step.'];
        }
        if (count($steps) > self::MAX_STEPS) {
            return ['steps' => 'A scene has at most '.self::MAX_STEPS.' steps.'];
        }

        $errors = [];
        $total = 0;
        foreach ($steps as $i => $step) {
            if (!is_array($step)) {
                $errors["steps.{$i}"] = 'A step must be an object.';

                continue;
            }
            if (self::isDelay($step)) {
                $seconds = $step['seconds'] ?? null;
                if (!is_int($seconds) && !is_float($seconds)) {
                    $errors["steps.{$i}.seconds"] = 'Seconds are required.';
                } elseif ($seconds <= 0 || $seconds > self::MAX_DELAY) {
                    $errors["steps.{$i}.seconds"] = 'A delay is 1 to '.self::MAX_DELAY.' seconds.';
                } else {
                    $total += $seconds;
                }

                continue;
            }
            foreach ($this->operations->validateStep($step) as $field => $message) {
                $errors["steps.{$i}.{$field}"] = $message;
            }
        }
        if ($total > self::MAX_TOTAL_DELAY) {
            $errors['steps'] = 'The delays of a scene add up to at most '.self::MAX_TOTAL_DELAY.' seconds.';
        }

        return $errors;
    }

    /** Keeps only the known keys of each step, so a stored scene holds nothing else. */
    public static function normalise(array $steps): array
    {
        return array_map(function ($step) {
            if (!is_array($step)) {
                return $step;
            }
            if (self::isDelay($step)) {
                return ['type' => 'delay', 'seconds' => $step['seconds'] ?? null];
            }

            return array_filter([
                'operation' => $step['operation'] ?? null,
                'device_id' => $step['device_id'] ?? null,
                'params' => ($step['params'] ?? []) === [] ? null : $step['params'],
            ], fn ($v) => $v !== null);
        }, $steps);
    }

    /**
     * Runs the steps of a scene and records the outcome on it. A scene with an alarm (SceneAlarm) sets its start
     * volume first, and ramps the volume up after the steps; that is one more entry in the results (`type: alarm`).
     * While it runs, and a moment after, the scene's own triggers are ignored (loop protection, SceneTriggers).
     *
     * @return array{status: string, results: list<array>} status ok | partial | failed
     */
    public function run(Scene $scene, string $source = 'api'): array
    {
        SceneTriggers::guardRun($scene->id);

        try {
            $steps = $scene->steps ?? [];
            $alarm = $scene->alarm;
            $valid = $this->validate($steps) === [];

            if ($alarm && $valid) {
                // Best effort: a device in standby may not take a volume before its steps wake it.
                $this->bridge->call('PUT', "devices/{$alarm['device_id']}/volume", ['volume' => $alarm['start_volume']]);
            }

            $outcome = $this->execute($steps, (bool) $scene->stop_on_error);

            if ($alarm && $valid && ($outcome['status'] === 'ok' || ($outcome['status'] === 'partial' && !$scene->stop_on_error))) {
                $outcome['results'][] = $this->startAlarm($scene, $alarm, count($outcome['results']));
                $ok = count(array_filter($outcome['results'], fn ($r) => $r['ok']));
                $outcome['status'] = $ok === count($outcome['results']) ? 'ok' : ($ok === 0 ? 'failed' : 'partial');
            }

            $scene->forceFill([
                'last_run_at' => now(),
                'last_run_status' => $outcome['status'],
                'last_run_source' => $source,
                'last_run_results' => $outcome['results'],
            ])->save();
            \App\Domain\HomeAssistant\Hooks::scene($scene, $outcome['status'], $source);

            return $outcome;
        } finally {
            SceneTriggers::releaseRun($scene->id);
        }
    }

    private function startAlarm(Scene $scene, array $alarm, int $index): array
    {
        $base = ['index' => $index, 'type' => 'alarm', 'operation' => null, 'device_id' => $alarm['device_id'], 'minutes' => $alarm['minutes']];

        $device = \App\Models\Device::find($alarm['device_id']);
        if (!$device) {
            return $base + ['ok' => false, 'status' => 404, 'error' => 'not_found: The alarm\'s device does not exist.'];
        }

        try {
            $this->timers->startRamp($device, $alarm['minutes'], $alarm['start_volume'], $alarm['target_volume'], $scene->id);
        } catch (\Throwable $e) {
            return $base + ['ok' => false, 'status' => 500, 'error' => $e->getMessage()];
        }

        return $base + ['ok' => true, 'status' => null, 'error' => null];
    }

    /**
     * @return array{status: string, results: list<array>}
     */
    public function execute(array $steps, bool $stopOnError): array
    {
        // Nothing runs unless every step is valid (a stored scene can go stale: an operation may have been removed).
        if ($errors = $this->validate($steps)) {
            $results = [['index' => null, 'ok' => false, 'status' => 422, 'error' => 'The scene is not valid: '.json_encode($errors, JSON_UNESCAPED_UNICODE)]];

            return ['status' => 'failed', 'results' => $results];
        }

        $results = [];
        $stopped = false;
        foreach ($steps as $i => $step) {
            if ($stopped) {
                $results[] = $this->describe($i, $step) + ['ok' => false, 'skipped' => true, 'status' => null, 'error' => null];

                continue;
            }

            $result = self::isDelay($step) ? $this->delay($i, $step) : $this->operation($i, $step);
            $results[] = $result;
            $stopped = !$result['ok'] && $stopOnError;
        }

        $ok = count(array_filter($results, fn ($r) => $r['ok']));
        $status = $ok === count($results) ? 'ok' : ($ok === 0 ? 'failed' : 'partial');

        return ['status' => $status, 'results' => $results];
    }

    private function describe(int $index, array $step): array
    {
        return self::isDelay($step)
            ? ['index' => $index, 'type' => 'delay', 'seconds' => $step['seconds']]
            : ['index' => $index, 'type' => 'operation', 'operation' => $step['operation'], 'device_id' => $step['device_id'] ?? null];
    }

    private function delay(int $index, array $step): array
    {
        $seconds = $step['seconds'];
        $this->sleeper ? ($this->sleeper)($seconds) : usleep((int) ($seconds * 1_000_000));

        return $this->describe($index, $step) + ['ok' => true, 'status' => null, 'error' => null];
    }

    private function operation(int $index, array $step): array
    {
        $operation = $this->operations->find($step['operation']);
        $params = $step['params'] ?? [];
        $deviceParam = $operation['device_param'];
        if ($deviceParam !== null) {
            $params[$deviceParam] = $step['device_id'];
        }

        // Path parameters go into the URL, the rest is the body (the bridge sends it as JSON, or as the query of a GET).
        $path = preg_replace_callback('/\{([^}]+)\}/', function ($m) use (&$params) {
            $value = $params[$m[1]] ?? '';
            unset($params[$m[1]]);

            return rawurlencode((string) $value);
        }, $operation['path']);

        try {
            [$status, $body] = $this->bridge->call($operation['method'], $path, $params);
        } catch (\Throwable $e) {
            return $this->describe($index, $step) + ['ok' => false, 'status' => 500, 'error' => $e->getMessage()];
        }

        $ok = $status >= 200 && $status < 300;

        return $this->describe($index, $step) + [
            'ok' => $ok,
            'status' => $status,
            'error' => $ok ? null : trim(($body['error'] ?? 'http_'.$status).': '.($body['message'] ?? 'The request failed.')),
        ];
    }

    /** Marks the scene as running and queues its run. */
    public function start(Scene $scene, string $source): void
    {
        SceneTriggers::guardRun($scene->id);
        $scene->forceFill(['last_run_status' => 'running', 'last_run_source' => $source, 'last_run_at' => now()])->save();
        \App\Jobs\RunScene::dispatch($scene->id, $source);
    }

    /** Scenes whose schedule says now (the minute of `$now`, on one of their days) and that did not run this minute. */
    public function due(CarbonInterface $now): \Illuminate\Support\Collection
    {
        return Scene::where('schedule_enabled', true)->where('schedule_time', $now->format('H:i'))->get()
            ->filter(fn (Scene $s) => ($s->schedule_days === null || $s->schedule_days === [] || in_array($now->isoWeekday(), $s->schedule_days, true))
                && ($s->last_run_at === null || $s->last_run_at->lt($now->copy()->startOfMinute())));
    }

    /** Starts every due scene on the queue; the number started. */
    public function runDue(?CarbonInterface $now = null): int
    {
        $due = $this->due($now ?? now());
        foreach ($due as $scene) {
            $this->start($scene, 'schedule');
        }

        return $due->count();
    }
}
