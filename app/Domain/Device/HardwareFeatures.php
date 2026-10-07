<?php

namespace App\Domain\Device;

use App\Models\Device;

/**
 * What the hardware of a model can do, which a driver can't tell: every ASE
 * speaker answers the WiSA endpoints, but only the Moment has the radio.
 * Declared per model in config/devices.php (`wisa => true`, `speaker =>
 * 'external'`) and matched on the device's product type.
 */
final class HardwareFeatures
{
    /** Capabilities that need hardware, mapped to the model setting [key, value] that declares it. */
    private const REQUIRES = [
        'wireless_speakers' => ['wisa', true],
        'wired_speakers' => ['speaker', 'external'],
    ];

    /** False only for a hardware-bound capability the device's model doesn't have. */
    public static function allows(Device $device, string $capability): bool
    {
        $requires = self::REQUIRES[$capability] ?? null;

        return $requires === null || self::has($device, ...$requires);
    }

    private static function has(Device $device, string $key, mixed $value): bool
    {
        $product = mb_strtolower(trim((string) $device->device_product_type));
        if ($product === '') {
            return false;
        }

        foreach (config('devices', []) as $brand => $models) {
            if ($brand === 'discoverers' || !is_array($models)) {
                continue;
            }
            foreach ($models as $model => $definition) {
                if (mb_strtolower($model) === $product && ($definition[$key] ?? null) === $value) {
                    return true;
                }
            }
        }

        return false;
    }
}
