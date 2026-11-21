<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ripper\RipException;
use App\Domain\Ripper\Rips;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ripping a CD on a node (docs/api/rips.md): `rippers` is open (which nodes can rip and whether a disc is in), the rest
 * is the admin API (`admin.token`): check the disc, choose the release, list and cancel. The node does the work through
 * its jobs; see `App\Domain\Ripper\Rips`.
 */
class RipController extends Controller
{
    /** Open: the nodes with the ripper role switched on, the disc as of the last check, whether one is busy. */
    public function rippers(): JsonResponse
    {
        return response()->json(['data' => Rips::rippers()]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => Rips::list()]);
    }

    public function show(string $rip): JsonResponse
    {
        $job = Rips::findJob($rip);

        return $job ? response()->json(Rips::payload($job, detail: true)) : $this->notFound();
    }

    /** Without `release_mbid`: check the disc (202). With one: queue the rip of that release (202). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'node_id' => ['required', 'integer'],
            'release_mbid' => ['nullable', 'uuid'],
        ]);

        $node = Client::where('kind', 'node')->find($data['node_id']);
        if ($node === null) {
            return response()->json(['error' => 'unknown_node', 'message' => 'No such node.'], 404);
        }

        try {
            [$job, $created] = Rips::start($node, $data['release_mbid'] ?? null);
        } catch (RipException $e) {
            return response()->json(['error' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
        }

        return response()->json(Rips::payload($job), $created ? 202 : 200);
    }

    public function cancel(string $rip): JsonResponse
    {
        $job = Rips::findJob($rip);

        return $job ? response()->json(Rips::payload(Rips::cancel($job))) : $this->notFound();
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => 'not_found', 'message' => 'No such rip.'], 404);
    }
}
