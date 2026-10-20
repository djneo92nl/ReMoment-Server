<?php

namespace App\Livewire;

use App\Domain\Device\DeviceModels;
use App\Domain\Device\DiscoveryInbox;
use App\Models\Device;
use App\Models\DiscoveredDeviceRecord;
use App\Services\NetworkDiscoveryService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Component;

class DeviceDriverManager extends Component
{
    /** Optional IPs (space/comma/newline separated) probed directly, for when multicast does not reach the server. */
    public string $hosts = '';

    /** @var string[] */
    public array $diagnostics = [];

    public ?string $scanSummary = null;

    /** @var array<int, string> Edited names of discovered devices, by record id. */
    public array $names = [];

    /** @var array<string, array{name: string, ip: string, product_key: string}> */
    public array $manualForm = [];

    /**
     * Drivers grouped for the page: by brand (ASE and Mozart are both Bang & Olufsen), except that a driver built on
     * another brand's driver (Philips extends the UPnP driver) sits under that brand.
     *
     * @return array<string, array{label: string, virtual: bool, products: array, drivers: array<string, Collection>, devices: Collection}>
     */
    public function driverGroups(): array
    {
        $models = DeviceModels::all();
        $brandOfDriver = [];

        foreach ($models as $brand => $products) {
            foreach ($products as $cfg) {
                $brandOfDriver[$cfg['driver']] ??= $brand;
            }
        }

        $devices = Device::orderBy('device_name')->get()->groupBy('device_driver_name');
        $groups = [];

        foreach ($models as $brand => $products) {
            foreach ($products as $product => $cfg) {
                $label = $this->groupBrand($cfg['driver'], $brand, $brandOfDriver);
                $key = Str::slug($label);

                $groups[$key]['label'] = $label;
                $groups[$key]['products'][] = [
                    'key' => "{$brand}|{$product}",
                    'brand' => $brand,
                    'product' => $product,
                    'driver_name' => $cfg['driver_name'],
                    'virtual' => $cfg['virtual'] ?? false,
                ];
                $groups[$key]['drivers'][$cfg['driver_name']] = $devices->get($cfg['driver_name'], collect());
            }
        }

        foreach ($groups as &$group) {
            $group['virtual'] = collect($group['products'])->every('virtual');
            $group['devices'] = collect($group['drivers'])->flatten(1)->sortBy('device_name')->values();
        }

        return $groups;
    }

    /** @param  array<string, string>  $brandOfDriver */
    private function groupBrand(string $driver, string $brand, array $brandOfDriver): string
    {
        foreach (class_exists($driver) ? class_parents($driver) : [] as $parent) {
            if (isset($brandOfDriver[$parent]) && $brandOfDriver[$parent] !== $brand) {
                return $brandOfDriver[$parent];
            }
        }

        return $brand;
    }

    public function scan(): void
    {
        $service = app(NetworkDiscoveryService::class);
        $hosts = array_values(array_filter(
            preg_split('/[\s,;]+/', $this->hosts, -1, PREG_SPLIT_NO_EMPTY),
            fn ($h) => filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || preg_match('#^https?://[^/\s]+#i', $h)
        ));

        $found = $service->discoverAll(hosts: $hosts);
        $counts = app(DiscoveryInbox::class)->record(array_values($found));

        $this->diagnostics = $service->diagnostics;
        $this->scanSummary = count($found) === 0
            ? 'No devices responded.'
            : "{$counts['new']} new, {$counts['known']} already added.";
    }

    public function addDiscovered(int $id): void
    {
        $record = DiscoveredDeviceRecord::find($id);

        if (!$record) {
            return;
        }

        $device = app(DiscoveryInbox::class)->add($record, $this->names[$id] ?? null);
        unset($this->names[$id]);

        session()->flash('success', "{$device->device_name} added.");
    }

    public function addAll(): void
    {
        $count = 0;

        foreach (DiscoveredDeviceRecord::whereNull('ignored_at')->get() as $record) {
            app(DiscoveryInbox::class)->add($record, $this->names[$record->id] ?? null);
            $count++;
        }

        $this->names = [];

        session()->flash('success', "{$count} ".($count === 1 ? 'device' : 'devices').' added.');
    }

    public function ignore(int $id): void
    {
        if ($record = DiscoveredDeviceRecord::find($id)) {
            app(DiscoveryInbox::class)->ignore($record);
        }
    }

    public function restore(int $id): void
    {
        if ($record = DiscoveredDeviceRecord::find($id)) {
            app(DiscoveryInbox::class)->restore($record);
        }
    }

    public function addManual(string $groupKey): void
    {
        $this->validate([
            "manualForm.{$groupKey}.name" => ['required', 'string', 'max:255'],
            "manualForm.{$groupKey}.ip" => ['required', 'string', 'max:255'],
            "manualForm.{$groupKey}.product_key" => ['required', 'string'],
        ]);

        $data = $this->manualForm[$groupKey];
        [$brand, $product] = explode('|', $data['product_key'], 2);
        $cfg = config("devices.{$brand}.{$product}");

        if (!$cfg) {
            $this->addError("manualForm.{$groupKey}.product_key", 'Unknown product.');

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

        unset($this->manualForm[$groupKey]);

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
        $records = DiscoveredDeviceRecord::orderBy('device_name')->get();

        return view('livewire.device-driver-manager', [
            'groups' => $this->driverGroups(),
            'pending' => $records->whereNull('ignored_at')->values(),
            'ignored' => $records->whereNotNull('ignored_at')->values(),
        ]);
    }
}
