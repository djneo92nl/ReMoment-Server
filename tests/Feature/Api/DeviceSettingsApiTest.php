<?php

namespace Tests\Feature\Api;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBareDriver;
use Tests\Support\FakeSettingsDriver;
use Tests\TestCase;

class DeviceSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeSettingsDriver::reset();
    }

    private function makeDevice(string $driver = FakeSettingsDriver::class, State $state = State::Standby): Device
    {
        $device = Device::create([
            'ip_address' => '10.0.0.10',
            'device_name' => 'Living Room',
            'device_brand_name' => 'Test',
            'device_product_type' => 'Speaker',
            'device_driver' => $driver,
            'device_driver_name' => 'Fake',
        ]);

        DeviceCache::updateState($device->id, $state);

        return $device;
    }

    public function test_capabilities_are_reported(): void
    {
        $device = $this->makeDevice();

        $this->getJson("/api/devices/{$device->id}")
            ->assertJsonPath('data.capabilities', ['sound_adjustment', 'bluetooth', 'device_info']);
    }

    public function test_reads_sound_adjustment(): void
    {
        $device = $this->makeDevice();

        $this->getJson("/api/devices/{$device->id}/sound-adjustment")
            ->assertOk()
            ->assertExactJson([
                'bass' => ['value' => 1, 'min' => -10, 'max' => 10, 'step' => 1],
                'treble' => ['value' => 0, 'min' => -10, 'max' => 10, 'step' => 1],
                'loudness' => true,
            ]);
    }

    public function test_changes_only_what_is_sent(): void
    {
        $device = $this->makeDevice();

        $this->putJson("/api/devices/{$device->id}/sound-adjustment", ['bass' => 4, 'loudness' => false])
            ->assertOk()
            ->assertJsonPath('bass.value', 4)
            ->assertJsonPath('treble.value', 0)
            ->assertJsonPath('loudness', false);

        $this->assertSame([['setSoundAdjustment', 4, null, false]], FakeSettingsDriver::$calls);
    }

    public function test_sound_adjustment_validates_types_and_range(): void
    {
        $device = $this->makeDevice();

        $this->putJson("/api/devices/{$device->id}/sound-adjustment", ['bass' => 'loud'])->assertStatus(422)->assertJsonValidationErrors('bass');
        $this->putJson("/api/devices/{$device->id}/sound-adjustment", ['bass' => 11])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid');
        $this->assertSame([], FakeSettingsDriver::$calls);
    }

    public function test_reads_and_changes_bluetooth(): void
    {
        $device = $this->makeDevice();

        $this->getJson("/api/devices/{$device->id}/bluetooth")
            ->assertOk()
            ->assertJsonPath('discoverable', false)
            ->assertJsonPath('writable', true)
            ->assertJsonPath('reconnect_modes', ['manual', 'automatic', 'disabled'])
            ->assertJsonPath('devices.0', ['id' => 'phone-1', 'name' => 'Remko iPhone', 'address' => 'AA:BB:CC:DD:EE:FF', 'connected' => true]);

        $this->putJson("/api/devices/{$device->id}/bluetooth", ['discoverable' => true, 'reconnect_mode' => 'automatic'])
            ->assertOk()
            ->assertJsonPath('discoverable', true)
            ->assertJsonPath('reconnect_mode', 'automatic');

        $this->assertSame([['setBluetooth', true, 'automatic']], FakeSettingsDriver::$calls);
    }

    public function test_unknown_reconnect_mode_is_rejected(): void
    {
        $device = $this->makeDevice();

        $this->putJson("/api/devices/{$device->id}/bluetooth", ['reconnect_mode' => 'sometimes'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid');
    }

    public function test_read_only_bluetooth_cannot_be_changed(): void
    {
        FakeSettingsDriver::$writable = false;
        $device = $this->makeDevice();

        $this->getJson("/api/devices/{$device->id}/bluetooth")->assertOk()->assertJsonPath('writable', false);
        $this->putJson("/api/devices/{$device->id}/bluetooth", ['discoverable' => true])
            ->assertStatus(422)
            ->assertJsonPath('error', 'unsupported');
    }

    public function test_removes_a_paired_bluetooth_device(): void
    {
        $device = $this->makeDevice();

        $this->deleteJson("/api/devices/{$device->id}/bluetooth/devices/phone-1")
            ->assertOk()
            ->assertJsonPath('devices', []);
        $this->deleteJson("/api/devices/{$device->id}/bluetooth/devices/phone-1")
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid');
    }

    public function test_reads_and_renames_the_device(): void
    {
        $device = $this->makeDevice();

        $this->getJson("/api/devices/{$device->id}/info")
            ->assertOk()
            ->assertExactJson(['name' => 'Living Room', 'product_type' => 'FAKE_SPEAKER', 'firmware' => '1.0', 'mac_address' => 'AA:AA:AA:AA:AA:AA', 'renamable' => true]);

        $this->putJson("/api/devices/{$device->id}/info", ['name' => 'Kitchen'])->assertOk()->assertJsonPath('name', 'Kitchen');

        $this->assertSame('Kitchen', $device->fresh()->device_name);
        $this->putJson("/api/devices/{$device->id}/info", [])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->putJson("/api/devices/{$device->id}/info", ['name' => str_repeat('x', 65)])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_a_platform_that_cannot_rename_says_so(): void
    {
        FakeSettingsDriver::$renamable = false;
        $device = $this->makeDevice();

        $this->getJson("/api/devices/{$device->id}/info")->assertOk()->assertJsonPath('renamable', false);
        $this->putJson("/api/devices/{$device->id}/info", ['name' => 'Kitchen'])->assertStatus(422)->assertJsonPath('error', 'unsupported');
        $this->assertSame('Living Room', $device->fresh()->device_name);
    }

    public function test_unsupported_device_gets_422(): void
    {
        $device = $this->makeDevice(FakeBareDriver::class);

        foreach (['sound-adjustment', 'bluetooth', 'info'] as $path) {
            $this->getJson("/api/devices/{$device->id}/{$path}")
                ->assertStatus(422)
                ->assertJsonPath('error', 'unsupported');
        }
    }

    public function test_unreachable_device_gets_503(): void
    {
        $device = $this->makeDevice(state: State::Unreachable);

        foreach (['sound-adjustment', 'bluetooth', 'info'] as $path) {
            $this->getJson("/api/devices/{$device->id}/{$path}")->assertStatus(503)->assertJsonPath('error', 'unreachable');
        }
    }

    public function test_driver_failure_gets_502(): void
    {
        FakeSettingsDriver::$throw = new \RuntimeException('boom');
        $device = $this->makeDevice();

        $this->getJson("/api/devices/{$device->id}/bluetooth")->assertStatus(502)->assertJsonPath('error', 'driver_error');
        $this->putJson("/api/devices/{$device->id}/info", ['name' => 'Kitchen'])->assertStatus(502);
        $this->assertSame('Living Room', $device->fresh()->device_name);
    }
}
