<?php

namespace Tests\Feature\Api;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBareDriver;
use Tests\Support\FakePowerDriver;
use Tests\TestCase;

class PowerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakePowerDriver::reset();
    }

    private function makeDevice(string $driver = FakePowerDriver::class, State $state = State::Standby): Device
    {
        $device = Device::factory()->create([
            'device_driver' => $driver,
        ]);

        DeviceCache::updateState($device->id, $state);

        return $device;
    }

    public function test_power_capability_is_reported(): void
    {
        $device = $this->makeDevice();

        $this->getJson("/api/devices/{$device->id}")->assertJsonPath('data.capabilities', ['power']);
    }

    public function test_power_on_and_standby(): void
    {
        $device = $this->makeDevice();

        $this->putJson("/api/devices/{$device->id}/power", ['on' => true])->assertOk()->assertExactJson(['on' => true]);
        $this->putJson("/api/devices/{$device->id}/power", ['on' => false])->assertOk()->assertExactJson(['on' => false]);

        $this->assertSame(['powerOn', 'standby'], FakePowerDriver::$calls);
    }

    public function test_on_is_required(): void
    {
        $device = $this->makeDevice();

        $this->putJson("/api/devices/{$device->id}/power", [])->assertStatus(422)->assertJsonValidationErrors('on');
    }

    public function test_unsupported_device_gets_422(): void
    {
        $device = $this->makeDevice(FakeBareDriver::class);

        $this->putJson("/api/devices/{$device->id}/power", ['on' => false])
            ->assertStatus(422)
            ->assertJsonPath('error', 'unsupported');
    }

    public function test_a_device_that_cannot_be_woken_gets_422_for_on_only(): void
    {
        $device = $this->makeDevice();
        FakePowerDriver::$standbyOnly = true;

        $this->putJson("/api/devices/{$device->id}/power", ['on' => true])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'unsupported', 'message' => 'This device cannot be switched on remotely.']);
        $this->putJson("/api/devices/{$device->id}/power", ['on' => false])->assertOk();
    }

    public function test_unreachable_gets_503_and_driver_errors_502(): void
    {
        $unreachable = $this->makeDevice(state: State::Unreachable);
        $this->putJson("/api/devices/{$unreachable->id}/power", ['on' => true])->assertStatus(503);

        $device = $this->makeDevice();
        FakePowerDriver::$throw = new \RuntimeException('timeout');
        $this->putJson("/api/devices/{$device->id}/power", ['on' => false])
            ->assertStatus(502)
            ->assertJsonPath('error', 'driver_error');
    }
}
