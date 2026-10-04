<?php

namespace Tests\Unit;

use App\Integrations\BangOlufsen\Ase\Connectors\BluetoothControls;
use App\Integrations\BangOlufsen\Ase\Connectors\DeviceInfoControls;
use App\Integrations\BangOlufsen\Ase\Connectors\SoundAdjustmentControls;
use App\Integrations\Common\HttpConnector;
use App\Integrations\Common\UnsupportedOperationException;
use Tests\Support\FakeAseConnector;
use Tests\TestCase;

/** Shapes below are what an Essence Mk2 (fw 6.7) answers. */
class AseSettingsConnectorsTest extends TestCase
{
    private function driver(FakeAseConnector $api): object
    {
        return new class($api)
        {
            use BluetoothControls;
            use DeviceInfoControls;
            use SoundAdjustmentControls;

            public function __construct(private HttpConnector $api) {}

            protected function settleBluetooth(): void {}

            protected function deviceApiClient(): HttpConnector
            {
                return $this->api;
            }
        };
    }

    private function adjustment(int $bass = 1, int $treble = 0, bool $loudness = true): array
    {
        return ['adjustment' => [
            'bass' => $bass, 'treble' => $treble, 'loudness' => $loudness,
            '_capabilities' => [
                'editable' => ['bass', 'treble', 'loudness'],
                'range' => ['bass' => [['min' => -10, 'max' => 10, 'step' => 1]], 'treble' => [['min' => -10, 'max' => 10, 'step' => 1]]],
            ],
        ]];
    }

    private function bluetooth(string $mode = 'notDiscoverable', string $reconnect = 'manual', array $devices = []): array
    {
        return ['profile' => ['bluetooth' => [
            'deviceSettings' => [
                'deviceName' => 'Woonkamer Speakers', 'bluetoothOn' => true, 'discoverabilityMode' => $mode,
                'alwaysOpen' => false, 'reconnectMode' => $reconnect,
                '_capabilities' => [
                    'editable' => ['deviceName', 'discoverabilityMode', 'alwaysOpen', 'reconnectMode'],
                    'value' => ['discoverabilityMode' => ['notDiscoverable', 'discoverable'], 'reconnectMode' => ['manual', 'automatic', 'disabled']],
                ],
            ],
            'devices' => ['device' => $devices],
        ]]];
    }

    public function test_reads_sound_adjustment_with_its_ranges(): void
    {
        $api = (new FakeAseConnector)->seed('BeoZone/Zone/Sound/Adjustment', $this->adjustment(bass: 3));

        $result = $this->driver($api)->getSoundAdjustment()->toArray();

        $this->assertSame(['value' => 3, 'min' => -10, 'max' => 10, 'step' => 1], $result['bass']);
        $this->assertTrue($result['loudness']);
    }

    public function test_writes_the_whole_adjustment_resource_and_checks_it_took(): void
    {
        $api = (new FakeAseConnector)->seed('BeoZone/Zone/Sound/Adjustment', $this->adjustment())
            ->onPut('BeoZone/Zone/Sound/Adjustment', fn ($body, $a) => $a->replace('BeoZone/Zone/Sound/Adjustment', $this->adjustment(...$body['adjustment'])));

        $this->driver($api)->setSoundAdjustment(bass: 5);

        $this->assertSame([['adjustment' => ['bass' => 5, 'treble' => 0, 'loudness' => true]]], $api->bodies);
    }

    public function test_rejects_values_outside_the_range_without_writing(): void
    {
        $api = (new FakeAseConnector)->seed('BeoZone/Zone/Sound/Adjustment', $this->adjustment());

        try {
            $this->driver($api)->setSoundAdjustment(treble: 11);
            $this->fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('between -10 and 10', $e->getMessage());
        }

        $this->assertSame([], $api->bodies);
    }

    public function test_a_change_the_device_ignores_is_reported(): void
    {
        $api = (new FakeAseConnector)->seed('BeoZone/Zone/Sound/Adjustment', $this->adjustment());
        $api->ignoreWrites = true;

        $this->expectExceptionMessage('did not apply the bass change');

        $this->driver($api)->setSoundAdjustment(bass: 5);
    }

    public function test_a_missing_adjustment_part_is_unsupported(): void
    {
        $adjustment = $this->adjustment();
        unset($adjustment['adjustment']['loudness']);
        $api = (new FakeAseConnector)->seed('BeoZone/Zone/Sound/Adjustment', $adjustment);

        $this->expectException(UnsupportedOperationException::class);

        $this->driver($api)->setSoundAdjustment(loudness: true);
    }

