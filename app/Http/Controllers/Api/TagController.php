<?php

namespace App\Http\Controllers\Api;

use App\Domain\Tags\PlayTags;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Device;
use App\Models\PlayTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tap to play: NFC tags and QR codes that play something (docs/api/tags.md). */
class TagController extends Controller
{
    public function show(string $uid): JsonResponse
    {
        $tag = PlayTags::find($uid);

        return $tag ? response()->json(['data' => PlayTags::item($tag)]) : $this->unknown($uid, remember: false);
    }

    /** A reader taps a tag: play its target on `device_id`, else the tag's own device, else the reader's first device. */
    public function play(Request $request, string $uid): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'client' => ['nullable', 'string', 'max:64'],
        ]);

        $tag = PlayTags::find($uid);
        if (!$tag) {
            return $this->unknown($uid, remember: true);
        }

        $device = isset($data['device_id']) ? Device::find($data['device_id']) : $tag->device;
        if (!$device && isset($data['client'])) {
            $device = Client::where('api_token', $data['client'])->where('status', 'approved')->first()?->devices->first();
        }
        if (!$device) {
            return response()->json(['error' => 'no_device', 'message' => 'Give a device_id: this tag has no device of its own.'], 422);
        }

        [$status, $body] = PlayTags::play($tag, $device);

        return response()->json($status < 300
            ? ['status' => 'ok', 'tag' => PlayTags::item($tag), 'device' => ['id' => $device->id, 'name' => $device->device_name]]
            : $body, $status);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => PlayTag::with('device')->orderBy('label')->orderBy('id')->get()->map(fn (PlayTag $t) => PlayTags::item($t))]);
    }

    public function lastScanned(): JsonResponse
    {
        return response()->json(['data' => PlayTags::lastScanned()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);
        $data['uid'] = PlayTags::normalise($data['uid'] ?? '') ?: PlayTags::newUid();

        if (PlayTag::where('uid', $data['uid'])->exists()) {
            return response()->json(['message' => 'This tag is already in use.', 'errors' => ['uid' => ['This tag is already in use.']]], 422);
        }

        return response()->json(['data' => PlayTags::item(PlayTag::create($data))], 201);
    }

    public function update(Request $request, PlayTag $tag): JsonResponse
    {
        $data = $this->validated($request, $tag);
        unset($data['uid']);
        $tag->update($data);

        return response()->json(['data' => PlayTags::item($tag->fresh())]);
    }

    public function destroy(PlayTag $tag): JsonResponse
    {
        $tag->delete();

        return response()->json(['status' => 'ok']);
    }

    private function validated(Request $request, ?PlayTag $tag): array
    {
        $required = $tag ? 'sometimes' : 'required';
        $type = $request->input('target_type', $tag?->target_type);

        return $request->validate([
            'uid' => ['nullable', 'string', 'max:64'],
            'label' => ['nullable', 'string', 'max:255'],
            'target_type' => [$required, Rule::in(array_keys(PlayTags::TYPES))],
            'target_id' => [$required, 'integer', function ($attribute, $value, $fail) use ($type) {
                $class = PlayTags::TYPES[$type] ?? null;
                if (!$class || !$class::whereKey($value)->exists()) {
                    $fail('There is no such '.($type ?: 'target').'.');
                }
            }],
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'shuffle' => ['nullable', 'boolean'],
        ]);
    }

    private function unknown(string $uid, bool $remember): JsonResponse
    {
        if ($remember) {
            PlayTags::rememberUnknown($uid);
        }

        return response()->json(['error' => 'unknown_tag', 'message' => 'This tag is not assigned to anything yet.', 'uid' => PlayTags::normalise($uid)], 404);
    }
}
