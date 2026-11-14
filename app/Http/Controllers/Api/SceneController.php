<?php

namespace App\Http\Controllers\Api;

use App\Domain\Scenes\SceneRunner;
use App\Domain\Scenes\Scenes;
use App\Http\Controllers\Controller;
use App\Models\Scene;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Scenes (workstream 8.1, docs/api/scenes.md): household-wide lists of steps, readable, editable and runnable by
 * guests like playlists and multiroom presets. Steps are validated on save by SceneRunner against openapi.yaml.
 */
class SceneController extends Controller
{
    public function __construct(private readonly SceneRunner $runner) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => Scene::orderBy('name')->orderBy('id')->get()->map(fn (Scene $s) => Scenes::item($s))]);
    }

    public function show(Scene $scene): JsonResponse
    {
        return response()->json(['data' => Scenes::detail($scene)]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => Scenes::detail(Scene::create($this->validated($request, null)))], 201);
    }

    public function update(Request $request, Scene $scene): JsonResponse
    {
        $scene->update($this->validated($request, $scene));

        return response()->json(['data' => Scenes::detail($scene->fresh())]);
    }

    public function destroy(Scene $scene): JsonResponse
    {
        $scene->delete();

        return response()->json(['status' => 'ok']);
    }

    /** Runs the scene: at once (200 with the per-step results) or, with delays, on the queue (202). */
    public function activate(Scene $scene): JsonResponse
    {
        if (SceneRunner::hasDelay($scene->steps ?? [])) {
            $this->runner->start($scene, 'api');

            return response()->json(['status' => 'queued', 'data' => Scenes::item($scene->fresh())], 202);
        }

        $outcome = $this->runner->run($scene, 'api');

        return response()->json(['status' => $outcome['status'], 'results' => $outcome['results'], 'data' => Scenes::item($scene->fresh())]);
    }

    private function validated(Request $request, ?Scene $scene): array
    {
        $partial = $scene !== null;
        $required = $partial ? 'sometimes|required' : 'required';
        $data = $request->validate([
            'name' => "{$required}|string|max:120",
            'icon' => 'nullable|string|max:32',
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'steps' => "{$required}|array",
            'stop_on_error' => 'sometimes|boolean',
            'schedule_enabled' => 'sometimes|boolean',
            'schedule_time' => ['nullable', 'date_format:H:i'],
            'schedule_days' => 'nullable|array',
            'schedule_days.*' => 'integer|between:1,7',
        ]);

        if (array_key_exists('steps', $data)) {
            if ($errors = $this->runner->validate($data['steps'])) {
                throw ValidationException::withMessages(array_map(fn ($m) => [$m], $errors));
            }
            $data['steps'] = SceneRunner::normalise($data['steps']);
        }
        if (isset($data['schedule_days'])) {
            $data['schedule_days'] = array_values(array_unique($data['schedule_days'])) ?: null;
        }

        $enabled = $data['schedule_enabled'] ?? $scene?->schedule_enabled ?? false;
        $time = array_key_exists('schedule_time', $data) ? $data['schedule_time'] : $scene?->schedule_time;
        if ($enabled && $time === null) {
            throw ValidationException::withMessages(['schedule_time' => ['A schedule needs a time (HH:MM).']]);
        }

        return $data;
    }
}
