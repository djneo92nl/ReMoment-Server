<?php

namespace Tests\Feature\Api;

use App\Domain\Device\BatteryStatus;
use App\Domain\Device\Cache\Battery;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Events\Device\BatteryUpdated;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBatteryDriver;
use Tests\TestCase;

/** Battery level: cache, `battery` and the capability in the API, MQTT `/battery`, the web card. */
class BatteryApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeDevice(): Device
    {
        $device = Device::factory()->create([
            'device_name' => 'Roam',
            'device_driver' => FakeBatteryDriver::class,
        ]);

        DeviceCache::updateState($device->id, State::Standby);

        return $device;
    }

    public function test_a_device_without_a_reported_battery_has_none(): void
    {
        $device = $this->makeDevice();

        $this->getJson("/api/devices/{$device->id}")
            ->assertOk()
            ->assertJsonPath('data.battery', null)
            ->assertJsonMissing(['battery' => 'battery'])
            ->assertJsonPath('data.capabilities', []);
    }

    public function test_the_event_caches_and_publishes_the_battery(): void
    {
        $device = $this->makeDevice();

        event(new BatteryUpdated((string) $device->id, new BatteryStatus(42, true)));

        $this->assertEquals(new BatteryStatus(42, true), Battery::get($device->id));
        $this->getJson("/api/devices/{$device->id}")
            ->assertJsonPath('data.battery', ['level' => 42, 'charging' => true])
            ->assertJsonPath('data.capabilities', ['battery']);
        $this->getJson('/api/devices')->assertJsonPath('data.0.battery.level', 42);

        $published = array_values(array_filter($this->mqtt->published, fn ($p) => $p['topic'] === "remoment/player/{$device->id}/battery"));
        $this->assertCount(1, $published);
        $this->assertSame('{"level":42,"charging":true}', $published[0]['message']);
    }

    public function test_an_unchanged_battery_is_not_published_again(): void
    {
        $device = $this->makeDevice();

        event(new BatteryUpdated((string) $device->id, new BatteryStatus(42)));
        event(new BatteryUpdated((string) $device->id, new BatteryStatus(42)));
        event(new BatteryUpdated((string) $device->id, new BatteryStatus(41)));

        $published = array_filter($this->mqtt->published, fn ($p) => $p['topic'] === "remoment/player/{$device->id}/battery");
        $this->assertCount(2, $published);
    }
}
