<?php

namespace App\Domain\Multiroom;

use App\Integrations\Contracts\MultiRoomInterface;
use App\Models\Device;
use App\Models\MultiroomPreset;
use Illuminate\Support\Collection;

/**
 * Multiroom presets: named groupings of devices, the first one the host. `Livewire\MultiroomPresets` (/multiroom) and
 * `Api\MultiroomPresetController` (docs/api/multiroom-presets.md) share this. Presets are open to guests like multiroom itself
 * (decided 2026-10-26, workstream 8b): no admin token.
 */
class Presets
{
    /** @return Collection<int, MultiroomPreset> */
    public static function all(): Collection
    {
        return MultiroomPreset::orderBy('id')->get();
    }

    /** @param  list<int|string>  $deviceIds */
    public static function create(string $name, array $deviceIds): MultiroomPreset
    {
        return MultiroomPreset::create(['name' => trim($name), 'device_ids' => self::ids($deviceIds)]);
    }

    /** @param  array{name?: string, device_ids?: list<int|string>}  $data */
    public static function update(MultiroomPreset $preset, array $data): MultiroomPreset
    {
        if (isset($data['name'])) {
            $preset->name = trim($data['name']);
        }
        if (isset($data['device_ids'])) {
            $preset->device_ids = self::ids($data['device_ids']);
        }
        $preset->save();

        return $preset;
    }

    public static function delete(MultiroomPreset $preset): void
    {
        $preset->delete();
    }

    /**
     * Makes every device after the first listen to the first (the host). A device that cannot join is reported, the rest still do.
     *
     * @return array{host: ?Device, joined: list<Device>, failed: list<array{device: Device, message: string}>, too_few: bool}
     */
    public static function activate(MultiroomPreset $preset): array
    {
        $byId = Device::whereIn('id', $preset->device_ids)->get()->keyBy('id');
        $devices = collect($preset->device_ids)->map(fn ($id) => $byId->get($id))->filter()->values();

        if ($devices->count() < 2) {
            return ['host' => $devices->first(), 'joined' => [], 'failed' => [], 'too_few' => true];
        }

        $host = $devices->first();
        $joined = $failed = [];

        foreach ($devices->skip(1) as $guest) {
            try {
                $driver = $guest->driver;
                if (!$driver instanceof MultiRoomInterface) {
                    throw new \RuntimeException('This device does not support multiroom.');
                }
                $driver->joinSession($host);
                $joined[] = $guest;
            } catch (\Throwable $e) {
                $failed[] = ['device' => $guest, 'message' => $e->getMessage()];
            }
        }

        return ['host' => $host, 'joined' => $joined, 'failed' => $failed, 'too_few' => false];
    }

    /** Devices that can take part in multiroom, as `{id, name}`. @return Collection<int, array{id: int, name: string}> */
    public static function multiroomDevices(): Collection
    {
        return Device::all()
            ->filter(fn (Device $device) => is_a($device->device_driver, MultiRoomInterface::class, true))
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->device_name])
            ->values();
    }

    /** The preset as the API shows it. @return array{id: int, name: string, device_ids: list<int>, devices: list<array{id: int, name: string}>} */
    public static function toArray(MultiroomPreset $preset): array
    {
        $names = Device::whereIn('id', $preset->device_ids)->pluck('device_name', 'id');

        return [
            'id' => $preset->id,
            'name' => $preset->name,
            'device_ids' => $preset->device_ids,
            // In preset order (the first is the host); a device that was removed since is left out.
            'devices' => collect($preset->device_ids)->filter(fn ($id) => $names->has($id))->map(fn ($id) => ['id' => $id, 'name' => $names[$id]])->values()->all(),
        ];
    }

    /** @param  list<int|string>  $ids  @return list<int> */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }
}
