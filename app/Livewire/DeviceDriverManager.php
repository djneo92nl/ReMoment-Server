<?php

namespace App\Livewire;

use App\Integrations\BangOlufsen\Ase\AseDiscovery;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Services\NetworkDiscoveryService;
use Carbon\Carbon;
use Livewire\Component;

class DeviceDriverManager extends Component
{
    private const DISCOVERERS = [
        'ASE' => AseDiscovery::class,
        'Sonos' => \App\Integrations\Sonos\SonosDiscovery::class,
    ];

    public array $scanning = [];

    /** @var array<string, array<int, array{ip: string, name: string, brand: string, product: string, driver: string, driver_name: string, meta: array}>> */
    public array $discovered = [];

    /** @var array<string, array{name: string, ip: string, product_key: string}> */
    public array $manualForm = [];

    public function driverGroups(): array
    {
        $groups = [];

        foreach (collect(config('devices'))->except('discoverers') as $brand => $products) {
            foreach ($products as $product => $cfg) {
                $groups[$cfg['driver_name']]['products'][] = [
                    'key' => "{$brand}|{$product}",
                    'brand' => $brand,
                    'product' => $product,
                    'virtual' => $cfg['virtual'] ?? false,
                ];
            }
        }

        foreach ($groups as $driverName => &$group) {
            $group['driver_name'] = $driverName;
            $group['virtual'] = collect($group['products'])->contains('virtual', true);
            $group['discoverable'] = isset(self::DISCOVERERS[$driverName]);
            $group['devices'] = Device::where('device_driver_name', $driverName)
                ->orderBy('device_name')
                ->get();
        }

        return $groups;
    }

    public function discover(string $driverName): void
    {
        $class = self::DISCOVERERS[$driverName] ?? null;

        if (!$class) {
            return;
        }

        $found = app(NetworkDiscoveryService::class)->discover([$class]);

        $this->discovered[$driverName] = array_map(fn ($d) => [
            'ip' => $d->ip_address,
            'name' => $d->device_name,
            'brand' => $d->device_brand_name,
            'product' => $d->device_product_type,
            'driver' => $d->device_driver,
            'driver_name' => $d->device_driver_name,
            'meta' => $d->meta,
        ], $found);
    }

    public function addDiscovered(string $driverName, int $index): void
    {
        $row = $this->discovered[$driverName][$index] ?? null;

        if (!$row) {
            return;
        }

        $device = Device::create([
            'ip_address' => $row['ip'],
            'device_name' => $row['name'],
            'device_brand_name' => $row['brand'],
            'device_product_type' => $row['product'],
            'device_driver' => $row['driver'],
            'device_driver_name' => $row['driver_name'],
            'last_seen' => Carbon::now(),
        ]);

        foreach ($row['meta'] as $key => $value) {
            DeviceMeta::create(['device_id' => $device->id, 'key' => $key, 'value' => $value]);
        }

        unset($this->discovered[$driverName][$index]);

        session()->flash('success', "{$row['name']} added.");
    }

    public function addManual(string $driverName): void
    {
        $this->validate([
            "manualForm.{$driverName}.name" => ['required', 'string', 'max:255'],
            "manualForm.{$driverName}.ip" => ['required', 'string', 'max:255'],
            "manualForm.{$driverName}.product_key" => ['required', 'string'],
        ]);

        $data = $this->manualForm[$driverName];
        [$brand, $product] = explode('|', $data['product_key'], 2);
        $cfg = config("devices.{$brand}.{$product}");

        if (!$cfg) {
            $this->addError("manualForm.{$driverName}.product_key", 'Unknown product.');

            return;
        }

        Device::create([
            'ip_address' => $data['ip'],
            'device_name' => $data['name'],
            'device_brand_name' => $brand,
            'device_product_type' => $product,
            'device_driver' => $cfg['driver'],
            'device_driver_name' => $cfg['driver_name'],
            'last_seen' => Carbon::now(),
        ]);

        unset($this->manualForm[$driverName]);

        session()->flash('success', "{$data['name']} added.");
    }

    public function toggleHidden(int $deviceId): void
    {
        $device = Device::find($deviceId);

        $device?->update(['hidden' => !$device->hidden]);
    }

    public function deleteDevice(int $deviceId): void
    {
        $device = Device::find($deviceId);

        if (!$device) {
            return;
        }

        $name = $device->device_name;
        $device->meta()->delete();
        $device->delete();

        session()->flash('success', "{$name} removed.");
    }

    public function render()
    {
        return view('livewire.device-driver-manager', [
            'groups' => $this->driverGroups(),
        ]);
    }
}
