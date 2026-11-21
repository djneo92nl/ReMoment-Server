<?php

namespace App\Domain\Device;

use App\Models\Device;

/**
 * Adding, changing, hiding and removing devices by hand: `DeviceController` and `Livewire\DeviceDriverManager` (web) and
 * `Api\AdminDeviceController` (docs/api/admin-devices.md) share it. Discovery has its own inbox (`DiscoveryInbox`).
 */
class DeviceAdmin
{
    /** Thrown when a brand and product are not in config/devices.php. */
    public const UNKNOWN_PRODUCT = 'Unknown brand or product.';

    /**
     * The models a device can be added as: `{brand, product, driver_name, virtual}` each. A virtual model (Spotify) has no address.
     *
     * @return list<array{brand: string, product: string, driver_name: string, virtual: bool}>
     */
    public static function models(): array
    {
        $models = [];

        foreach (DeviceModels::all() as $brand => $products) {
            foreach ($products as $product => $definition) {
                $models[] = [
                    'brand' => $brand,
                    'product' => $product,
                    'driver_name' => (string) ($definition['driver_name'] ?? ''),
                    'virtual' => ($definition['virtual'] ?? false) === true,
                ];
            }
        }

        return $models;
    }

    /** Whether a model needs no network address (a virtual device such as Spotify). */
    public static function isVirtual(?string $brand, ?string $product): bool
    {
        return (DeviceModels::find((string) $brand, (string) $product)['virtual'] ?? false) === true;
    }

    /**
     * The device attributes of a brand and product, the driver included, or null when it is no model of config/devices.php.
     *
     * @return array{device_brand_name: string, device_product_type: string, device_driver: string, device_driver_name: string}|null
     */
    public static function attributesFor(string $brand, string $product): ?array
    {
        $definition = DeviceModels::find($brand, $product);

        return $definition === null ? null : [
            'device_brand_name' => $brand,
            'device_product_type' => $product,
            'device_driver' => $definition['driver'],
            'device_driver_name' => (string) ($definition['driver_name'] ?? ''),
        ];
    }

    /** @param  array<string, mixed>  $attributes  device columns (`device_name`, `ip_address`, brand, product, driver ...) */
    public static function create(array $attributes): Device
    {
        return Device::create($attributes);
    }

    /** @param  array<string, mixed>  $attributes */
    public static function update(Device $device, array $attributes): Device
    {
        $device->update($attributes);

        return $device->refresh();
    }

    /** Hidden devices stay stored (and listened to) but are left out of the pickers. */
    public static function setHidden(Device $device, bool $hidden): Device
    {
        $device->update(['hidden' => $hidden]);

        return $device;
    }

    public static function delete(Device $device): void
    {
        $device->meta()->delete();
        $device->delete();
    }

    /** @param  list<int>  $ids  @return int how many were removed */
    public static function deleteMany(array $ids): int
    {
        $devices = Device::whereIn('id', $ids)->get();

        foreach ($devices as $device) {
            self::delete($device);
        }

        return $devices->count();
    }
}
