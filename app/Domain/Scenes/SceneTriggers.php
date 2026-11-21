<?php

namespace App\Domain\Scenes;

use App\Domain\Device\State;
use App\Models\Device;
use App\Models\Scene;
use Illuminate\Support\Facades\Cache;

/**
 * What starts a scene besides a person, the clock and the API (workstream 8.1, docs/api/scenes.md): a message on an
 * MQTT topic filter, or an event of a device (it went to `playing`/`standby`/..., or its source changed).
 *
 * A scene's `triggers` is a list, validated and normalised on save (`validate`, `normalise`):
 *   {type: "mqtt", topic: "home/doorbell/#", payload?: "ring"}            payload: the whole message must equal it
 *   {type: "device", device_id, event: "state", value: "playing"}          value: playing | paused | standby | unreachable
 *   {type: "device", device_id, event: "source", value?: "HDMI 2"}         value: the source's name or input, any change when left out
 *
 * Starting is always queued (`SceneRunner::start`, source `trigger`), because the callers are the device listeners
 * and the MQTT subscriber, which must not wait for a scene. Two guards keep it from running away:
 *   - **debounce**: after a trigger starts a scene, its other triggers are ignored for `trigger_debounce` seconds;
 *   - **loop protection**: while a scene runs and for `scenes.triggers.loop_guard_seconds` after, its triggers are
 *     ignored, so the events its own steps cause (it plays, the device reports `playing`) cannot start it again.
 * A loop across two scenes (A starts B, B starts A) is only slowed down by the debounce.
 *
 * The triggers of all scenes are kept as one cached index, forgotten whenever a scene is saved or deleted, so the
 * device event listeners (which run for every progress report) cost one cache read.
 */
class SceneTriggers
{
    private const INDEX_KEY = 'scene_trigger_index';

    // ---- Validation ----

    /**
     * Errors of a list of triggers as `triggers.N.field => message` (empty when valid or null).
     *
     * @return array<string, string>
     */
    public static function validate(mixed $triggers): array
    {
        if ($triggers === null || $triggers === []) {
            return [];
        }
        if (!is_array($triggers) || !array_is_list($triggers)) {
            return ['triggers' => 'Triggers are a list.'];
        }
        $max = (int) config('scenes.triggers.max');
        if (count($triggers) > $max) {
            return ['triggers' => "A scene has at most {$max} triggers."];
        }

        $errors = [];
        foreach ($triggers as $i => $trigger) {
            if (!is_array($trigger)) {
                $errors["triggers.{$i}"] = 'A trigger must be an object.';

                continue;
            }
            $type = $trigger['type'] ?? null;
            $found = match ($type) {
                'mqtt' => self::validateMqtt($trigger),
                'device' => self::validateDevice($trigger),
                default => ['type' => 'The type is mqtt or device.'],
            };
            foreach ($found as $field => $message) {
                $errors["triggers.{$i}.{$field}"] = $message;
            }
        }

        return $errors;
    }

    /** @return array<string, string> */
    private static function validateMqtt(array $trigger): array
    {
        $errors = [];
        $topic = $trigger['topic'] ?? null;
        if (!is_string($topic) || $topic === '') {
            $errors['topic'] = 'A topic filter is required.';
        } elseif (!self::validFilter($topic)) {
            $errors['topic'] = 'This is not a valid MQTT topic filter (+ is one level, # the rest and only last).';
        } else {
            foreach ((array) config('scenes.triggers.blocked_topics') as $blocked) {
                if (self::filtersOverlap($topic, $blocked)) {
                    $errors['topic'] = 'The server publishes on this topic itself; a scene cannot be triggered by it.';
                    break;
                }
            }
        }

        $payload = $trigger['payload'] ?? null;
        if ($payload !== null && (!is_string($payload) || mb_strlen($payload) > 200)) {
            $errors['payload'] = 'The payload is a text of at most 200 characters.';
        }
        foreach (array_diff(array_keys($trigger), ['type', 'topic', 'payload']) as $name) {
            $errors[$name] = 'Unknown field.';
        }

        return $errors;
    }

