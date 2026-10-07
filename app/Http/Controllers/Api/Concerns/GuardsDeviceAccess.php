<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Domain\Device\HardwareFeatures;
use App\Domain\Device\SpotifyRouting;
use App\Domain\Device\State;
use App\Integrations\Common\UnsupportedOperationException;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

/** The 503 / 422 / 502 answers every capability endpoint shares. */
trait GuardsDeviceAccess
{
    private function assertReachable(Device $device): ?JsonResponse
    {
        if ($device->state === State::Unreachable) {
            return response()->json([
                'error' => 'unreachable',
                'message' => 'Device is not reachable.',
            ], 503);
        }

        return null;
    }

    private function unsupported(string $capability): JsonResponse
    {
        return response()->json([
            'error' => 'unsupported',
            'message' => "This device does not support {$capability}.",
        ], 422);
    }

    /**
     * Shared order of checks for a capability endpoint (after validation): 503 unreachable
     * (unless `$reachable` is false), 422 unsupported, then the driver call.
     *
     * The driver is the one that executes `$contract` for the device (the Spotify driver for
     * playback on a speaker Spotify is routed to, see SpotifyRouting). `$callback` gets it and
     * returns the response body; it may also return a JsonResponse itself.
     */
    private function withDriver(Device $device, string $contract, string $capability, \Closure $callback, bool $reachable = true): JsonResponse
    {
        if ($reachable && ($error = $this->assertReachable($device))) {
            return $error;
        }

        $driver = SpotifyRouting::driverFor($device, $contract);

        if (!($driver instanceof $contract) || !HardwareFeatures::allows($device, $capability)) {
            return $this->unsupported($capability);
        }

        return $this->answerDriverCall(fn () => $callback($driver));
    }

    /**
     * Runs a call to a device and answers with its result: an operation the driver can't do is
     * 422 `unsupported`, a bad value 422 `invalid`, and any other failure 502 `driver_error`.
     */
    private function answerDriverCall(\Closure $call): JsonResponse
    {
        try {
            $result = $call();
        } catch (UnsupportedOperationException $e) {
            return response()->json(['error' => 'unsupported', 'message' => $e->getMessage()], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid', 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        return $result instanceof JsonResponse ? $result : response()->json($result);
    }
}
