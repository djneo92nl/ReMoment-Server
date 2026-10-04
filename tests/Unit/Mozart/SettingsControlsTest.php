<?php

namespace Tests\Unit\Mozart;

use App\Integrations\Common\UnsupportedOperationException;
use App\Models\Device;
use Djneo92nl\BeoMozart\MozartClient;
use Remoment\MozartDriver\SettingsControls;
use Tests\Support\FakeMozartTransport;
use Tests\TestCase;

class SettingsControlsTest extends TestCase
{
    private function driver(FakeMozartTransport $transport): object
    {
        $device = new Device(['device_name' => 'Beosound Balance', 'device_product_type' => 'Beosound Balance']);

        return new class(new MozartClient('mozart.test', transport: $transport), $device)
        {
            use SettingsControls;

            public function __construct(public MozartClient $client, public Device $device) {}
        };
    }

    private function sound(FakeMozartTransport $t, array $adjustments = ['bass' => 2, 'treble' => -1, 'loudness' => true], ?array $features = null): FakeMozartTransport
    {
        return $t
            ->seed('/api/v1/state', ['soundSettings' => ['adjustments' => $adjustments]])
            ->seed('/api/v1/sound/features', $features ?? ['standalone' => [
                'bass' => ['value' => 2, 'default' => ['value' => 0], 'range' => [['value' => -6], ['value' => 6]]],
                'treble' => ['value' => -1, 'default' => ['value' => 0], 'range' => [['value' => -6], ['value' => 6]]],
            ]]);
    }

    public function test_reads_the_adjustments_with_the_ranges_the_product_reports(): void
    {
        $result = $this->driver($this->sound(new FakeMozartTransport))->getSoundAdjustment()->toArray();

        $this->assertSame(['value' => 2, 'min' => -6, 'max' => 6, 'step' => 1], $result['bass']);
        $this->assertSame(['value' => -1, 'min' => -6, 'max' => 6, 'step' => 1], $result['treble']);
        $this->assertTrue($result['loudness']);
    }

    public function test_a_range_given_as_a_list_of_values_gets_its_step(): void
    {
        $features = ['standalone' => ['bass' => ['range' => [['value' => -4], ['value' => -2], ['value' => 0], ['value' => 2], ['value' => 4]]]]];

        $result = $this->driver($this->sound(new FakeMozartTransport, ['bass' => 2], $features))->getSoundAdjustment()->toArray();

        $this->assertSame(['value' => 2, 'min' => -4, 'max' => 4, 'step' => 2], $result['bass']);
        $this->assertNull($result['treble']);
        $this->assertNull($result['loudness']);
    }

    public function test_the_first_role_that_has_the_feature_is_used(): void
    {
        $features = [
            'multichannel' => ['treble' => ['range' => [['value' => -2], ['value' => 2]]]],
            'standalone' => ['bass' => ['range' => [['value' => -6], ['value' => 6]]], 'treble' => ['range' => [['value' => -6], ['value' => 6]]]],
        ];

        $result = $this->driver($this->sound(new FakeMozartTransport, features: $features))->getSoundAdjustment()->toArray();

        $this->assertSame(-2, $result['treble']['min']);
        $this->assertSame(-6, $result['bass']['min']);
    }

    public function test_no_answer_is_an_error_not_an_empty_result(): void
    {
        $this->expectExceptionMessage('did not answer');

        $this->driver(new FakeMozartTransport)->getSoundAdjustment();
    }

    public function test_writes_only_the_parts_that_changed(): void
    {
        $transport = $this->sound(new FakeMozartTransport);

        $this->driver($transport)->setSoundAdjustment(bass: 4, loudness: false);

        $this->assertSame(['PUT /api/v1/sound/settings/adjustments/bass', 'PUT /api/v1/sound/settings/adjustments/loudness'], array_slice($transport->requests, -2));
        $this->assertSame([['value' => 4], ['value' => false]], $transport->bodies);
    }

    public function test_a_value_outside_the_range_is_rejected_before_anything_is_written(): void
    {
        $transport = $this->sound(new FakeMozartTransport);

        try {
            $this->driver($transport)->setSoundAdjustment(bass: 7);
            $this->fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('between -6 and 6', $e->getMessage());
        }

        $this->assertSame([], $transport->bodies);
    }

    public function test_a_part_the_product_lacks_is_unsupported(): void
    {
        $transport = $this->sound(new FakeMozartTransport, ['bass' => 2]);

        $this->expectException(UnsupportedOperationException::class);

        $this->driver($transport)->setSoundAdjustment(loudness: true);
    }

    public function test_bluetooth_is_a_read_only_list(): void
    {
        $transport = (new FakeMozartTransport)->seed('/api/v1/setup/bluetooth/devices', ['items' => [
            ['address' => 'AA:BB:CC', 'name' => 'Remko iPhone', 'connected' => true],
        ]]);
        $driver = $this->driver($transport);

        $state = $driver->getBluetooth()->toArray();

        $this->assertFalse($state['writable']);
        $this->assertSame([], $state['reconnect_modes']);
        $this->assertSame(['id' => 'AA:BB:CC', 'name' => 'Remko iPhone', 'address' => 'AA:BB:CC', 'connected' => true], $state['devices'][0]);

        foreach ([fn () => $driver->setBluetooth(discoverable: true), fn () => $driver->removeBluetoothDevice('AA:BB:CC')] as $change) {
            try {
                $change();
                $this->fail('expected UnsupportedOperationException');
            } catch (UnsupportedOperationException) {
            }
        }
    }

    public function test_device_info_uses_our_name_and_the_devices_firmware(): void
    {
        $transport = (new FakeMozartTransport)->seed('/api/v1/softwareupdate', ['softwareVersion' => '2.1.0', 'state' => ['value' => 'idle']]);

        $info = $this->driver($transport)->getDeviceInfo()->toArray();

        $this->assertSame(['name' => 'Beosound Balance', 'product_type' => 'Beosound Balance', 'firmware' => '2.1.0', 'mac_address' => null, 'renamable' => true], $info);
    }

    public function test_rename_puts_the_friendly_name(): void
    {
        $transport = new FakeMozartTransport;

        $this->driver($transport)->setDeviceName('Kitchen');

        $this->assertSame(['PUT /api/v1/product/info/friendlyname'], $transport->requests);
        $this->assertSame([['friendlyName' => 'Kitchen']], $transport->bodies);
    }
}
