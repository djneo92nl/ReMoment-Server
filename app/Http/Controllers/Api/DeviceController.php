<?php

namespace App\Http\Controllers\Api;

use App\Domain\Device\State;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\DeviceDetailResource;
use App\Http\Resources\Api\DeviceListResource;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\RadioControlInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Contracts\SourcesInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;
use App\Models\RadioStation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeviceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return DeviceListResource::collection(Device::all());
    }

    public function show(Device $device): DeviceDetailResource
    {
        return new DeviceDetailResource($device);
    }

    public function action(Device $device, string $action): JsonResponse
    {
        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        $driver = $device->driver;

        if (!($driver instanceof MediaControlsInterface)) {
            return $this->unsupported('media_controls');
        }

        try {
            match ($action) {
                'play' => $driver->play(),
                'pause' => $driver->pause(),
                'stop' => $driver->stop(),
                'next' => $driver->next(),
                'previous' => $driver->previous(),
            };
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        return response()->json(['status' => 'ok', 'action' => $action]);
    }

    public function playRadio(Device $device, RadioStation $station): JsonResponse
    {
        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        $driver = $device->driver;

        if (!($driver instanceof RadioControlInterface)) {
            return $this->unsupported('radio_control');
        }

        try {
            $driver->playRadioStation($station);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        return response()->json(['status' => 'ok', 'station' => $station->name]);
    }

    public function sources(Device $device): JsonResponse
    {
        $driver = $device->driver;

        if (!($driver instanceof SourcesInterface)) {
            return $this->unsupported('source_control');
        }

        try {
            $sources = $driver->getSources();
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        $device->deviceSources()->delete();
        $device->deviceSources()->createMany(
            array_map(fn ($s) => [
                'source_id' => $s->sourceId,
                'friendly_name' => $s->friendlyName,
                'source_type' => $s->sourceType,
                'category' => $s->category,
                'in_use' => $s->inUse,
                'borrowed' => $s->borrowed,
                'provider_jid' => $s->providerJid,
                'provider_name' => $s->providerName,
            ], $sources)
        );

        return response()->json(['sources' => array_map(fn ($s) => $s->toArray(), $sources)]);
    }

    public function activateSource(Request $request, Device $device): JsonResponse
    {
        $request->validate(['source_id' => ['required', 'string']]);

        $driver = $device->driver;

        if (!($driver instanceof SourceActivationInterface)) {
            return $this->unsupported('source_activation');
        }

        try {
            $driver->activateSource($request->string('source_id'));
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        return response()->json(['status' => 'ok', 'source_id' => $request->string('source_id')]);
    }

    public function getVolume(Device $device): JsonResponse
    {
        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        $driver = $device->driver;

        if (!($driver instanceof VolumeControlInterface)) {
            return $this->unsupported('volume_control');
        }

        return response()->json(['volume' => $driver->getVolume()]);
    }

    public function setVolume(Request $request, Device $device): JsonResponse
    {
        $request->validate(['volume' => ['required', 'integer', 'min:0', 'max:100']]);

        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        $driver = $device->driver;

        if (!($driver instanceof VolumeControlInterface)) {
            return $this->unsupported('volume_control');
        }

        try {
            $actual = $driver->setVolume($request->integer('volume'));
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'driver_error',
                'message' => 'The device did not respond: '.$e->getMessage(),
            ], 502);
        }

        return response()->json(['volume' => $actual]);
    }

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
}
