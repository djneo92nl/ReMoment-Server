<?php

namespace App\Http\Controllers\Api;

use App\Domain\Node\NodeJobs;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\NodeJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin side of node jobs (admin token): create, list, cancel. Nodes never create jobs. */
class AdminNodeJobController extends Controller
{
    public function index(int $client): JsonResponse
    {
        $node = Client::where('kind', 'node')->findOrFail($client);
        NodeJobs::sweep($node);

        return response()->json(['data' => NodeJob::where('client_id', $node->id)->latest('id')->limit(50)->get()->map->toPayload()]);
    }

    public function store(Request $request, int $client): JsonResponse
    {
        $node = Client::where('kind', 'node')->findOrFail($client);
        $data = $request->validate([
            'type' => ['required', 'string', 'max:40'],
            'params' => ['nullable', 'array'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $job = NodeJobs::create($node, $data['type'], $data['params'] ?? [], $data['idempotency_key'] ?? null);

        return response()->json(['job' => $job->toPayload()], $job->wasRecentlyCreated ? 201 : 200);
    }

    public function cancel(int $client, string $job): JsonResponse
    {
        $row = NodeJob::where('client_id', $client)->where('id', $job)->firstOrFail();

        return response()->json(['job' => NodeJobs::cancel($row)->toPayload()]);
    }
}
