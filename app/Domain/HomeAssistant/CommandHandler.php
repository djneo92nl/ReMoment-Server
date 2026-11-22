<?php

namespace App\Domain\HomeAssistant;

use App\Domain\Scenes\SceneOperations;
use App\Domain\Scenes\SceneRunner;
use App\Domain\Scenes\SceneTriggers;
use App\Models\Scene;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Home Assistant tells ReMoment to do something over MQTT (docs/architecture/home-assistant.md, "Commands from Home
 * Assistant"). Opt-in (`HomeAssistant::commandsActive()`), and deliberately narrow:
 *
 *  - `remoment/ha/command/scene/{id}`   start a scene (what the discovery `button` entities press; any payload)
 *  - `remoment/ha/command/operation`    one operation of the scene allow-list: `{"operation": "setVolume", "device_id": 3,
 *                                       "params": {"volume": 30}, "request_id": "abc"}`
 *
 * Nothing else is accepted. An operation must pass `SceneOperations::validateStep`, so it is exactly what a scene step
 * may be: a guest-level operation of the contract that changes something, never an admin-token one, never a scene
 * operation (no recursion), never a delay. It runs through `SceneRunner` and so through `ApiBridge`, with the validation
 * and capability checks of a client call.
 *
 * Protection: retained messages are ignored (a broker replays them on every connect), at most `mqtt.ha_command_rate` commands a
 * minute across all topics, the same command inside `mqtt.ha_command_debounce` seconds is dropped, a scene that is running
 * (or ended a moment ago) is not started again, payloads are capped, and the results go to `remoment/ha/events/command_result`,
 * which this process never subscribes to, so an answer can never become a command.
 */
final class CommandHandler
{
    public const PREFIX = 'remoment/ha/command/';

    public const FILTER = 'remoment/ha/command/#';

    public const RESULT_ENTITY = 'command_result';

    public const MAX_PAYLOAD = 4096;

    /** Errors are answered at most this often a minute, so a flood of bad messages is not echoed back as a flood. */
    private const ERROR_ANSWERS_PER_MINUTE = 10;

    public function __construct(private readonly SceneRunner $runner, private readonly SceneOperations $operations, private readonly DiscoveryPublisher $publisher) {}

    /**
     * @return array<string, mixed>|null the result that was published, null when the message was ignored without an answer
     */
    public function handle(string $topic, string $payload, bool $retained = false): ?array
    {
        if ($retained || !HomeAssistant::commandsActive() || !str_starts_with($topic, self::PREFIX)) {
            return null;
        }

        $command = substr($topic, strlen(self::PREFIX));
        $requestId = null;

        try {
            if (strlen($payload) > self::MAX_PAYLOAD) {
                return $this->answer($command, $requestId, false, 'too_large', 'A command is at most '.self::MAX_PAYLOAD.' bytes.');
            }
            if (!RateLimiter::attempt('ha-command', max(1, (int) config('mqtt.ha_command_rate')), fn () => true, 60)) {
                return $this->answer($command, $requestId, false, 'rate_limited', 'Too many commands this minute; this one was dropped.');
            }

            if (preg_match('#^scene/(\d{1,9})$#', $command, $m)) {
                return $this->scene((int) $m[1], $command);
            }
            if ($command === 'operation') {
                return $this->operation($payload, $command);
            }

            return $this->answer($command, $requestId, false, 'unknown_command', 'Known commands: scene/{id}, operation.');
        } catch (\Throwable $e) {
            Log::warning("Home Assistant command {$command} failed: {$e->getMessage()}");

            return $this->answer($command, $requestId, false, 'failed', 'The command could not be run.');
        }
    }

    /** @return array<string, mixed> */
    private function scene(int $id, string $command): array
    {
        $scene = Scene::find($id);
        if (!$scene) {
            return $this->answer($command, null, false, 'not_found', "There is no scene {$id}.");
        }
        if (SceneTriggers::guarded($id)) {
            return $this->answer($command, null, false, 'busy', "Scene {$id} is running or just ended.");
        }
        if (!Cache::add("ha_command_debounce_scene_{$id}", 1, $this->debounce())) {
            return $this->answer($command, null, false, 'debounced', 'The same command came a moment ago.');
        }

        $this->runner->start($scene, 'home_assistant');

        return $this->answer($command, null, true, null, null, ['scene_id' => $id, 'scene' => $scene->name]);
    }

    /** @return array<string, mixed> */
    private function operation(string $payload, string $command): array
    {
        $data = json_decode($payload, true);
        if (!is_array($data) || array_is_list($data)) {
            return $this->answer($command, null, false, 'invalid', 'The payload must be a JSON object.');
        }

        $requestId = isset($data['request_id']) && is_scalar($data['request_id']) ? substr((string) $data['request_id'], 0, 64) : null;
        $step = array_intersect_key($data, array_flip(['operation', 'device_id', 'params']));

        // A delay is a step type of its own, never an operation here; `type` is not a known key and is dropped above.
        if (($errors = $this->operations->validateStep($step)) !== []) {
            $operation = is_string($step['operation'] ?? null) ? $step['operation'] : null;
            $code = $operation !== null && $this->operations->find($operation) === null ? 'not_allowed' : 'invalid';

            return $this->answer($command, $requestId, false, $code, json_encode($errors, JSON_UNESCAPED_UNICODE), ['operation' => $operation]);
        }

        $fingerprint = md5(json_encode(SceneRunner::normalise([$step])));
        if (!Cache::add("ha_command_debounce_op_{$fingerprint}", 1, $this->debounce())) {
            return $this->answer($command, $requestId, false, 'debounced', 'The same command came a moment ago.', ['operation' => $step['operation']]);
        }

        $outcome = $this->runner->execute(SceneRunner::normalise([$step]), true);
        $result = $outcome['results'][0] ?? ['ok' => false, 'status' => null, 'error' => 'no_result'];

        return $this->answer($command, $requestId, (bool) $result['ok'], $result['ok'] ? null : 'failed', $result['error'] ?? null, [
            'operation' => $step['operation'], 'device_id' => $step['device_id'] ?? null, 'status' => $result['status'] ?? null,
        ]);
    }

    private function debounce(): int
    {
        return max(1, (int) config('mqtt.ha_command_debounce'));
    }

    /**
     * Publishes the result (an `event` entity, non-retained). Errors are rate limited; a success is always answered.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function answer(string $command, ?string $requestId, bool $ok, ?string $error, ?string $message = null, array $extra = []): array
    {
        $result = array_filter([
            'command' => $command, 'request_id' => $requestId, 'ok' => $ok, 'error' => $error, 'message' => $message, 'at' => now()->toIso8601String(),
        ] + $extra, fn ($v) => $v !== null);

        if ($ok || RateLimiter::attempt('ha-command-errors', self::ERROR_ANSWERS_PER_MINUTE, fn () => true, 60)) {
            $this->publisher->event(self::RESULT_ENTITY, $ok ? 'ok' : 'error', $result);
        }

        return $result;
    }
}
