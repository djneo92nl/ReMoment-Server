<?php

namespace Tests\Feature\Api;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBareDriver;
use Tests\Support\FakeNetworkDriver;
use Tests\TestCase;

class NetworkSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeNetworkDriver::reset();
    }

    private function makeDevice(string $product = 'BeoSound Essence', string $driver = FakeNetworkDriver::class, State $state = State::Standby): Device
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

    public function test_wireless_speakers_are_only_listed_for_a_model_with_the_hardware(): void
    {
        $essence = $this->makeDevice('BeoSound Essence');
        $moment = $this->makeDevice('BeoSound Moment');

        $this->getJson("/api/devices/{$essence->id}")->assertJsonPath('data.capabilities', ['network_settings']);
        $this->getJson("/api/devices/{$moment->id}")->assertJsonPath('data.capabilities', ['network_settings', 'wireless_speakers']);
    }

    public function test_reads_the_network(): void
    {
        $device = $this->makeDevice();

        $this->getJson("/api/devices/{$device->id}/network")
            ->assertOk()
            ->assertJsonPath('active_interface', 'wired')
            ->assertJsonPath('interfaces', ['wired', 'wireless'])
            ->assertJsonPath('wired.address', '192.168.1.25')
            ->assertJsonPath('wired.alternative_dns', null)
            ->assertJsonPath('wifi_networks.0.ssid', 'HomeNet');
    }

    public function test_switches_interface_and_answers_without_a_read_back(): void
    {
        $device = $this->makeDevice();

        $this->putJson("/api/devices/{$device->id}/network/interface", ['interface' => 'wireless'])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertSame([['setActiveInterface', 'wireless']], FakeNetworkDriver::$calls);
    }

    public function test_a_refused_interface_is_422_invalid(): void
    {
        $device = $this->makeDevice();

        $this->putJson("/api/devices/{$device->id}/network/interface", ['interface' => 'none'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid');
        $this->putJson("/api/devices/{$device->id}/network/interface", [])->assertStatus(422)->assertJsonValidationErrors('interface');
    }

    public function test_sets_a_wired_address(): void
    {
        $device = $this->makeDevice();

        $this->putJson("/api/devices/{$device->id}/network/wired", ['dhcp' => true])->assertOk();
        $this->putJson("/api/devices/{$device->id}/network/wired", [
            'dhcp' => false, 'address' => '192.168.1.50', 'subnet_mask' => '255.255.255.0', 'gateway' => '192.168.1.1', 'preferred_dns' => null,
        ])->assertOk();

        $this->assertSame(['setWiredAddress', true, []], FakeNetworkDriver::$calls[0]);
        $this->assertSame(['setWiredAddress', false, ['address' => '192.168.1.50', 'subnet_mask' => '255.255.255.0', 'gateway' => '192.168.1.1', 'preferred_dns' => null]], FakeNetworkDriver::$calls[1]);
    }

    public function test_wired_input_is_validated(): void
    {
        $device = $this->makeDevice();

        $this->putJson("/api/devices/{$device->id}/network/wired", [])->assertStatus(422)->assertJsonValidationErrors('dhcp');
        $this->putJson("/api/devices/{$device->id}/network/wired", ['dhcp' => false, 'address' => 'not-an-ip'])->assertStatus(422)->assertJsonValidationErrors('address');
        $this->putJson("/api/devices/{$device->id}/network/wired", ['dhcp' => false])->assertStatus(422)->assertJsonPath('error', 'invalid');
        $this->assertSame([], FakeNetworkDriver::$calls);
    }

    public function test_joins_a_wifi_network_and_never_echoes_the_passphrase(): void
    {
        $device = $this->makeDevice();

        $response = $this->putJson("/api/devices/{$device->id}/network/wifi", ['ssid' => 'Guest', 'passphrase' => 'correct horse', 'security' => 'wpa2PskTkip'])->assertOk();

        $this->assertSame([['joinWifi', 'Guest', 'correct horse', 'wpa2PskTkip']], FakeNetworkDriver::$calls);
        $this->assertStringNotContainsString('correct horse', $response->getContent());
        $this->putJson("/api/devices/{$device->id}/network/wifi", ['ssid' => str_repeat('x', 33)])->assertStatus(422)->assertJsonValidationErrors('ssid');
        $this->putJson("/api/devices/{$device->id}/network/wifi", [])->assertStatus(422)->assertJsonValidationErrors('ssid');
    }

    public function test_the_moment_can_scan_for_wireless_speakers(): void
    {
        $moment = $this->makeDevice('BeoSound Moment');

        $this->getJson("/api/devices/{$moment->id}/wireless-speakers")->assertOk()->assertJsonPath('scanning', false)->assertJsonPath('speakers', []);

        $this->postJson("/api/devices/{$moment->id}/wireless-speakers/scan", ['action' => 'start'])
            ->assertOk()
            ->assertJsonPath('scanning', true)
            ->assertJsonPath('speakers.0.name', 'Front Left');
        $this->postJson("/api/devices/{$moment->id}/wireless-speakers/scan", ['action' => 'stop'])->assertOk()->assertJsonPath('scanning', false);

        $this->assertSame([['startWirelessScan'], ['stopWirelessScan']], FakeNetworkDriver::$calls);
        $this->postJson("/api/devices/{$moment->id}/wireless-speakers/scan", ['action' => 'pause'])->assertStatus(422)->assertJsonValidationErrors('action');
    }

    public function test_every_other_model_gets_422_for_wireless_speakers_even_though_the_driver_could(): void
    {
        foreach (['BeoSound Essence', 'Beoplay M3', 'Beoplay M5', ''] as $product) {
            $device = $this->makeDevice($product);

            $this->getJson("/api/devices/{$device->id}/wireless-speakers")->assertStatus(422)->assertJsonPath('error', 'unsupported');
            $this->postJson("/api/devices/{$device->id}/wireless-speakers/scan", ['action' => 'start'])->assertStatus(422)->assertJsonPath('error', 'unsupported');
        }

        $this->assertSame([], FakeNetworkDriver::$calls);
    }

    public function test_a_device_without_the_driver_support_gets_422(): void
    {
        $device = $this->makeDevice('BeoSound Moment', FakeBareDriver::class);

        foreach (['network', 'wireless-speakers'] as $path) {
            $this->getJson("/api/devices/{$device->id}/{$path}")->assertStatus(422)->assertJsonPath('error', 'unsupported');
        }
    }

    public function test_unreachable_gets_503_and_driver_failure_502(): void
    {
        $device = $this->makeDevice(state: State::Unreachable);
        $this->getJson("/api/devices/{$device->id}/network")->assertStatus(503)->assertJsonPath('error', 'unreachable');

        FakeNetworkDriver::$throw = new \RuntimeException('boom');
        $reachable = $this->makeDevice();
        $this->getJson("/api/devices/{$reachable->id}/network")->assertStatus(502)->assertJsonPath('error', 'driver_error');
        $this->putJson("/api/devices/{$reachable->id}/network/interface", ['interface' => 'wired'])->assertStatus(502);
    }
}
