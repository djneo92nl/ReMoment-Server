<?php

namespace App\Http\Controllers\Api;

use App\Domain\Health\ExternalServices;
use App\Domain\Health\HealthSummary;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin health for apps (docs/api/admin-health.md): the data of `/settings/health` (Domain\Health\HealthSummary) and
 * the external-service probes with "check now" (Domain\Health\ExternalServices). Admin token only.
 */
class AdminHealthController extends Controller
{
    public function show(HealthSummary $health): JsonResponse
    {
        return response()->json($health->toApi());
    }

    public function services(ExternalServices $services): JsonResponse
    {
        return response()->json($this->payload($services));
    }

    /** Runs the probes now (all of them, or the `only` ids) and answers with the fresh results; each probe has an 8 s timeout. */
    public function check(Request $request, ExternalServices $services): JsonResponse
    {
        $data = $request->validate([
            'only' => ['sometimes', 'array', 'min:1'],
            'only.*' => ['string', 'in:'.implode(',', $services->probeIds())],
        ]);

        $services->refresh($data['only'] ?? null);

        return response()->json($this->payload($services));
    }

    private function payload(ExternalServices $services): array
    {
        $results = collect($services->all())->map(fn (array $row, string $id) => ['id' => $id] + $row)->values();

        return [
            'checked_at' => $services->checkedAt()?->toIso8601String(),
            'problems' => $results->whereIn('status', ['error', 'warning'])->count(),
            'data' => $results->all(),
        ];
    }
}
