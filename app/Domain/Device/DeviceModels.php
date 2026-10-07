<?php

namespace App\Domain\Device;

/**
 * The brands and models of config/devices.php: `brand => [model => definition]`. The same file also holds
 * settings that are no brand (`discoverers`, `listeners`, `multiroom_meta_keys`), so read the models here.
 */
final class DeviceModels
{
    /** @return array<string, array<string, array<string, mixed>>> */
    public static function all(): array
    {
        return array_filter(
            config('devices', []),
            fn ($models) => is_array($models) && $models !== [] && array_filter($models, 'is_array') === $models,
        );
    }

    /** The definition (driver, driver_name, ...) of a model, or null for an unknown brand or model. */
    public static function find(?string $brand, ?string $model): ?array
    {
        return self::all()[$brand][$model] ?? null;
    }

    /** The definition of a model by product name alone, ignoring case (a stored device has no brand key to look up). */
    public static function findByProduct(?string $product): ?array
    {
        $product = mb_strtolower(trim((string) $product));

        if ($product === '') {
            return null;
        }

        foreach (self::all() as $models) {
            foreach ($models as $model => $definition) {
                if (mb_strtolower($model) === $product) {
                    return $definition;
                }
            }
        }

        return null;
    }
}