    public function test_an_unreachable_or_erroring_device_is_not_read_as_empty(): void
    {
        $api = (new FakeAseConnector)->seed('BeoZone/Zone/Sound/Adjustment', ['error' => ['type' => 'INTERNAL', 'message' => 'standby']], 500);

        $this->expectExceptionMessage('HTTP 500');

        $this->driver($api)->getSoundAdjustment();
    }

    public function test_reads_bluetooth_settings_and_paired_devices(): void
    {
        $api = (new FakeAseConnector)->seed('BeoDevice/bluetoothSettings', $this->bluetooth(devices: [
            ['id' => 'phone-1', 'deviceName' => 'Remko iPhone', 'deviceAddress' => 'AA:BB:CC:DD:EE:FF', 'connected' => true],
        ]));

        $state = $this->driver($api)->getBluetooth()->toArray();

        $this->assertTrue($state['enabled']);
        $this->assertFalse($state['discoverable']);
        $this->assertTrue($state['writable']);
        $this->assertSame(['manual', 'automatic', 'disabled'], $state['reconnect_modes']);
        $this->assertSame(['id' => 'phone-1', 'name' => 'Remko iPhone', 'address' => 'AA:BB:CC:DD:EE:FF', 'connected' => true], $state['devices'][0]);
    }

    public function test_making_the_device_discoverable_sends_every_field_the_device_requires(): void
    {
        $api = (new FakeAseConnector)->seed('BeoDevice/bluetoothSettings', $this->bluetooth())
            ->onPut('BeoDevice/bluetoothSettings/deviceSettings', fn ($body, $a) => $a->replace(
                'BeoDevice/bluetoothSettings',
                $this->bluetooth($body['deviceSettings']['discoverabilityMode'], $body['deviceSettings']['reconnectMode'])
            ));

        $this->driver($api)->setBluetooth(discoverable: true);

        $this->assertSame([['deviceSettings' => [
            'deviceName' => 'Woonkamer Speakers', 'bluetoothOn' => true, 'discoverabilityMode' => 'discoverable', 'alwaysOpen' => false, 'reconnectMode' => 'manual',
        ]]], $api->bodies);
    }

