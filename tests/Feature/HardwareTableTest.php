<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceModels;
use App\Domain\Hardware\HardwareTable;
use Tests\TestCase;

class HardwareTableTest extends TestCase
{
    public function test_the_committed_hardware_page_is_up_to_date(): void
    {
        $this->artisan('hardware:table', ['--check' => true])->assertExitCode(0);
    }

    public function test_check_fails_when_the_page_differs(): void
    {
        $page = file_get_contents(base_path('docs/user/hardware.md'));
        $stale = str_replace(HardwareTable::START, HardwareTable::START."\n\nhand edit", $page);

        $this->assertNotSame($page, $stale);
        $this->assertNotSame($stale, HardwareTable::render($stale));
        $this->assertSame($page, HardwareTable::render($stale));
    }

    public function test_every_driver_in_the_device_config_has_a_status(): void
    {
        $statuses = config('hardware-status.drivers');
        $valid = array_keys(config('hardware-status.statuses'));

        foreach (DeviceModels::all() as $brand => $models) {
            foreach ($models as $model => $definition) {
                $driver = $definition['driver'];
                $this->assertArrayHasKey($driver, $statuses, "No hardware status for driver {$driver} ({$brand} {$model}).");
            }
        }

        foreach ([...$statuses, ...array_merge(...array_values(config('hardware-status.models')))] as $entry) {
            $this->assertContains($entry['status'], $valid);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $entry['date']);
            $this->assertNotSame('', $entry['note']);
        }
    }

    public function test_status_models_exist_in_the_device_config(): void
    {
        foreach (config('hardware-status.models') as $brand => $models) {
            foreach (array_keys($models) as $model) {
                $this->assertNotNull(DeviceModels::find($brand, $model), "{$brand} {$model} is not in config/devices.php.");
            }
        }
    }

    public function test_json_lists_every_model_with_capabilities(): void
    {
        $data = json_decode(HardwareTable::json(), true);
        $count = array_sum(array_map('count', DeviceModels::all()));

        $this->assertCount($count, $data['devices']);

        $moment = collect($data['devices'])->firstWhere('model', 'BeoSound Moment');
        $m5 = collect($data['devices'])->firstWhere('model', 'Beoplay M5');
        $this->assertContains('wireless_speakers', $moment['capabilities']);
        $this->assertNotContains('wireless_speakers', $m5['capabilities']);
    }
}
