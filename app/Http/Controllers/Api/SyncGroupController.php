<?php

namespace App\Http\Controllers\Api;

use App\Domain\Playback\SyncGroupException;
use App\Domain\Playback\SyncGroups;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\SyncGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sync groups ("fake multiroom"): docs/api/sync-groups.md. */
class SyncGroupController extends Controller
{
    public function __construct(private readonly SyncGroups $groups) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => SyncGroup::with('leader', 'members.device')->orderBy('id')->get()->map->apiItem()->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'leader_device_id' => ['required', 'integer', 'exists:devices,id'],
            'member_device_ids' => ['required', 'array', 'min:1'],
            'member_device_ids.*' => ['integer', 'exists:devices,id'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->guarded(function () use ($data) {
            $result = $this->groups->create(Device::findOrFail($data['leader_device_id']), $data['member_device_ids'], $data['name'] ?? null);

            return response()->json(['data' => $result['group']->apiItem(), 'failed' => $result['failed']], 201);
        });
    }

    public function destroy(Request $request, SyncGroup $group): JsonResponse
    {
        $request->validate(['stop_members' => ['nullable', 'boolean']]);

        $this->groups->dissolve($group, $request->boolean('stop_members', true));

        return response()->json(['status' => 'ok']);
    }

    public function addMember(Request $request, SyncGroup $group): JsonResponse
    {
        $data = $request->validate(['device_id' => ['required', 'integer', 'exists:devices,id']]);

        return $this->guarded(function () use ($group, $data) {
            $failed = $this->groups->addMember($group, Device::findOrFail($data['device_id']));

            return response()->json(['data' => $group->refresh()->apiItem(), 'failed' => $failed], 201);
        });
    }

    public function setOffset(Request $request, SyncGroup $group, Device $device): JsonResponse
    {
        $data = $request->validate(['offset_ms' => ['required', 'integer', 'between:-30000,30000']]);

        $member = $this->groups->setOffset($group, $device, $data['offset_ms']);

        return response()->json(['status' => 'ok', 'offset_ms' => $member->offset_ms]);
    }

    public function removeMember(Request $request, SyncGroup $group, Device $device): JsonResponse
    {
        $request->validate(['stop' => ['nullable', 'boolean']]);

        $this->groups->removeMember($group, $device, $request->boolean('stop', true));

        return response()->json(['status' => 'ok']);
    }

    private function guarded(\Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (SyncGroupException $e) {
            return response()->json(['error' => 'invalid', 'message' => $e->getMessage()], 422);
        }
    }
}
