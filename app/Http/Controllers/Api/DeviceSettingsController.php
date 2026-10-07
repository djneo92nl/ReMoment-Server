<?php

namespace App\Http\Controllers\Api;

use App\Domain\Device\HardwareFeatures;
use App\Http\Controllers\Api\Concerns\GuardsDeviceAccess;
use App\Http\Controllers\Controller;
use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Contracts\BluetoothInterface;
use App\Integrations\Contracts\DeviceInfoInterface;
use App\Integrations\Contracts\DigitsInterface;
use App\Integrations\Contracts\NetworkSettingsInterface;
use App\Integrations\Contracts\SleepTimerInterface;
use App\Integrations\Contracts\SoundAdjustmentInterface;
use App\Integrations\Contracts\WiredSpeakersInterface;
use App\Integrations\Contracts\WirelessSpeakersInterface;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Device settings (sound adjustment, Bluetooth, device info, sleep timer) and the TV number keys for every
 * platform that implements the matching contract. Settings use the device's
 * own driver, never Spotify routing.
 */
class DeviceSettingsController extends Controller
{
    use GuardsDeviceAccess;

    public function getSoundAdjustment(Device $device): JsonResponse
    {
        return $this->withDriver($device, SoundAdjustmentInterface::class, 'sound_adjustment',
            fn (SoundAdjustmentInterface $driver) => $driver->getSoundAdjustment()->toArray());
    }

    public function setSoundAdjustment(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate([
            'bass' => ['sometimes', 'integer'],
            'treble' => ['sometimes', 'integer'],
            'loudness' => ['sometimes', 'boolean'],
        ]);

        return $this->withDriver($device, SoundAdjustmentInterface::class, 'sound_adjustment', function (SoundAdjustmentInterface $driver) use ($data) {
            $driver->setSoundAdjustment($data['bass'] ?? null, $data['treble'] ?? null, array_key_exists('loudness', $data) ? (bool) $data['loudness'] : null);

            return $driver->getSoundAdjustment()->toArray();
        });
    }

    public function getBluetooth(Device $device): JsonResponse
    {
        return $this->withDriver($device, BluetoothInterface::class, 'bluetooth',
            fn (BluetoothInterface $driver) => $driver->getBluetooth()->toArray());
    }

    public function setBluetooth(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate([
            'discoverable' => ['sometimes', 'boolean'],
            'reconnect_mode' => ['sometimes', 'string'],
        ]);

        return $this->withDriver($device, BluetoothInterface::class, 'bluetooth', function (BluetoothInterface $driver) use ($data) {
            $driver->setBluetooth(
                array_key_exists('discoverable', $data) ? (bool) $data['discoverable'] : null,
                $data['reconnect_mode'] ?? null,
            );

            return $driver->getBluetooth()->toArray();
        });
    }

    public function removeBluetoothDevice(Device $device, string $deviceId): JsonResponse
    {
        return $this->withDriver($device, BluetoothInterface::class, 'bluetooth', function (BluetoothInterface $driver) use ($deviceId) {
            $driver->removeBluetoothDevice($deviceId);

            return $driver->getBluetooth()->toArray();
        });
    }

    public function getInfo(Device $device): JsonResponse
    {
        return $this->withDriver($device, DeviceInfoInterface::class, 'device_info',
            fn (DeviceInfoInterface $driver) => $driver->getDeviceInfo()->toArray());
    }

    public function setInfo(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:1', 'max:64', Rule::notIn([''])]]);

        return $this->withDriver($device, DeviceInfoInterface::class, 'device_info', function (DeviceInfoInterface $driver) use ($data, $device) {
            $driver->setDeviceName($data['name']);
            $device->update(['device_name' => $data['name']]);

            return $driver->getDeviceInfo()->toArray();
        });
    }

    public function getSleepTimer(Device $device): JsonResponse
    {
        return $this->withDriver($device, SleepTimerInterface::class, 'sleep_timer',
            fn (SleepTimerInterface $driver) => $driver->getSleepTimer()->toArray());
    }

    public function setSleepTimer(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate(['minutes' => ['required', 'integer']]);

        return $this->withDriver($device, SleepTimerInterface::class, 'sleep_timer', function (SleepTimerInterface $driver) use ($data) {
            $driver->setSleepTimer((int) $data['minutes']);

            return $driver->getSleepTimer()->toArray();
        });
    }

