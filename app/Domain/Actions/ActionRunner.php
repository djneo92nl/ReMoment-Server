<?php

namespace App\Domain\Actions;

use App\Domain\Device\HardwareFeatures;
use App\Domain\Device\SpotifyRouting;
use App\Domain\Device\State;
use App\Integrations\Common\UnsupportedOperationException;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

/**
 * The shared pipeline of an action: validate, 503 unreachable, 422 unsupported, handler, error mapping. It repeats
 * what `GuardsDeviceAccess::withDriver()` does (that trait is private to API controllers), which is the price of the
 * layer: the guard order has to exist in one more place until the trait moves here.
 */
final class ActionRunner
{
    public function run(Action $action, Device $device, array $input): JsonResponse
    {
        $data = Validator::make($input, $action->rules())->validate();

        if ($action->needsReachable() && $device->state === State::Unreachable) {
            return $this->error('unreachable', 'Device is not reachable.', 503);
        }

        $driver = null;
        if ($contract = $action->contract()) {
            $driver = SpotifyRouting::driverFor($device, $contract);
            if (!($driver instanceof $contract) || !HardwareFeatures::allows($device, $action->capability())) {
                return $this->error('unsupported', "This device does not support {$action->capability()}.", 422);
            }
        }

        try {
            return response()->json($action->handle($device, $data, $driver));
        } catch (ActionException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->status);
        } catch (UnsupportedOperationException $e) {
            return $this->error('unsupported', $e->getMessage(), 422);
        } catch (\InvalidArgumentException $e) {
            return $this->error('invalid', $e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->error('driver_error', 'The device did not respond: '.$e->getMessage(), 502);
        }
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $status);
    }
}
