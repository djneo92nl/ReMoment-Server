<?php

namespace App\Http\Controllers\Api;

use App\Domain\Sync\SyncRefused;
use App\Domain\Sync\Syncs;
use App\Domain\Sync\SyncService;
use App\Http\Controllers\Controller;
use App\Models\SyncTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sync targets (workstream 18.3, docs/api/sync.md): list, create, edit, delete, estimate, the stateless plan, the
 * executor's report, and running a node target. Admin token only; `{target}` is the target's ULID.
 */
class SyncTargetController extends Controller
{
    public function __construct(private readonly SyncService $sync) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => SyncTarget::query()->orderBy('name')->get()->map(fn (SyncTarget $t) => $this->sync->resource($t))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in(SyncTarget::KINDS)],
            'client_ref' => ['nullable', 'string', 'max:64'],
            'node_id' => ['nullable', 'integer'],
            'rules' => ['nullable', 'array'],
        ]);
        $existing = $data['kind'] === SyncTarget::APP && SyncTarget::where('client_ref', $data['client_ref'] ?? '')->exists();
        $target = $this->sync->create($data);

        return response()->json($this->sync->resource($target), $existing ? 200 : 201);
    }

    public function show(SyncTarget $target): JsonResponse
    {
        return response()->json($this->sync->resource($target));
    }

    public function update(Request $request, SyncTarget $target): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:100'], 'rules' => ['sometimes', 'nullable', 'array']]);

        return response()->json($this->sync->resource($this->sync->update($target, $data)));
    }

    public function destroy(SyncTarget $target): JsonResponse
    {
        abort_if($target->job() && !$target->job()->isFinished(), 409, 'A job for this target is running.');
        $target->delete();

        return response()->json(['status' => 'ok']);
    }

    /** What the rules would hold; the stored rules unless `rules` is sent. Nothing is stored or prepared. */
    public function estimate(Request $request, SyncTarget $target): JsonResponse
    {
        $data = $request->validate(['rules' => ['nullable', 'array'], 'free_bytes' => ['nullable', 'integer', 'min:0']]);
        $rules = isset($data['rules']) ? \App\Domain\Sync\SyncRules::normalize($data['rules']) : $this->sync->rules($target);

        return response()->json($this->sync->estimate($rules, $data['free_bytes'] ?? null));
    }

    /** The stateless plan: the client says what it holds, the server answers with the changes. */
    public function plan(Request $request, SyncTarget $target): JsonResponse
    {
        return $this->planFor($request, $target);
    }

    public function report(Request $request, SyncTarget $target): JsonResponse
    {
        return $this->recordReport($request, $target);
    }

    /** Sync now on the node of the target. */
    public function run(Request $request, SyncTarget $target): JsonResponse
    {
        $data = $request->validate(['confirm' => ['nullable', 'boolean']]);

        return $this->guard(fn () => ['job_id' => Syncs::start($target, (bool) ($data['confirm'] ?? false))->id, 'status' => 'queued'], 202);
    }

    /** Read the drive (marker, free space, manifest), or claim an unmarked one ("Use this drive"). */
    public function volume(Request $request, SyncTarget $target): JsonResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['scan', 'claim'])]]);

        return $this->guard(fn () => ['job_id' => ($data['action'] === 'scan' ? Syncs::scan($target) : Syncs::claim($target))->id, 'status' => 'queued'], 202);
    }

    public function cancel(SyncTarget $target): JsonResponse
    {
        return response()->json(['cancelled' => Syncs::cancel($target)]);
    }

    /** Shared with the node route: validates the manifest and answers the plan (or the refusal with its estimate). */
    public function planFor(Request $request, SyncTarget $target): JsonResponse
    {
        $data = $request->validate([
            'have' => ['present', 'array'],
            'free_bytes' => ['nullable', 'integer', 'min:0'],
            'fs' => ['nullable', 'string', 'max:20'],
            'rules_version' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->guard(fn () => $this->sync->plan(
            $target, SyncService::have($data['have']), $data['free_bytes'] ?? null, $data['fs'] ?? null, $data['rules_version'] ?? null,
        ));
    }

    /** The executor's report: counts and the owned totals are kept, never the file list. */
    public function recordReport(Request $request, SyncTarget $target): JsonResponse
    {
        $data = $request->validate([
            'rules_version' => ['nullable', 'integer', 'min:0'],
            'applied' => ['nullable', 'array', 'max:200000'], 'applied.*' => ['string', 'max:100'],
            'failed' => ['nullable', 'array', 'max:200000'], 'failed.*.key' => ['string', 'max:100'], 'failed.*.error' => ['nullable', 'string', 'max:300'],
            'released' => ['nullable', 'array', 'max:200000'], 'released.*' => ['string', 'max:100'],
            'owned_bytes' => ['nullable', 'integer', 'min:0'], 'owned_count' => ['nullable', 'integer', 'min:0'],
            'free_bytes' => ['nullable', 'integer', 'min:0'],
        ]);

        $target->forceFill([
            'last_report' => [
                'rules_version' => $data['rules_version'] ?? null,
                'applied' => count($data['applied'] ?? []), 'failed' => count($data['failed'] ?? []), 'released' => count($data['released'] ?? []),
                'failures' => array_slice($data['failed'] ?? [], 0, 20),
                'owned_bytes' => $data['owned_bytes'] ?? null, 'owned_count' => $data['owned_count'] ?? null, 'free_bytes' => $data['free_bytes'] ?? null,
            ],
            'last_report_at' => now(),
            'owned_bytes' => $data['owned_bytes'] ?? $target->owned_bytes,
            'owned_count' => $data['owned_count'] ?? $target->owned_count,
        ])->save();

        return response()->json(['status' => 'ok']);
    }

    /** @param  callable(): array<string, mixed>  $work */
    private function guard(callable $work, int $status = 200): JsonResponse
    {
        try {
            return response()->json($work(), $status);
        } catch (SyncRefused $e) {
            return response()->json(['error' => $e->errorCode, 'message' => $e->getMessage()] + ($e->estimate ? ['estimate' => $e->estimate] : []), $e->status);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid', 'message' => $e->getMessage()], 422);
        }
    }
}
