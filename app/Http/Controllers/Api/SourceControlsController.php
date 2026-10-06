<?php

namespace App\Http\Controllers\Api;

use App\Domain\Control\SourceControls;
use App\Http\Controllers\Api\Concerns\GuardsDeviceAccess;
use App\Http\Controllers\Controller;
use App\Integrations\Common\UnsupportedOperationException;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

/**
 * Controls of the source a device is playing (change disc, FM presets, …);
 * see docs/api/source-controls.md. Empty while the source has none.
 */
class SourceControlsController extends Controller
{
    use GuardsDeviceAccess;

    public function index(Device $device): JsonResponse
    {
        $profile = SourceControls::available($device);

        return response()->json($profile?->toArray() ?? ['profile' => null, 'label' => null, 'controls' => []]);
    }

    public function run(Device $device, string $control): JsonResponse
    {
        if (!SourceControls::available($device)) {
            return $this->unsupported('source_controls');
        }

        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        try {
            SourceControls::run($device, $control);
        } catch (UnsupportedOperationException $e) {
            return response()->json(['error' => 'unsupported', 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        return response()->json(['status' => 'ok', 'control' => $control]);
    }
}
