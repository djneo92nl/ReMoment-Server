<?php

namespace App\Http\Controllers\Api;

use App\Domain\Node\NodeJobs;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\NodeJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a node itself calls (Bearer: its client api_token): fetch, accept, progress and finish its jobs.
 * A node's token reaches nothing else of the admin API (docs/architecture/node-agent.md).
 */
class NodeController extends Controller
{
    private function node(Request $request): Client
    {
        $token = $request->bearerToken();
        $node = $token ? Client::where('api_token', $token)->first() : null;

        abort_if($node === null || !$node->isNode() || $node->status !== 'approved', 401, 'Unknown node token.');

        return $node;
    }

    private function job(Client $node, string $id): NodeJob
    {
        return NodeJob::where('client_id', $node->id)->where('id', $id)->firstOrFail();
    }

    /** The next job for this node: `{ job: {...} }` or `{ job: null }`. Also counts as a sign of life. */
    public function next(Request $request): JsonResponse
    {
        $node = $this->node($request);
        $node->update(['last_seen_at' => now()]);

        return response()->json(['job' => ($job = NodeJobs::next($node)) ? NodeJobs::deliver($job) : null]);
    }

    public function accept(Request $request, string $job): JsonResponse
    {
        $node = $this->node($request);
        $row = $this->job($node, $job);

        if ($row->state === 'accepted' || $row->state === 'running') {
            return response()->json(['job' => NodeJobs::deliver($row)]); // a retry after a lost answer
        }
        if (!NodeJobs::accept($row)) {
            return response()->json(['error' => 'conflict', 'message' => "Job is {$row->fresh()->state}."], 409);
        }

        return response()->json(['job' => NodeJobs::deliver($row->fresh())]);
    }

    public function progress(Request $request, string $job): JsonResponse
    {
        $row = $this->job($this->node($request), $job);

        if (!NodeJobs::progress($row)) {
            return response()->json(['error' => 'conflict', 'message' => "Job is {$row->fresh()->state}."], 409);
        }

        return response()->json(['cancel_requested' => $row->fresh()->cancel_requested]);
    }

    public function result(Request $request, string $job): JsonResponse
    {
        $row = $this->job($this->node($request), $job);
        $data = $request->validate([
            'ok' => ['required', 'boolean'],
            'result' => ['nullable', 'array'],
            'error' => ['nullable', 'string', 'max:500'],
        ]);

        if ($row->isFinished()) {
            return response()->json(['job' => $row->toPayload()]); // idempotent
        }

        return response()->json(['job' => NodeJobs::finish($row, (bool) $data['ok'], $data['result'] ?? null, $data['error'] ?? null)->toPayload()]);
    }
}
