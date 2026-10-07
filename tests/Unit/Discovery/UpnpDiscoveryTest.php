<?php

namespace Tests\Unit\Discovery;

use App\Integrations\BangOlufsen\Ase\AseDiscovery;
use App\Services\Discovery\SsdpClient;
use Mockery;
use Remoment\MozartDriver\MozartDiscovery;
use Tests\TestCase;

class UpnpDiscoveryTest extends TestCase
{
    private function ssdp(): SsdpClient
    {
        $descriptors = [
            'http://10.0.0.1/d.xml' => ['device' => ['manufacturer' => 'Bang & Olufsen', 'modelName' => 'Beoplay M5', 'friendlyName' => 'Kitchen', 'UDN' => 'uuid:ase']],
            'http://10.0.0.2/d.xml' => ['device' => ['manufacturer' => 'Bang & Olufsen', 'modelName' => 'Beosound Theatre', 'friendlyName' => 'TV room', 'UDN' => 'uuid:mozart']],
            'http://10.0.0.3/d.xml' => ['device' => ['manufacturer' => 'Acme', 'modelName' => 'Toaster']],
        ];

        $ssdp = Mockery::mock(SsdpClient::class);
        $ssdp->shouldReceive('search')->andReturn(array_map(
            fn ($location, $i) => ['ip' => '10.0.0.'.($i + 1), 'location' => $location, 'st' => null, 'usn' => null],
            array_keys($descriptors),
            array_keys(array_keys($descriptors)),
        ));
        $ssdp->shouldReceive('describe')->andReturnUsing(fn ($location) => $descriptors[$location]);

        return $ssdp;
    }

    public function test_each_discoverer_reports_only_its_own_platforms_models(): void
    {
        $ase = (new AseDiscovery($this->ssdp()))->discover();
        $mozart = (new MozartDiscovery($this->ssdp()))->discover();

        $this->assertSame(['Beoplay M5'], array_map(fn ($d) => $d->device_product_type, $ase));
        $this->assertSame('ASE', $ase[0]->device_driver_name);
        $this->assertSame(['upnp_uuid' => 'uuid:ase'], $ase[0]->meta);

        $this->assertSame(['Beosound Theatre'], array_map(fn ($d) => $d->device_product_type, $mozart));
        $this->assertSame('Mozart', $mozart[0]->device_driver_name);
    }

    public function test_the_container_can_build_every_registered_discoverer(): void
    {
        foreach (config('devices.discoverers') as $class) {
            $this->assertInstanceOf($class, app()->make($class));
        }
    }
}
