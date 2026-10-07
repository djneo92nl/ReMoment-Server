<?php

namespace Tests\Feature\Api;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBareDriver;
use Tests\Support\FakeSleepDigitsDriver;
use Tests\TestCase;

class SleepTimerDigitsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeSleepDigitsDriver::reset();
    }

    private function makeDevice(string $driver = FakeSleepDigitsDriver::class, State $state = State::Playing): Device
    {
        $device = Device::create([
            'ip_address' => '10.0.0.10',
            'device_name' => 'TV',
            'device_brand_name' => 'Test',
            'device_product_type' => 'TV',
            'device_driver' => $driver,
            'device_driver_name' => 'Fake',
        ]);

        DeviceCache::updateState($device->id, $state);

        return $device;
    }

    public function test_capabilities_are_reported(): void
    {
        $device = $this->makeDevice();

        $this->getJson("/api/devices/{$device->id}")->assertJsonPath('data.capabilities', ['sleep_timer', 'digits']);
    }

    public function test_reads_and_sets_the_sleep_timer(): void
    {
        $device = $this->makeDevice();

        $this->getJson("/api/devices/{$device->id}/sleep-timer")
            ->assertOk()
            ->assertExactJson(['value' => 0, 'min' => 0, 'max' => 60, 'step' => 1, 'writable' => true]);

        $this->putJson("/api/devices/{$device->id}/sleep-timer", ['minutes' => 30])->assertOk()->assertJsonPath('value', 30);
        $this->putJson("/api/devices/{$device->id}/sleep-timer", ['minutes' => 0])->assertOk()->assertJsonPath('value', 0);
    }

    public function test_sleep_timer_validation(): void
    {
        $device = $this->makeDevice();

        $this->putJson("/api/devices/{$device->id}/sleep-timer", [])->assertStatus(422)->assertJsonValidationErrors('minutes');
        $this->putJson("/api/devices/{$device->id}/sleep-timer", ['minutes' => 'soon'])->assertStatus(422)->assertJsonValidationErrors('minutes');
        $this->putJson("/api/devices/{$device->id}/sleep-timer", ['minutes' => 61])->assertStatus(422)->assertJsonPath('error', 'invalid');
        $this->assertSame(0, FakeSleepDigitsDriver::$minutes);
    }

    public function test_a_platform_that_cannot_write_the_timer_says_so(): void
    {
        FakeSleepDigitsDriver::$writable = false;
        $device = $this->makeDevice();

        $this->getJson("/api/devices/{$device->id}/sleep-timer")->assertOk()->assertJsonPath('writable', false);
        $this->putJson("/api/devices/{$device->id}/sleep-timer", ['minutes' => 10])->assertStatus(422)->assertJsonPath('error', 'unsupported');
    }

    public function test_sends_digits_in_order(): void
    {
        $device = $this->makeDevice();

        $this->postJson("/api/devices/{$device->id}/digits", ['digits' => '105'])
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'digits' => '105']);

        $this->assertSame([1, 0, 5], FakeSleepDigitsDriver::$digits);
    }

    public function test_digits_validation(): void
    {
        $device = $this->makeDevice();

        foreach ([null, '', 'ab', '-1', '123456789', 12] as $digits) {
            $this->postJson("/api/devices/{$device->id}/digits", ['digits' => $digits])->assertStatus(422)->assertJsonValidationErrors('digits');
        }
        $this->assertSame([], FakeSleepDigitsDriver::$digits);
    }

    public function test_unsupported_device_gets_422(): void
    {
        $device = $this->makeDevice(FakeBareDriver::class);

        $this->getJson("/api/devices/{$device->id}/sleep-timer")->assertStatus(422)->assertJsonPath('error', 'unsupported');
        $this->postJson("/api/devices/{$device->id}/digits", ['digits' => '1'])->assertStatus(422)->assertJsonPath('error', 'unsupported');
    }

    public function test_unreachable_gets_503_and_driver_failure_502(): void
    {
        $unreachable = $this->makeDevice(state: State::Unreachable);
        $this->postJson("/api/devices/{$unreachable->id}/digits", ['digits' => '1'])->assertStatus(503);

        FakeSleepDigitsDriver::$throw = new \RuntimeException('boom');
        $device = $this->makeDevice();
        $this->getJson("/api/devices/{$device->id}/sleep-timer")->assertStatus(502)->assertJsonPath('error', 'driver_error');
    }
}
