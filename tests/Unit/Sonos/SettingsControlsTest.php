<?php

namespace Tests\Unit\Sonos;

use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Sonos\Connectors\SettingsControls;
use App\Models\Device;
use Tests\TestCase;

class SettingsControlsTest extends TestCase
{
    /** Stands in for duncan3dc's Controller: the same method names, in memory. */
    private function speaker(array $zone = ['DisplaySoftwareVersion' => '16.1', 'MACAddress' => '5C:AA:FD:00:00:01']): object
    {
        return new class($zone)
        {
            public int $bass = 2;

            public int $treble = -3;

            public bool $loudness = true;

            public function __construct(private array $zone) {}

            public function getBass(): int
            {
                return $this->bass;
            }

            public function setBass(int $bass): static
            {
                $this->bass = $bass;

                return $this;
            }

            public function getTreble(): int
            {
                return $this->treble;
            }

            public function setTreble(int $treble): static
            {
                $this->treble = $treble;

                return $this;
            }

            public function getLoudness(): bool
            {
                return $this->loudness;
            }

            public function setLoudness(bool $on): static
            {
                $this->loudness = $on;

                return $this;
            }

            public function getRoom(): string
            {
                return 'Living Room';
            }

            public function soap(string $service, string $action): object
            {
                if ($this->zone === []) {
                    throw new \RuntimeException('no answer');
                }

                return new class($this->zone)
                {
                    public function __construct(private array $zone) {}

                    public function getArray(): array
                    {
                        return $this->zone;
                    }
                };
            }
        };
    }

    private function driver(object $speaker): object
    {
        $device = new Device(['device_name' => 'Sonos', 'device_product_type' => 'Sonos One']);

        return new class($speaker, $device)
        {
            use SettingsControls;

            public function __construct(private object $speaker, public Device $device) {}

            public function deviceApiClient(): object
            {
                return $this->speaker;
            }
        };
    }

    public function test_reads_the_equalizer_with_sonos_fixed_range(): void
    {
        $result = $this->driver($this->speaker())->getSoundAdjustment()->toArray();

        $this->assertSame(['value' => 2, 'min' => -10, 'max' => 10, 'step' => 1], $result['bass']);
        $this->assertSame(['value' => -3, 'min' => -10, 'max' => 10, 'step' => 1], $result['treble']);
        $this->assertTrue($result['loudness']);
    }

    public function test_changes_only_what_is_given(): void
    {
        $speaker = $this->speaker();

        $this->driver($speaker)->setSoundAdjustment(treble: 5, loudness: false);

        $this->assertSame([2, 5, false], [$speaker->bass, $speaker->treble, $speaker->loudness]);
    }

    public function test_a_value_outside_the_range_changes_nothing(): void
    {
        $speaker = $this->speaker();

        try {
            $this->driver($speaker)->setSoundAdjustment(bass: 3, treble: 11);
            $this->fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame([2, -3], [$speaker->bass, $speaker->treble]);
    }

    public function test_device_info_has_the_room_name_firmware_and_mac_but_cannot_be_renamed(): void
    {
        $driver = $this->driver($this->speaker());

        $this->assertSame(
            ['name' => 'Living Room', 'product_type' => 'Sonos One', 'firmware' => '16.1', 'mac_address' => '5C:AA:FD:00:00:01', 'renamable' => false],
            $driver->getDeviceInfo()->toArray()
        );

        $this->expectException(UnsupportedOperationException::class);
        $driver->setDeviceName('Kitchen');
    }

    public function test_a_speaker_that_will_not_tell_its_firmware_is_not_an_error(): void
    {
        $info = $this->driver($this->speaker([]))->getDeviceInfo()->toArray();

        $this->assertSame('Living Room', $info['name']);
        $this->assertNull($info['firmware']);
        $this->assertNull($info['mac_address']);
    }
}
