<?php

namespace App\Http\Controllers\Api;

use App\Domain\Analysis\StemWeights;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The opt-in download of the Demucs weights (docs/api/stems.md, "Weights"): the state is open, starting the download
 * needs the admin token. The stems service downloads them, the licence being unclear (App\Domain\Analysis\StemWeights).
 */
class StemWeightsController extends Controller
{
    /** GET /api/stems/weights: state missing | downloading | ready | failed, size, progress, source, checksum, licence note. */
    public function show(): JsonResponse
    {
        [$status, $body] = StemWeights::status();

        return response()->json($body, $status);
    }

    /** POST /api/admin/stems/weights: starts the download (a no-op when ready or already downloading); 202 with the status, 200 when ready. */
    public function store(): JsonResponse
    {
        [$status, $body] = StemWeights::download();

        return response()->json($body, $status);
    }
}
