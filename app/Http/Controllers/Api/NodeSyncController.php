<?php

namespace App\Http\Controllers\Api;

use App\Domain\Sync\Syncs;
use App\Http\Controllers\Api\Concerns\AuthenticatesNode;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The plan and report a node asks for while it runs `sync.apply` (Bearer node token). A node can only plan and report for
 * the target bound to it; another target is `403` (docs/architecture/offline-sync.md sections 6 and 10).
 */
class NodeSyncController extends Controller
{
    use AuthenticatesNode;

    public function plan(Request $request, SyncTargetController $targets): JsonResponse
    {
        $request->validate(['target_id' => ['required', 'string']]);

        return $targets->planFor($request, Syncs::nodeTarget($this->node($request), (string) $request->input('target_id')));
    }

    public function report(Request $request, SyncTargetController $targets): JsonResponse
    {
        $request->validate(['target_id' => ['required', 'string']]);

        return $targets->recordReport($request, Syncs::nodeTarget($this->node($request), (string) $request->input('target_id')));
    }
}