    public function test_unknown_reconnect_mode_is_rejected_before_writing(): void
    {
        $api = (new FakeAseConnector)->seed('BeoDevice/bluetoothSettings', $this->bluetooth());

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->driver($api)->setBluetooth(reconnectMode: 'whenever');
        } finally {
            $this->assertSame([], $api->bodies);
        }
    }

    public function test_removes_a_paired_device_through_its_own_delete_link(): void
    {
        $api = (new FakeAseConnector)->seed('BeoDevice/bluetoothSettings', $this->bluetooth(devices: [
            ['id' => 'phone-1', 'deviceName' => 'Remko iPhone', '_links' => ['/relation/delete' => ['href' => './device/phone-1']]],
            ['id' => 'phone-2', 'deviceName' => 'Other'],
        ]));

        $api->onPut('BeoDevice/bluetoothSettings/device/phone-1', fn ($body, $a) => $a->replace('BeoDevice/bluetoothSettings', $this->bluetooth(devices: [['id' => 'phone-2', 'deviceName' => 'Other']])));

        $this->driver($api)->removeBluetoothDevice('phone-1');

        $this->assertContains('DELETE BeoDevice/bluetoothSettings/device/phone-1', $api->requests);
    }

    public function test_matches_the_url_encoded_id_the_device_uses_however_the_client_sends_it(): void
    {
        $paired = [['id' => 'FC%3aB2%3a14', 'deviceName' => 'MacBook', 'deviceAddress' => 'FC:B2:14', '_links' => ['/relation/delete' => ['href' => './device/FC%3aB2%3a14']]]];

        foreach (['FC%3aB2%3a14', 'FC:B2:14'] as $id) {
            $api = (new FakeAseConnector)->seed('BeoDevice/bluetoothSettings', $this->bluetooth(devices: $paired))
                ->onPut('BeoDevice/bluetoothSettings/device/FC%3aB2%3a14', fn ($body, $a) => $a->replace('BeoDevice/bluetoothSettings', $this->bluetooth()));

            $this->driver($api)->removeBluetoothDevice($id);

            $this->assertContains('DELETE BeoDevice/bluetoothSettings/device/FC%3aB2%3a14', $api->requests);
        }
    }

    public function test_a_removal_the_device_does_not_apply_is_reported(): void
    {
        $api = (new FakeAseConnector)->seed('BeoDevice/bluetoothSettings', $this->bluetooth(devices: [
            ['id' => 'phone-1', 'deviceName' => 'Remko iPhone', '_links' => ['/relation/delete' => ['href' => './device/phone-1']]],
        ]));

        $this->expectExceptionMessage('did not remove the Bluetooth device');

        $this->driver($api)->removeBluetoothDevice('phone-1');
    }

    public function test_the_device_waits_for_a_slow_removal(): void
    {
        $listed = ['id' => 'phone-1', 'deviceName' => 'Remko iPhone', '_links' => ['/relation/delete' => ['href' => './device/phone-1']]];
        $api = new class extends FakeAseConnector
        {
            public int $reads = 0;

            protected function send(string $method, string $url, array $data = []): array
            {
                // Still listed for the first two reads after the DELETE, gone on the third.
                if ($method === 'GET' && str_ends_with(rtrim($url, '/'), 'bluetoothSettings') && in_array('DELETE BeoDevice/bluetoothSettings/device/phone-1', $this->requests, true)) {
                    $this->reads++;
                    if ($this->reads >= 3) {
                        $this->replace('BeoDevice/bluetoothSettings', ['profile' => ['bluetooth' => ['devices' => ['device' => []]]]]);
                    }
                }

                return parent::send($method, $url, $data);
            }
        };
        $api->seed('BeoDevice/bluetoothSettings', $this->bluetooth(devices: [$listed]));

        $this->driver($api)->removeBluetoothDevice('phone-1');

        $this->assertSame(3, $api->reads);
    }

    public function test_devices_the_speaker_has_seen_but_not_paired_are_not_listed(): void
    {
        $api = (new FakeAseConnector)->seed('BeoDevice/bluetoothSettings', $this->bluetooth(devices: [
            ['id' => 'a', 'deviceName' => 'Paired', 'paired' => true],
            ['id' => 'b', 'deviceName' => 'Only seen', 'paired' => false],
        ]));

        $names = array_column($this->driver($api)->getBluetooth()->toArray()['devices'], 'name');

        $this->assertSame(['Paired'], $names);
    }

    public function test_a_device_without_a_delete_link_is_unsupported_and_an_unknown_one_invalid(): void
    {
        $api = (new FakeAseConnector)->seed('BeoDevice/bluetoothSettings', $this->bluetooth(devices: [['id' => 'phone-2', 'deviceName' => 'Other']]));
        $driver = $this->driver($api);

        try {
            $driver->removeBluetoothDevice('phone-2');
            $this->fail('expected UnsupportedOperationException');
        } catch (UnsupportedOperationException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        $driver->removeBluetoothDevice('nobody');
    }

    public function test_reads_device_info(): void
    {
        $api = (new FakeAseConnector)->seed('BeoDevice', ['beoDevice' => [
            'productId' => ['productType' => 'BEOSOUND_ESSENCE_MK2'],
            'productFriendlyName' => ['productFriendlyName' => 'Woonkamer Speakers'],
            'software' => ['version' => '6.7.58710.100086671'],
            'hardware' => ['mac' => 'B0:D5:CC:44:D1:D5'],
        ]]);

        $this->assertSame(
            ['name' => 'Woonkamer Speakers', 'product_type' => 'BEOSOUND_ESSENCE_MK2', 'firmware' => '6.7.58710.100086671', 'mac_address' => 'B0:D5:CC:44:D1:D5', 'renamable' => true],
            $this->driver($api)->getDeviceInfo()->toArray()
        );
    }

    public function test_rename_is_verified_by_reading_the_name_back(): void
    {
        $info = fn (string $name) => ['beoDevice' => ['productFriendlyName' => ['productFriendlyName' => $name]]];
        $api = (new FakeAseConnector)->seed('BeoDevice', $info('Old'))
            ->onPut('BeoDevice/productFriendlyName', fn ($body, $a) => $a->replace('BeoDevice', $info($body['productFriendlyName']['productFriendlyName'])));

        $this->driver($api)->setDeviceName('Kitchen');
        $this->assertSame('Kitchen', $this->driver($api)->getDeviceInfo()->name);

        $api->ignoreWrites = true;
        $this->expectExceptionMessage('did not apply the new name');
        $this->driver($api)->setDeviceName('Bedroom');
    }
}
