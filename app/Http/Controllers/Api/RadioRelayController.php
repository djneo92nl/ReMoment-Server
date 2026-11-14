<?php

namespace App\Http\Controllers\Api;

use App\Domain\Radio\Relay\RelayStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** The radio relay as the manager reports it (docs/architecture/radio-relay.md). Read only. */
class RadioRelayController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(RelayStatus::report());
    }
}