    /** @return array<string, string> */
    private static function validateDevice(array $trigger): array
    {
        $errors = [];
        $device = $trigger['device_id'] ?? null;
        if (!is_int($device)) {
            $errors['device_id'] = 'A device is required.';
        } elseif (!Device::whereKey($device)->exists()) {
            $errors['device_id'] = 'This device does not exist.';
        }

        $event = $trigger['event'] ?? null;
        $value = $trigger['value'] ?? null;
        if ($event === 'state') {
            if (!is_string($value) || State::tryFrom($value) === null) {
                $errors['value'] = 'The state is one of '.implode(', ', array_map(fn (State $s) => $s->value, State::cases())).'.';
            }
        } elseif ($event === 'source') {
            if ($value !== null && (!is_string($value) || $value === '' || mb_strlen($value) > 80)) {
                $errors['value'] = 'The source is a name of at most 80 characters, or left out for any change.';
            }
        } else {
            $errors['event'] = 'The event is state or source.';
        }
        foreach (array_diff(array_keys($trigger), ['type', 'device_id', 'event', 'value']) as $name) {
            $errors[$name] = 'Unknown field.';
        }

        return $errors;
    }

    /** Only the known keys of each trigger (call after `validate`). */
    public static function normalise(?array $triggers): ?array
    {
        if ($triggers === null || $triggers === []) {
            return null;
        }

        return array_map(fn (array $t) => $t['type'] === 'mqtt'
            ? array_filter(['type' => 'mqtt', 'topic' => $t['topic'], 'payload' => $t['payload'] ?? null], fn ($v) => $v !== null)
            : array_filter(['type' => 'device', 'device_id' => $t['device_id'], 'event' => $t['event'], 'value' => $t['value'] ?? null], fn ($v) => $v !== null),
            $triggers);
    }

    public static function debounceErrors(mixed $seconds): array
    {
        $min = (int) config('scenes.triggers.debounce_min');
        $max = (int) config('scenes.triggers.debounce_max');

        return is_int($seconds) && $seconds >= $min && $seconds <= $max ? [] : ['trigger_debounce' => "The debounce is {$min} to {$max} seconds."];
    }

    // ---- MQTT topic filters ----

    /** Is this a well-formed MQTT topic filter (`+` a whole level, `#` the whole last level)? */
    public static function validFilter(string $filter): bool
    {
        if ($filter === '' || strlen($filter) > 200 || str_contains($filter, "\0")) {
            return false;
        }
        $levels = explode('/', $filter);
        foreach ($levels as $i => $level) {
            if ($level === '#') {
                if ($i !== count($levels) - 1) {
                    return false;
                }
            } elseif ($level !== '+' && (str_contains($level, '#') || str_contains($level, '+'))) {
                return false;
            }
        }

        return true;
    }

    public static function topicMatches(string $filter, string $topic): bool
    {
        $f = explode('/', $filter);
        $t = explode('/', $topic);
        foreach ($f as $i => $level) {
            if ($level === '#') {
                return true;
            }
            if (!array_key_exists($i, $t) || ($level !== '+' && $level !== $t[$i])) {
                return false;
            }
        }

        return count($f) === count($t);
    }

    /** Could one topic match both filters? (`remoment/+/3/state` and `remoment/player/#` can.) */
    public static function filtersOverlap(string $a, string $b): bool
    {
        $x = explode('/', $a);
        $y = explode('/', $b);
        for ($i = 0, $n = max(count($x), count($y)); $i < $n; $i++) {
            $p = $x[$i] ?? null;
            $q = $y[$i] ?? null;
            if ($p === '#' || $q === '#') {
                return true;
            }
            if ($p === null || $q === null) {
                return false;
            }
            if ($p !== '+' && $q !== '+' && $p !== $q) {
                return false;
            }
        }

        return true;
    }

    // ---- The index ----

    /**
     * Every trigger of every scene, flat: `{scene_id, type, ...trigger fields}`.
     *
     * @return list<array<string, mixed>>
     */
    public static function index(): array
    {
        return Cache::rememberForever(self::INDEX_KEY, function () {
            $entries = [];
            foreach (Scene::whereNotNull('triggers')->get(['id', 'triggers']) as $scene) {
                foreach ($scene->triggers ?? [] as $trigger) {
                    $entries[] = ['scene_id' => $scene->id] + $trigger;
                }
            }

            return $entries;
        });
    }

