<?php

namespace App\Domain\Device;

use App\Models\Device;

/**
 * What the hardware of a model can do, which a driver can't tell: every ASE
 * speaker answers the WiSA endpoints, but only the Moment has the radio.
 * Declared per model in config/devices.php (`wisa => true`) and matched on
 * the device's product type.
 */
final class HardwareFeatures
{
    /** Capabilities that need hardware, mapped to the model flag that declares it. */
    private const REQUIRES = ['wireless_speakers' => 'wisa'];

    public static function wisa(Device $device): bool
    {
        return self::has($device, 'wisa');
    }

    /** False only for a hardware-bound capability the device's model doesn't have. */
    public static function allows(Device $device, string $capability): bool
    {
        $flag = self::REQUIRES[$capability] ?? null;

        return $flag === null || self::has($device, $flag);
    }

    private static function has(Device $device, string $flag): bool
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
                if (mb_strtolower($model) === $product && ($definition[$flag] ?? false) === true) {
                    return true;
                }
            }
        }

        return false;
    }
}
