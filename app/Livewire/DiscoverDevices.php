<?php

namespace App\Livewire;

use App\Models\Device;
use App\Models\DeviceMeta;
use App\Services\NetworkDiscoveryService;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Livewire\Component;

class DiscoverDevices extends Component
{
    public bool $scanning = false;

    public bool $done = false;

    /** @var array<int, array{ip: string, name: string, brand: string, product: string, driver: string, driver_name: string, meta: array, selected: bool}> */
    public array $results = [];

    public function scan(): void
    {
        $this->scanning = true;
        $this->done = false;
        $this->results = [];

        $service = app(NetworkDiscoveryService::class);
        $discovered = $service->discover();

        $this->results = array_map(fn ($d) => [
            'ip' => $d->ip_address,
            'name' => $d->device_name,
            'brand' => $d->device_brand_name,
            'product' => $d->device_product_type,
            'driver' => $d->device_driver,
            'driver_name' => $d->device_driver_name,
            'meta' => $d->meta,
            'selected' => true,
        ], $discovered);

        $this->scanning = false;
        $this->done = true;
    }

    public function toggleAll(bool $selected): void
    {
        $this->results = array_map(fn ($r) => [...$r, 'selected' => $selected], $this->results);
    }

    public function addSelected(): void
    {
        foreach ($this->results as $row) {
            if (!$row['selected']) {
                continue;
            }

            $device = Device::create([
                'uuid' => Str::uuid(),
                'ip_address' => $row['ip'],
                'device_name' => $row['name'],
                'device_brand_name' => $row['brand'],
                'device_product_type' => $row['product'],
                'device_driver' => $row['driver'],
                'device_driver_name' => $row['driver_name'],
                'last_seen' => Carbon::now(),
            ]);

            foreach ($row['meta'] as $key => $value) {
                DeviceMeta::create([
                    'device_id' => $device->id,
                    'key' => $key,
                    'value' => $value,
                ]);
            }
        }

        $this->redirect(route('devices.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.discover-devices');
    }
}