    /** Number keys for the active TV / set-top box source, pressed in order. */
    public function sendDigits(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate(['digits' => ['required', 'string', 'regex:/^[0-9]{1,8}$/']]);

        return $this->withDriver($device, DigitsInterface::class, 'digits', function (DigitsInterface $driver) use ($data) {
            foreach (str_split($data['digits']) as $digit) {
                $driver->sendDigit((int) $digit);
            }

            return ['status' => 'ok', 'digits' => $data['digits']];
        });
    }

    public function getNetwork(Device $device): JsonResponse
    {
        return $this->withDriver($device, NetworkSettingsInterface::class, 'network_settings',
            fn (NetworkSettingsInterface $driver) => $driver->getNetwork()->toArray());
    }

    public function setNetworkInterface(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate(['interface' => ['required', 'string']]);

        return $this->withDriver($device, NetworkSettingsInterface::class, 'network_settings', function (NetworkSettingsInterface $driver) use ($data) {
            $driver->setActiveInterface($data['interface']);

            return $this->networkChanged();
        });
    }

    public function setWiredNetwork(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate([
            'dhcp' => ['required', 'boolean'],
            'address' => ['nullable', 'ipv4'],
            'subnet_mask' => ['nullable', 'ipv4'],
            'gateway' => ['nullable', 'ipv4'],
            'preferred_dns' => ['nullable', 'ipv4'],
            'alternative_dns' => ['nullable', 'ipv4'],
        ]);

        return $this->withDriver($device, NetworkSettingsInterface::class, 'network_settings', function (NetworkSettingsInterface $driver) use ($data) {
            $driver->setWiredAddress((bool) $data['dhcp'], collect($data)->except('dhcp')->all());

            return $this->networkChanged();
        });
    }

    public function joinWifi(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate([
            'ssid' => ['required', 'string', 'min:1', 'max:32'],
            'passphrase' => ['nullable', 'string', 'max:63'],
            'security' => ['nullable', 'string', 'max:32'],
        ]);

        return $this->withDriver($device, NetworkSettingsInterface::class, 'network_settings', function (NetworkSettingsInterface $driver) use ($data) {
            $driver->joinWifi($data['ssid'], $data['passphrase'] ?? null, $data['security'] ?? null);

            return $this->networkChanged();
        });
    }

    public function getWirelessSpeakers(Device $device): JsonResponse
    {
        return $this->withDriver($device, WirelessSpeakersInterface::class, 'wireless_speakers',
            fn (WirelessSpeakersInterface $driver) => $driver->getWirelessSpeakers()->toArray());
    }

    public function scanWirelessSpeakers(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:start,stop']]);

        return $this->withDriver($device, WirelessSpeakersInterface::class, 'wireless_speakers', function (WirelessSpeakersInterface $driver) use ($data) {
            $data['action'] === 'start' ? $driver->startWirelessScan() : $driver->stopWirelessScan();

            return $driver->getWirelessSpeakers()->toArray();
        });
    }

    public function getWiredSpeakers(Device $device): JsonResponse
    {
        return $this->withDriver($device, WiredSpeakersInterface::class, 'wired_speakers',
            fn (WiredSpeakersInterface $driver) => $driver->getWiredSpeakers()->toArray());
    }

    public function setWiredSpeaker(Request $request, Device $device, string $speakerId): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required_without:sound', 'nullable', 'string', 'max:64'],
            'sound' => ['required_without:type', 'nullable', 'string', 'max:32'],
        ]);

        return $this->withDriver($device, WiredSpeakersInterface::class, 'wired_speakers', function (WiredSpeakersInterface $driver) use ($data, $speakerId) {
            $driver->setWiredSpeaker($speakerId, $data['type'] ?? null, $data['sound'] ?? null);

            return $driver->getWiredSpeakers()->toArray();
        });
    }

    /**
     * A network change can move the device to another address, so the answer
     * is not a read-back: clients re-read `GET /network` once the device is up.
     */
    private function networkChanged(): array
    {
        return ['status' => 'ok', 'message' => 'Applied. The device may take a moment, and may answer on a new address.'];
    }

    /**
     * Shared order of checks for a capability endpoint (after validation):
     * 503 unreachable, 422 unsupported, then the driver call, where an
     * operation the driver can't do is 422, a bad value 422 and any other
     * failure 502.
     */
    private function withDriver(Device $device, string $contract, string $capability, \Closure $callback): JsonResponse
    {
        if ($error = $this->assertReachable($device)) {
            return $error;
        }

        $driver = $device->driver;

        if (!($driver instanceof $contract) || !HardwareFeatures::allows($device, $capability)) {
            return $this->unsupported($capability);
        }

        try {
            return response()->json($callback($driver));
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
    }
}
