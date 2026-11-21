<?php

namespace App\Http\Controllers\Api;

use App\Domain\Node\NodeJobs;
use App\Domain\Ripper\Rips;
use App\Domain\Sync\Syncs;
use App\Http\Controllers\Api\Concerns\AuthenticatesNode;
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
    use AuthenticatesNode;

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
        $data = $request->validate([
            'progress' => ['nullable', 'array'],
            'progress.phase' => ['nullable', 'string', 'max:30'],
            'progress.track' => ['nullable', 'integer', 'min:0', 'max:999'],
            'progress.tracks' => ['nullable', 'integer', 'min:0', 'max:999'],
            'progress.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'progress.detail' => ['nullable', 'string', 'max:200'],
            'progress.files_done' => ['nullable', 'integer', 'min:0'],
            'progress.files_total' => ['nullable', 'integer', 'min:0'],
            'progress.bytes_done' => ['nullable', 'integer', 'min:0'],
            'progress.bytes_total' => ['nullable', 'integer', 'min:0'],
            'progress.current' => ['nullable', 'string', 'max:200'],
        ]);

        if (!NodeJobs::progress($row, $data['progress'] ?? null)) {
            return response()->json(['error' => 'conflict', 'message' => "Job is {$row->fresh()->state}."], 409);
        }

        str_starts_with($row->type, 'sync.') && Syncs::progress($row, $data['progress'] ?? []);

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

        $finished = NodeJobs::finish($row, (bool) $data['ok'], $data['result'] ?? null, $data['error'] ?? null);
        Rips::afterFinish($finished);
        str_starts_with($finished->type, 'sync.') && Syncs::finished($finished);

        return response()->json(['job' => $finished->toPayload()]);
    }
}
