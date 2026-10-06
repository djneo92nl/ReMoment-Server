<?php

namespace Tests\Feature\Api;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBareDriver;
use Tests\Support\FakeWiredSpeakersDriver;
use Tests\TestCase;

class WiredSpeakersApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeWiredSpeakersDriver::reset();
    }

    private function makeDevice(string $product = 'BeoSound Moment', string $driver = FakeWiredSpeakersDriver::class, State $state = State::Standby): Device
    {
        $device = Device::create([
            'ip_address' => '10.0.0.10',
            'device_name' => 'Living Room',
            'device_brand_name' => 'Bang & Olufsen',
            'device_product_type' => $product,
            'device_driver' => $driver,
            'device_driver_name' => 'Fake',
        ]);

        DeviceCache::updateState($device->id, $state);

        return $device;
    }

    public function test_only_models_with_external_speakers_list_the_capability(): void
    {
        foreach (['BeoSound Moment', 'BeoSound Essence'] as $product) {
            $this->getJson('/api/devices/'.$this->makeDevice($product)->id)->assertJsonPath('data.capabilities', ['wired_speakers']);
        }

        foreach (['Beoplay M3', 'Beoplay M5', ''] as $product) {
            $this->getJson('/api/devices/'.$this->makeDevice($product)->id)->assertJsonPath('data.capabilities', []);
        }
    }

    public function test_reads_the_outputs(): void
    {
        $device = $this->makeDevice();

        $this->getJson("/api/devices/{$device->id}/wired-speakers")
            ->assertOk()
            ->assertJsonPath('speakers.0.id', 'pl_1')
            ->assertJsonPath('speakers.0.position', 'left')
            ->assertJsonPath('speakers.0.connected', true)
            ->assertJsonPath('speakers.0.type', 'Other')
            ->assertJsonPath('speakers.0.types', FakeWiredSpeakersDriver::TYPES)
            ->assertJsonPath('speakers.1.position', 'right');
    }

    public function test_sets_a_speaker_type_and_returns_the_new_state(): void
    {
        $device = $this->makeDevice();

        $this->putJson("/api/devices/{$device->id}/wired-speakers/pl_2", ['type' => 'Beolab 3'])
            ->assertOk()
            ->assertJsonPath('speakers.1.type', 'Beolab 3')
            ->assertJsonPath('speakers.0.type', 'Other');

        $this->assertSame([['pl_2', 'Beolab 3', null]], FakeWiredSpeakersDriver::$calls);
    }

    public function test_stops_a_test_noise(): void
    {
        FakeWiredSpeakersDriver::$outputs['pl_1']['sound'] = 'localizationNoise';
        $device = $this->makeDevice();

        $this->putJson("/api/devices/{$device->id}/wired-speakers/pl_1", ['sound' => 'none'])->assertOk()->assertJsonPath('speakers.0.sound', 'none');
    }

    public function test_something_to_change_is_required_and_bad_values_are_422(): void
    {
        $device = $this->makeDevice();

        $this->putJson("/api/devices/{$device->id}/wired-speakers/pl_1", [])->assertStatus(422)->assertJsonValidationErrors(['type', 'sound']);
        $this->putJson("/api/devices/{$device->id}/wired-speakers/pl_1", ['type' => 'Beolab 99'])->assertStatus(422)->assertJsonPath('error', 'invalid');
        $this->putJson("/api/devices/{$device->id}/wired-speakers/pl_9", ['type' => 'Line'])->assertStatus(422)->assertJsonPath('error', 'invalid');
        $this->assertSame([], FakeWiredSpeakersDriver::$calls);
    }

    public function test_a_model_with_internal_speakers_gets_422_even_though_the_driver_could(): void
    {
        foreach (['Beoplay M3', 'Beoplay M5', ''] as $product) {
            $device = $this->makeDevice($product);

            $this->getJson("/api/devices/{$device->id}/wired-speakers")->assertStatus(422)->assertJsonPath('error', 'unsupported');
            $this->putJson("/api/devices/{$device->id}/wired-speakers/pl_1", ['type' => 'Line'])->assertStatus(422)->assertJsonPath('error', 'unsupported');
        }

        $this->assertSame([], FakeWiredSpeakersDriver::$calls);
    }

    public function test_a_driver_without_support_gets_422_unreachable_503_and_failure_502(): void
    {
        $bare = $this->makeDevice('BeoSound Moment', FakeBareDriver::class);
        $this->getJson("/api/devices/{$bare->id}/wired-speakers")->assertStatus(422)->assertJsonPath('error', 'unsupported');

        $down = $this->makeDevice(state: State::Unreachable);
        $this->getJson("/api/devices/{$down->id}/wired-speakers")->assertStatus(503);

        FakeWiredSpeakersDriver::$throw = new \RuntimeException('boom');
        $up = $this->makeDevice();
        $this->getJson("/api/devices/{$up->id}/wired-speakers")->assertStatus(502)->assertJsonPath('error', 'driver_error');
    }
}
