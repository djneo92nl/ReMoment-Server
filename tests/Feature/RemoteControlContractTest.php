<?php

namespace Tests\Feature;

use App\Domain\Device\Capabilities;
use App\Domain\Device\RemoteKey;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\RemoteControlInterface;
use App\Models\Device;
use Tests\TestCase;

class RemoteControlContractTest extends TestCase
{
    public function test_a_driver_with_a_remote_reports_the_remote_control_capability(): void
    {
        $driver = new class(new Device) implements MediaControlsInterface, MusicPlayerDriverInterface, RemoteControlInterface
        {
            public function __construct(public Device $device) {}

            public function supportedKeys(): array
            {
                return [RemoteKey::Ok, RemoteKey::Back];
            }

            public function sendKey(RemoteKey $key): void {}

            public function play(): void {}

            public function pause(): void {}

            public function next(): void {}

            public function previous(): void {}

            public function stop(): void {}
        };

        $this->assertContains('remote_control', Capabilities::forDriver($driver::class));
        $this->assertSame(RemoteControlInterface::class, Capabilities::contractFor('remote_control'));
    }

    public function test_the_key_names_are_unique_snake_case(): void
    {
        $values = array_map(fn (RemoteKey $key) => $key->value, RemoteKey::cases());

        $this->assertSame($values, array_values(array_unique($values)));
        foreach ($values as $value) {
            $this->assertMatchesRegularExpression('/^[a-z]+(_[a-z]+)*$/', $value);
        }
    }
}
