<?php

namespace App\Http\Controllers\Api;

use App\Domain\Multiroom\Presets;
use App\Http\Controllers\Controller;
use App\Models\MultiroomPreset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Multiroom presets (docs/api/multiroom-presets.md). Open like multiroom itself, no admin token (decided 2026-10-26,
 * docs/architecture/v1-roadmap.md 8b): the same as the web page, which has no login either.
 */
class MultiroomPresetController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Presets::all()->map(fn (MultiroomPreset $p) => Presets::toArray($p))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(required: true));

        return response()->json(['data' => Presets::toArray(Presets::create($data['name'], $data['device_ids']))], 201);
    }

    public function update(Request $request, MultiroomPreset $preset): JsonResponse
    {
        $data = $request->validate($this->rules(required: false));

        return response()->json(['data' => Presets::toArray(Presets::update($preset, $data))]);
    }

    public function destroy(MultiroomPreset $preset): JsonResponse
    {
        Presets::delete($preset);

        return response()->json(['status' => 'ok']);
    }

    /**
     * The host is the first device; the others join it. `joined`/`failed` say who did; 422 `invalid` when fewer than two of
     * the preset's devices still exist, 502 `driver_error` when nobody could join.
     */
    public function activate(MultiroomPreset $preset): JsonResponse
    {
        $result = Presets::activate($preset);

        if ($result['too_few']) {
            return response()->json(['error' => 'invalid', 'message' => 'The preset needs at least 2 devices that still exist.'], 422);
        }

        $payload = [
            'status' => $result['failed'] === [] ? 'ok' : ($result['joined'] === [] ? 'failed' : 'partial'),
            'host' => ['id' => $result['host']->id, 'name' => $result['host']->device_name],
            'joined' => array_map(fn ($d) => ['id' => $d->id, 'name' => $d->device_name], $result['joined']),
            'failed' => array_map(fn ($f) => ['id' => $f['device']->id, 'name' => $f['device']->device_name, 'message' => $f['message']], $result['failed']),
        ];

        if ($result['joined'] === []) {
            return response()->json(['error' => 'driver_error', 'message' => 'No device could join the host.'] + $payload, 502);
        }

        return response()->json($payload);
    }

    private function rules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:100'],
            'device_ids' => [$presence, 'array', 'min:2'],
            'device_ids.*' => ['integer', 'distinct', 'exists:devices,id'],
        ];
    }
}
