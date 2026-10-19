<?php

namespace App\Integrations\Philips;

use App\Models\Device;
use App\Models\DeviceMeta;
use Illuminate\Support\Str;

/**
 * Pairing with a Philips Android TV (Jointspace v6): the TV is asked to show a PIN, the PIN goes back signed, and
 * the TV answers with a device id and a key, the digest login of every later call. The signing key is the one
 * every open client of this API uses (it is in the Philips remote app); it is used for pairing only.
 */
final class Pairing
{
    private const SECRET = 'ZmVay1EQVFOaZhwQ4Kv81ypLAZNczV9sG4KkseXWn1NEk6cXmPKO/MCa9sryslvLCFMnNe4Z4CPXzToowvhHvA==';

    /** base64 of the hex HMAC-SHA1 (the hex text, not the raw bytes) of timestamp + PIN. */
    public static function signature(int|string $timestamp, string $pin): string
    {
        return base64_encode(hash_hmac('sha1', $timestamp.$pin, base64_decode(self::SECRET)));
    }

    /** @return array<string, string> */
    public static function deviceInfo(string $deviceId): array
    {
        return [
            'device_name' => 'ReMoment',
            'device_os' => 'Android',
            'app_name' => 'ReMoment',
            'type' => 'native',
            'app_id' => 'app.id',
            'id' => $deviceId,
        ];
    }

    /**
     * Step one: the TV shows a PIN.
     *
     * @return array{device_id: string, auth_key: string, timestamp: int|string}
     */
    public static function begin(JointspaceClient $client): array
    {
        $deviceId = Str::random(16);
        $reply = $client->pairRequest($deviceId);

        if (empty($reply['auth_key']) || !isset($reply['timestamp'])) {
            throw new \RuntimeException('The TV did not start pairing: '.json_encode($reply));
        }

        return ['device_id' => $deviceId, 'auth_key' => $reply['auth_key'], 'timestamp' => $reply['timestamp']];
    }

    /**
     * Step two: the PIN goes back; on success the login is stored on the device and the device is handed to the
     * Philips driver (which adds power, apps and the active app on top of the UPnP driver).
     *
     * @param  array{device_id: string, auth_key: string, timestamp: int|string}  $pending
     */
    public static function finish(Device $device, JointspaceClient $client, array $pending, string $pin): void
    {
        $client->pairGrant($pending['device_id'], $pending['auth_key'], trim($pin), $pending['timestamp']);

        foreach (['philips_user' => $pending['device_id'], 'philips_password' => $pending['auth_key']] as $key => $value) {
            DeviceMeta::updateOrCreate(['device_id' => $device->id, 'key' => $key], ['value' => $value]);
        }

        $device->update(['device_driver' => VideoPlayerDriver::class, 'device_driver_name' => 'Philips']);
    }
}
