<?php

namespace App\Http\Controllers\Api;

use App\Domain\Alerts\Alerts;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Alerts feed and acknowledge; see docs/architecture/alerts.md. */
class AlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'since' => 'nullable|integer|min:0',
            'state' => 'nullable|in:active,resolved,all',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $alerts = Alerts::since((int) ($data['since'] ?? 0), $data['state'] ?? 'all', (int) ($data['limit'] ?? 100));

        return response()->json([
            'data' => $alerts->map->toPayload()->values(),
            'summary' => Alerts::summary(),
        ]);
    }

    public function acknowledge(Alert $alert): JsonResponse
    {
        return response()->json(['data' => Alerts::acknowledge($alert)->toPayload()]);
    }
}
