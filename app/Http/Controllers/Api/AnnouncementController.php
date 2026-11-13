<?php

namespace App\Http\Controllers\Api;

use App\Domain\Device\State;
use App\Domain\Playback\AnnouncementClips;
use App\Domain\Playback\Announcer;
use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Announcements: a short clip on one or more speakers, after which they go back to what they did (docs/api/announcements.md). */
class AnnouncementController extends Controller
{
    public function clips(): JsonResponse
    {
        return response()->json(['data' => array_values(AnnouncementClips::all())]);
    }

    /** Open like playback. Each device answers for itself: one that fails does not stop the others. */
    public function store(Request $request, Announcer $announcer): JsonResponse
    {
        $data = $request->validate([
            'clip' => ['required', 'string', Rule::in(array_keys(AnnouncementClips::all()))],
            'device_ids' => ['required', 'array', 'min:1', 'max:32'],
            'device_ids.*' => ['integer', 'distinct', 'exists:devices,id'],
            'volume' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $clip = AnnouncementClips::find($data['clip']);
        $results = [];

        foreach (Device::whereIn('id', $data['device_ids'])->get() as $device) {
            $results[] = ['id' => $device->id, 'name' => $device->device_name] + $this->announceOn($announcer, $device, $clip, $data['volume'] ?? null);
        }

        $ok = count(array_filter($results, fn ($r) => $r['status'] === 'ok'));

        return response()->json([
            'status' => $ok === count($results) ? 'ok' : ($ok > 0 ? 'partial' : 'failed'),
            'clip' => $clip['name'],
            'devices' => $results,
        ], $ok > 0 ? 200 : 502);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'label' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            return response()->json(['data' => AnnouncementClips::add($request->file('file'), (string) $request->input('label'))], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'invalid', 'message' => $e->getMessage()], 422);
        }
    }

    public function destroy(string $name): JsonResponse
    {
        return AnnouncementClips::remove($name)
            ? response()->json(['status' => 'ok'])
            : response()->json(['error' => 'not_found', 'message' => 'There is no such clip (the built-in chime cannot be removed).'], 404);
    }

    /** @return array{status: string, error?: string, message?: string} */
    private function announceOn(Announcer $announcer, Device $device, array $clip, ?int $volume): array
    {
        if ($device->state === State::Unreachable) {
            return ['status' => 'error', 'error' => 'unreachable', 'message' => 'Device is not reachable.'];
        }

        try {
            $announcer->announce($device, $clip, $volume);

            return ['status' => 'ok'];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 'error', 'error' => 'unsupported', 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => 'driver_error', 'message' => 'The device did not respond: '.$e->getMessage()];
        }
    }
}
