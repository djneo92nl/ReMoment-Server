<?php

namespace Tests\Unit\Mozart;

use App\Models\Device;
use Djneo92nl\BeoMozart\MozartClient;
use Remoment\MozartDriver\MusicPlayerDriver;
use Tests\Support\FakeMozartTransport;
use Tests\TestCase;

class MozartSessionHostTest extends TestCase
{
    public function test_adding_a_listener_expands_the_experience_to_the_peer(): void
    {
        $transport = new FakeMozartTransport;
        $driver = new MusicPlayerDriver(new Device(['device_name' => 'Beolab 28', 'ip_address' => '10.0.0.5']));

        $driver->client = new MozartClient('mozart.test', transport: $transport);

        $driver->addListener('2730.1200316.28922783@products.bang-olufsen.com');

        $this->assertSame(['POST /api/v1/beolink/expand/2730.1200316.28922783@products.bang-olufsen.com'], $transport->requests);
    }
}