    public static function forgetIndex(): void
    {
        Cache::forget(self::INDEX_KEY);
    }

    /** The distinct MQTT topic filters of all scenes' triggers. @return list<string> */
    public static function mqttFilters(): array
    {
        return array_values(array_unique(array_column(array_filter(self::index(), fn ($e) => $e['type'] === 'mqtt'), 'topic')));
    }

    /** Does any scene have a source trigger for this device (a cheap check for the per-report listener)? */
    public static function hasSourceTrigger(int $deviceId): bool
    {
        foreach (self::index() as $e) {
            if ($e['type'] === 'device' && $e['event'] === 'source' && $e['device_id'] === $deviceId) {
                return true;
            }
        }

        return false;
    }

    // ---- Firing ----

    /** A message arrived: starts the scenes whose filter and payload match. Retained messages must not reach this. @return list<int> scene ids started */
    public function mqtt(string $topic, string $payload): array
    {
        $payload = trim($payload);
        $scenes = [];
        foreach (self::index() as $e) {
            if ($e['type'] === 'mqtt' && self::topicMatches($e['topic'], $topic) && (!isset($e['payload']) || $e['payload'] === $payload)) {
                $scenes[$e['scene_id']] = true;
            }
        }

        return $this->fire(array_keys($scenes));
    }

    /** A device changed state. @return list<int> scene ids started */
    public function deviceState(int $deviceId, State $state): array
    {
        $scenes = [];
        foreach (self::index() as $e) {
            if ($e['type'] === 'device' && $e['event'] === 'state' && $e['device_id'] === $deviceId && $e['value'] === $state->value) {
                $scenes[$e['scene_id']] = true;
            }
        }

        return $this->fire(array_keys($scenes));
    }

    /**
     * A device's source changed to `$names` (its name and its input, either may be null).
     *
     * @param  list<?string>  $names
     * @return list<int> scene ids started
     */
    public function deviceSource(int $deviceId, array $names): array
    {
        $names = array_map('mb_strtolower', array_filter($names, fn ($n) => $n !== null && $n !== ''));
        $scenes = [];
        foreach (self::index() as $e) {
            if ($e['type'] === 'device' && $e['event'] === 'source' && $e['device_id'] === $deviceId
                && (!isset($e['value']) || in_array(mb_strtolower($e['value']), $names, true))) {
                $scenes[$e['scene_id']] = true;
            }
        }

        return $this->fire(array_keys($scenes));
    }

    /**
     * @param  list<int>  $sceneIds
     * @return list<int> the scenes that were started
     */
    private function fire(array $sceneIds): array
    {
        $started = [];
        foreach ($sceneIds as $id) {
            $scene = Scene::find($id);
            if (!$scene || self::guarded($id)) {
                continue;
            }
            // Atomic: the first trigger wins, the rest within the debounce are dropped (also across processes).
            if (!Cache::add("scene_trigger_debounce_{$id}", 1, max(1, (int) $scene->trigger_debounce))) {
                continue;
            }
            app(SceneRunner::class)->start($scene, 'trigger');
            $started[] = $id;
        }

        return $started;
    }

    // ---- Loop protection ----

    /** Is the scene running, or did it end a moment ago? Its own steps' events must not start it again. */
    public static function guarded(int $sceneId): bool
    {
        return Cache::has("scene_guard_{$sceneId}");
    }

    /** A run starts (queued or begun): guarded until it ends (the longest run plus the guard). */
    public static function guardRun(int $sceneId): void
    {
        Cache::put("scene_guard_{$sceneId}", 1, SceneRunner::MAX_TOTAL_DELAY + 300 + (int) config('scenes.triggers.loop_guard_seconds'));
    }

    /** A run ended: guarded for the grace period only (the events of its last steps are still arriving). */
    public static function releaseRun(int $sceneId): void
    {
        Cache::put("scene_guard_{$sceneId}", 1, max(1, (int) config('scenes.triggers.loop_guard_seconds')));
    }
}
