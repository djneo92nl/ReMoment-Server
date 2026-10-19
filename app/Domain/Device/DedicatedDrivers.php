<?php

namespace App\Domain\Device;

/**
 * Who has a driver of its own. The generic UPnP driver is the fallback: a renderer that a dedicated driver
 * (ASE, Mozart, Sonos, any brand in config/devices.php) claims is never reported as a generic one, so adding
 * a brand or a model to the config is all it takes to take its devices off the generic driver.
 */
final class DedicatedDrivers
{
    /** The `driver_name` of the generic driver. */
    public const GENERIC = 'UPnP';

    /** True when the model is listed under a dedicated driver, or the manufacturer is a brand that has one (Sonos has models config doesn't list). */
    public static function claims(?string $manufacturer, ?string $model): bool
    {
        $manufacturer = mb_strtolower(html_entity_decode((string) $manufacturer));
        $model = mb_strtolower(trim((string) $model));

        foreach (DeviceModels::all() as $brand => $models) {
            $dedicated = array_filter($models, fn ($definition) => ($definition['driver_name'] ?? null) !== self::GENERIC && empty($definition['virtual']));

            if ($dedicated === []) {
                continue;
            }

            if ($manufacturer !== '' && str_contains($manufacturer, mb_strtolower($brand))) {
                return true;
            }

            foreach (array_keys($dedicated) as $name) {
                if ($model !== '' && mb_strtolower($name) === $model) {
                    return true;
                }
            }
        }

        return false;
    }
}
