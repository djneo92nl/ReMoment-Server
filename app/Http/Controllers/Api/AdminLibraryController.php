<?php

namespace App\Http\Controllers\Api;

use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibraryMaintenance;
use App\Domain\Library\LibrarySettingsAdmin;
use App\Domain\Stats\PlayRetention;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Library settings and maintenance for apps (docs/api/admin-library.md): Settings › Storage › Library
 * (Domain\Library\LibrarySettingsAdmin) and the `library:*` maintenance commands (Domain\Library\LibraryMaintenance).
 * Admin token only; `library` profile feature.
 */
class AdminLibraryController extends Controller
{
    public function settings(LibrarySettingsAdmin $settings): JsonResponse
    {
        return response()->json($settings->current());
    }

    /** Changes only the keys sent. */
    public function updateSettings(Request $request, LibrarySettingsAdmin $settings): JsonResponse
    {
        $data = $request->validate([
            'leading_source' => ['sometimes', 'in:'.implode(',', LeadingSource::MODES)],
            'add_played_tracks' => ['sometimes', 'boolean'],
            'playback_loudness' => ['sometimes', 'boolean'],
            'nts_enabled' => ['sometimes', 'boolean'],
            'concerts_enabled' => ['sometimes', 'boolean'],
            'concerts_location' => ['sometimes', 'nullable', 'string', 'max:200'],
            'history_keep_months' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.PlayRetention::MAX_MONTHS],
        ]);

        return response()->json($settings->save($data));
    }

    public function maintenance(Request $request, LibraryMaintenance $maintenance, string $action): JsonResponse
    {
        if (!in_array($action, LibraryMaintenance::ACTIONS, true)) {
            abort(404);
        }

        $data = $request->validate([
            'dry_run' => ['sometimes', 'boolean'],
            'confirm' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'only' => ['sometimes', 'in:metadata,artist-images'],
            'server' => ['sometimes', 'string', 'max:255'],
            'root' => ['sometimes', 'string', 'max:1000'],
            'playlist_id' => ['sometimes', 'integer'],
        ]);

        if (in_array($action, LibraryMaintenance::DESTRUCTIVE, true) && empty($data['dry_run']) && empty($data['confirm'])) {
            return response()->json([
                'error' => 'confirmation_required',
                'message' => 'This cannot be undone: run with dry_run=true first, then confirm=true.',
            ], 422);
        }

        return response()->json($maintenance->run($action, $data));
    }
}
