<?php

namespace Tests\Unit\Discovery;

use App\Integrations\Sonos\Services\SonosTopology;
use App\Integrations\Sonos\SonosDiscovery;
use App\Services\Discovery\SsdpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SonosDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function soap(): string
    {
        $state = '<ZoneGroups><ZoneGroup Coordinator="RINCON_A01400" ID="RINCON_A01400:1">'
            .'<ZoneGroupMember UUID="RINCON_A01400" Location="http://192.168.1.29:1400/xml/device_description.xml" ZoneName="Roam"/>'
            .'<ZoneGroupMember UUID="RINCON_B01400" Location="http://192.168.1.30:1400/xml/device_description.xml" ZoneName="Kitchen"/>'
            .'<ZoneGroupMember UUID="RINCON_C01400" Location="http://192.168.1.31:1400/xml/device_description.xml" ZoneName="Kitchen" Invisible="1"/>'
            .'<ZoneGroupMember UUID="RINCON_D01400" Location="http://192.168.1.32:1400/xml/device_description.xml" ZoneName="Boost" IsZoneBridge="1"/>'
            .'</ZoneGroup></ZoneGroups>';

        return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><u:GetZoneGroupStateResponse xmlns:u="urn:schemas-upnp-org:service:ZoneGroupTopology:1">'
            .'<ZoneGroupState>'.htmlspecialchars($state).'</ZoneGroupState></u:GetZoneGroupStateResponse></s:Body></s:Envelope>';
    }

    private function description(string $room, string $model): string
    {
        return "<root><device><roomName>{$room}</roomName><friendlyName>{$room} - Sonos</friendlyName><modelName>{$model}</modelName><UDN>uuid:X</UDN></device></root>";
    }

    public function test_parse_members_skips_secondary_and_bridge(): void
    {
        $members = SonosTopology::parseMembers($this->soap());

        $this->assertSame(['RINCON_A01400', 'RINCON_B01400'], array_column($members, 'uuid'));
        $this->assertSame('192.168.1.29', $members[0]['ip']);
    }

    public function test_one_given_host_finds_the_household_without_multicast(): void
    {
        Http::fake([
            '192.168.1.29:1400/ZoneGroupTopology/Control' => Http::response($this->soap()),
            '192.168.1.29:1400/xml/*' => Http::response($this->description('Roam', 'Sonos Roam')),
            '192.168.1.30:1400/xml/*' => Http::response($this->description('Kitchen', 'Sonos One')),
        ]);
        $ssdp = $this->createMock(SsdpClient::class);
        $ssdp->method('search')->willReturn([]);

        $discovery = (new SonosDiscovery($ssdp, new SonosTopology))->withHosts(['192.168.1.29']);
        $found = $discovery->discover();

        $this->assertCount(2, $found);
        $this->assertSame('Sonos Roam', $found[0]->device_product_type);
        $this->assertSame(['sonos_uuid' => 'RINCON_B01400'], $found[1]->meta);
        $this->assertStringContainsString('No multicast', $discovery->diagnostics()[0]);
    }

    public function test_unreachable_seed_is_reported(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $ssdp = $this->createMock(SsdpClient::class);
        $ssdp->method('search')->willReturn([]);

        $discovery = (new SonosDiscovery($ssdp, new SonosTopology))->withHosts(['192.168.1.99']);

        $this->assertSame([], $discovery->discover());
        $this->assertStringContainsString('192.168.1.99 (given) did not answer', implode(' ', $discovery->diagnostics()));
    }

    public function test_non_sonos_ssdp_replies_are_not_probed(): void
    {
        Http::fake();
        $ssdp = $this->createMock(SsdpClient::class);
        $ssdp->method('search')->willReturn([
            ['ip' => '192.168.1.236', 'location' => 'http://192.168.1.236:80/description.xml', 'st' => 'upnp:rootdevice', 'usn' => 'uuid:2f402f80-da50-11e1-9b23-001788000000::upnp:rootdevice'],
        ]);

        $discovery = new SonosDiscovery($ssdp, new SonosTopology);

        $this->assertSame([], $discovery->discover());
        Http::assertNothingSent();
    }

    public function test_ssdp_response_parsing(): void
    {
        $r = SsdpClient::parseResponse("HTTP/1.1 200 OK\r\nLOCATION: http://10.0.0.5:1400/xml/device_description.xml\r\nST: urn:x\r\nUSN: uuid:1\r\n\r\n", '10.0.0.5');

        $this->assertSame('http://10.0.0.5:1400/xml/device_description.xml', $r['location']);
        $this->assertNull(SsdpClient::parseResponse("NOTIFY * HTTP/1.1\r\n\r\n", '10.0.0.5'));
    }
}
