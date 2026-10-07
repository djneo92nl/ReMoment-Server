<?php

namespace Tests\Feature;

use App\Domain\Device\DiscoveredDevice;
use App\Domain\Device\DiscoveredDevicePersister;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscoveredDevicePersisterTest extends TestCase
{
    use RefreshDatabase;

    private function found(string $ip, string $brand, array $meta = [], string $name = 'Kitchen'): DiscoveredDevice
    {
        return new DiscoveredDevice($ip, $name, $brand, 'Model', 'App\Fake\Driver', 'Fake', $meta);
    }

    public function test_a_known_identifier_updates_the_device_even_when_it_moved(): void
    {
        $persister = app(DiscoveredDevicePersister::class);

        $first = $persister->persist($this->found('192.168.1.10', 'Sonos', ['sonos_uuid' => 'RINCON_A']));
        $again = $persister->persist($this->found('192.168.1.99', 'Sonos', ['sonos_uuid' => 'RINCON_A'], 'Kitchen 2'));

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Device::count());
        $this->assertSame('192.168.1.99', $again->fresh()->ip_address);
        $this->assertSame('RINCON_A', $again->meta()->where('key', 'sonos_uuid')->value('value'));
    }

    public function test_a_reused_ip_never_takes_over_another_brand(): void
    {
        $persister = app(DiscoveredDevicePersister::class);

        $persister->persist($this->found('192.168.1.10', 'Sonos', ['sonos_uuid' => 'RINCON_A']));
        $persister->persist($this->found('192.168.1.10', 'Bang & Olufsen', ['upnp_uuid' => 'uuid:1']));

        $this->assertSame(2, Device::count());
    }
}
