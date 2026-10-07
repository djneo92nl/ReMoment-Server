<?php

namespace Tests\Unit;

use App\Integrations\BangOlufsen\Ase\Connectors\MultiRoomControls;
use App\Integrations\Common\HttpConnector;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAseConnector;
use Tests\Support\FakeAseHostDriver;
use Tests\TestCase;

class AseMultiRoomJoinTest extends TestCase
{
    use RefreshDatabase;

    public function test_joining_asks_the_host_to_add_this_device_as_listener(): void
    {
        $guestApi = new FakeAseConnector;
        $hostApi = new FakeAseConnector;
        FakeAseHostDriver::$api = $hostApi;

        $guest = new class($guestApi)
        {
            use MultiRoomControls;

            public function __construct(private HttpConnector $api) {}

            public function getMultiRoomId(): ?string
            {
                return 'guest-jid';
            }

            protected function deviceApiClient(): HttpConnector
            {
                return $this->api;
            }
        };

        $guest->joinSession(new Device(['device_driver' => FakeAseHostDriver::class]));

        $this->assertSame(['POST BeoZone/Zone/ActiveSources/primaryExperience'], $hostApi->requests);
        $this->assertSame([['listener' => ['jid' => 'guest-jid']]], $hostApi->bodies);
        $this->assertSame([], $guestApi->requests);
    }

    public function test_an_idle_device_derives_its_jid_from_its_product_id(): void
    {
        $api = (new FakeAseConnector)
            ->seed('BeoZone/Zone/ActiveSources', ['activeSources' => ['primary' => '', 'primaryJid' => '']])
            ->seed('BeoDevice', ['beoDevice' => ['productId' => ['typeNumber' => '2730', 'itemNumber' => '1200316', 'serialNumber' => '28922783']]]);

        $device = new class($api)
        {
            use MultiRoomControls;

            public Device $device;

            public function __construct(private HttpConnector $api)
            {
                $this->device = Device::create(['device_name' => 'M3', 'device_brand_name' => 'Bang & Olufsen', 'ip_address' => '10.0.0.3']);
            }

            protected function getActiveSources(): array
            {
                return $this->api->get('BeoZone/Zone/ActiveSources');
            }

            protected function deviceApiClient(): HttpConnector
            {
                return $this->api;
            }
        };

        $this->assertSame('2730.1200316.28922783@products.bang-olufsen.com', $device->getMultiRoomId());
    }
}
