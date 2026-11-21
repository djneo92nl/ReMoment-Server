<?php

namespace App\Http\Controllers\Api;

use App\Domain\Health\QueueAdmin;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Per-queue health and pause, resume, cancel and run-first of one queue's jobs (admin token; docs/api/admin-queues.md). */
class AdminQueueController extends Controller
{
    public function index(QueueAdmin $admin): JsonResponse
    {
        return response()->json($admin->overview());
    }

    public function pause(string $queue, QueueAdmin $admin): JsonResponse
    {
        if ($error = $this->refuse($queue, $admin)) {
            return $error;
        }

        try {
            $admin->pause($queue);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $admin->find($queue)]);
    }

    public function resume(string $queue, QueueAdmin $admin): JsonResponse
    {
        if ($error = $this->refuse($queue, $admin)) {
            return $error;
        }

        $admin->resume($queue);

        return response()->json(['data' => $admin->find($queue)]);
    }

    public function cancel(Request $request, string $queue, QueueAdmin $admin): JsonResponse
    {
        $data = $request->validate(['job' => 'nullable|string|max:255']);

        if ($error = $this->refuse($queue, $admin, needsDriver: true)) {
            return $error;
        }

        return response()->json($admin->cancel($queue, $data['job'] ?? null));
    }

    public function prioritise(Request $request, string $queue, QueueAdmin $admin): JsonResponse
    {
        $data = $request->validate(['job' => 'required|string|max:255']);

        if ($error = $this->refuse($queue, $admin, needsDriver: true)) {
            return $error;
        }

        try {
            return response()->json(['moved' => $admin->prioritise($queue, $data['job'])]);
        } catch (\LogicException $e) {
            return response()->json(['error' => 'unsupported', 'message' => $e->getMessage()], 422);
        }
    }

    /** 404 for a queue that isn't one of the app's, 422 when the driver can't be managed. */
    private function refuse(string $queue, QueueAdmin $admin, bool $needsDriver = false): ?JsonResponse
    {
        if (!$admin->known($queue)) {
            return response()->json(['error' => 'not_found', 'message' => "No queue named {$queue}."], 404);
        }

        return $needsDriver && !$admin->supported()
            ? response()->json(['error' => 'unsupported', 'message' => 'The queue driver cannot be managed (only redis and database can).'], 422)
            : null;
    }
}
