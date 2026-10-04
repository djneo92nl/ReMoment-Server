<?php

namespace Tests\Unit;

use App\Integrations\BangOlufsen\Ase\Connectors\NetworkControls;
use App\Integrations\BangOlufsen\Ase\Connectors\WirelessSpeakerControls;
use App\Integrations\Common\HttpConnector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeAseConnector;
use Tests\TestCase;

/** Shapes below are what an M3 (on Wi-Fi) and an Essence (wired) answer. */
class AseNetworkConnectorsTest extends TestCase
{
    private function driver(FakeAseConnector $api): object
    {
        return new class($api)
        {
            use NetworkControls;
            use WirelessSpeakerControls;

            public function __construct(private HttpConnector $api) {}

            protected function deviceApiClient(): HttpConnector
            {
                return $this->api;
            }
        };
    }

    private function wirelessNetworks(): array
    {
        return ['networks' => [[
            'ssid' => 'IDiot', 'configured' => true, 'created' => 'automatic', 'frequency' => '2.4ghz', 'encryption' => 'wpa2PskTkip',
            'passphrase' => 'supersecret', 'dhcp' => true,
            'ipv4' => ['address' => '192.168.1.103'],
        ]], '_links' => ['/relation/create' => ['href' => './']]];
    }

    private function settings(string $active = 'wireless', ?array $networks = null): FakeAseConnector
    {
        return (new FakeAseConnector)
            ->seed('BeoDevice/networkSettings', ['profile' => ['name' => 'networkSettingsProfile', 'networkSettings' => [
                'activeInterface' => $active,
                'internetReachable' => true,
                'interfaces' => [
                    'wired' => ['status' => $active === 'wired' ? 'connected' : 'inactive', 'dhcp' => true, 'ipv4' => ['address' => $active === 'wired' ? '192.168.1.25' : '', 'subnetMask' => '', 'defaultGateway' => '', 'preferredDns' => '', 'alternativeDns' => '']],
                    'wireless' => ['status' => $active === 'wireless' ? 'connected' : 'inactive', 'activeNetwork' => $active === 'wireless' ? [
                        'ssid' => 'IDiot', 'frequency' => '5ghz', 'quality' => 'excellent', 'rssi' => -55, 'encryption' => 'wpa2PskTkip', 'passphrase' => 'supersecret', 'dhcp' => true,
                        'ipv4' => ['address' => '192.168.1.103', 'subnetMask' => '255.255.255.0', 'defaultGateway' => '192.168.1.1', 'preferredDns' => '192.168.1.28', 'alternativeDns' => ''],
                    ] : []],
                ],
                '_capabilities' => ['editable' => ['activeInterface'], 'value' => ['activeInterface' => ['none', 'wired', 'wireless']]],
            ]]])
            ->seed('BeoDevice/networkSettings/wireless/networks', $networks ?? $this->wirelessNetworks());
    }

    public function test_reads_wifi_and_never_exposes_a_passphrase(): void
    {
        $result = $this->driver($this->settings())->getNetwork()->toArray();

        $this->assertSame('wireless', $result['active_interface']);
        $this->assertSame(['wired', 'wireless'], $result['interfaces'], '`none` would cut the device off and is not offered');
        $this->assertTrue($result['internet_reachable']);
        $this->assertSame('IDiot', $result['wireless']['ssid']);
        $this->assertSame('5ghz', $result['wireless']['frequency']);
        $this->assertSame(-55, $result['wireless']['signal']);
        $this->assertSame('192.168.1.103', $result['wireless']['address']);
        $this->assertSame([['ssid' => 'IDiot', 'frequency' => '2.4ghz', 'security' => 'wpa2PskTkip', 'configured' => true, 'active' => true]], $result['wifi_networks']);
        $this->assertStringNotContainsString('supersecret', json_encode($result));
    }

    public function test_reads_a_wired_connection_and_blanks_empty_values(): void
    {
        $result = $this->driver($this->settings('wired'))->getNetwork()->toArray();

        $this->assertSame('192.168.1.25', $result['wired']['address']);
        $this->assertTrue($result['wired']['dhcp']);
        $this->assertNull($result['wired']['gateway']);
        $this->assertSame('inactive', $result['wireless']['status']);
        $this->assertNull($result['wireless']['ssid']);
    }

    public function test_a_failing_read_is_an_error_not_an_empty_network(): void
    {
        $api = (new FakeAseConnector)->seed('BeoDevice/networkSettings', ['error' => ['message' => 'busy']], 500);

        $this->expectExceptionMessage('HTTP 500');

        $this->driver($api)->getNetwork();
    }

    public function test_switches_interface_with_the_body_the_device_accepts(): void
    {
        $api = $this->settings('wired');

        $this->driver($api)->setActiveInterface('wireless');

        $this->assertSame([['profile' => ['networkSettings' => ['activeInterface' => 'wireless']]]], $api->bodies);
        $this->assertContains('PUT BeoDevice/networkSettings', $api->requests);
    }

    public function test_refuses_none_and_unknown_interfaces(): void
    {
        $api = $this->settings('wired');

        foreach (['none', 'bluetooth'] as $interface) {
            try {
                $this->driver($api)->setActiveInterface($interface);
                $this->fail("expected '{$interface}' to be refused");
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame([], $api->bodies);
    }

    public function test_refuses_wireless_when_no_wifi_network_is_set_up(): void
    {
        $api = $this->settings('wired', ['networks' => []]);

        $this->expectExceptionMessage('Join a network first');

        $this->driver($api)->setActiveInterface('wireless');
    }

    public function test_dhcp_sends_only_the_dhcp_flag(): void
    {
        $api = $this->settings('wired');

        $this->driver($api)->setWiredAddress(true);

        $this->assertSame([['wired' => ['dhcp' => true]]], $api->bodies);
        $this->assertContains('PUT BeoDevice/networkSettings/wired', $api->requests);
    }

    public function test_a_static_address_is_sent_in_the_devices_own_names(): void
    {
        $api = $this->settings('wired');

        $this->driver($api)->setWiredAddress(false, ['address' => '192.168.1.50', 'subnet_mask' => '255.255.255.0', 'gateway' => '192.168.1.1', 'preferred_dns' => '192.168.1.28', 'alternative_dns' => '']);

        $this->assertSame([['wired' => ['dhcp' => false, 'ipv4' => [
            'address' => '192.168.1.50', 'subnetMask' => '255.255.255.0', 'defaultGateway' => '192.168.1.1', 'preferredDns' => '192.168.1.28', 'alternativeDns' => '',
        ]]]], $api->bodies);
    }

    #[DataProvider('badStaticSetups')]
    public function test_an_unusable_static_setup_is_refused_before_anything_is_sent(array $ipv4, string $message): void
    {
        $api = $this->settings('wired');

        try {
            $this->driver($api)->setWiredAddress(false, $ipv4);
            $this->fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertSame([], $api->bodies);
    }

    public static function badStaticSetups(): array
    {
        $ok = ['address' => '192.168.1.50', 'subnet_mask' => '255.255.255.0', 'gateway' => '192.168.1.1'];

        return [
            'missing gateway' => [['address' => '192.168.1.50', 'subnet_mask' => '255.255.255.0'], 'needs gateway'],
            'not an address' => [['address' => '192.168.1', ...array_slice($ok, 1)], 'not a valid IPv4'],
            'ipv6' => [['address' => '::1', ...array_slice($ok, 1)], 'not a valid IPv4'],
            'gap in the mask' => [['subnet_mask' => '255.0.255.0'] + $ok, 'not a valid subnet mask'],
            'mask of zeros' => [['subnet_mask' => '0.0.0.0'] + $ok, 'not a valid subnet mask'],
            'gateway elsewhere' => [['gateway' => '10.0.0.1'] + $ok, 'same subnet'],
            'bad dns' => [$ok + ['preferred_dns' => 'dns.local'], 'not a valid IPv4'],
        ];
    }

    public function test_joins_a_wifi_network_through_the_active_network(): void
    {
        $api = $this->settings('wired');

        $this->driver($api)->joinWifi('Guest WiFi', 'correct horse', 'wpa2PskTkip');

        $this->assertSame([['wireless' => ['activeNetwork' => [
            'ssid' => 'Guest WiFi', 'passphrase' => 'correct horse', 'encryption' => 'wpa2PskTkip', 'dhcp' => true, 'configured' => true,
        ]]]], $api->bodies);
        $this->assertContains('PUT BeoDevice/networkSettings/wireless', $api->requests);
    }

    public function test_an_open_network_needs_no_passphrase(): void
    {
        $api = $this->settings('wired');

        $this->driver($api)->joinWifi('Cafe');

        $this->assertSame(['ssid' => 'Cafe', 'dhcp' => true, 'configured' => true], $api->bodies[0]['wireless']['activeNetwork']);
    }

    #[DataProvider('badWifi')]
    public function test_a_bad_wifi_request_is_refused_before_anything_is_sent(string $ssid, ?string $passphrase, ?string $security): void
    {
        $api = $this->settings('wired');

        try {
            $this->driver($api)->joinWifi($ssid, $passphrase, $security);
            $this->fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame([], $api->bodies);
    }

    public static function badWifi(): array
    {
        return [
            'empty name' => ['', 'longenough', null],
            'name too long' => [str_repeat('x', 33), 'longenough', null],
            'short passphrase' => ['Home', 'short', null],
            'long passphrase' => ['Home', str_repeat('x', 64), null],
            'security with symbols' => ['Home', 'longenough', 'wpa2; drop'],
        ];
    }

    public function test_network_writes_are_not_read_back_because_the_device_may_move(): void
    {
        $api = $this->settings('wired');

        $this->driver($api)->setActiveInterface('wireless');
        $this->driver($api)->joinWifi('Guest', 'longenough');

        $writes = array_values(array_filter($api->requests, fn ($r) => str_starts_with($r, 'PUT')));
        $this->assertCount(2, $writes);
        $this->assertSame('PUT BeoDevice/networkSettings/wireless', end($api->requests), 'nothing is read after the last write');
    }

    public function test_reads_the_wireless_speaker_setup(): void
    {
        $api = (new FakeAseConnector)->seed('BeoZone/Zone/Sound/SpeakerWirelessSetup', ['speakerWirelessSetup' => [
            'scan' => 'notInitiated', 'standbyMode' => 'disconnect', 'speaker' => [],
            '_capabilities' => ['editable' => ['scan'], 'value' => ['scan' => ['start', 'stop']]],
        ]]);

        $this->assertSame(['scan' => 'notInitiated', 'scanning' => false, 'speakers' => []], $this->driver($api)->getWirelessSpeakers()->toArray());
    }

    public function test_a_scan_state_it_does_not_know_counts_as_scanning(): void
    {
        $api = (new FakeAseConnector)->seed('BeoZone/Zone/Sound/SpeakerWirelessSetup', ['speakerWirelessSetup' => [
            'scan' => 'inProgress',
            'speaker' => [['id' => 'spk-1', 'friendlyName' => 'Front Left', 'status' => 'found'], ['address' => 'AA:BB', 'name' => 'Rear']],
        ]]);

        $state = $this->driver($api)->getWirelessSpeakers()->toArray();

        $this->assertTrue($state['scanning']);
        $this->assertSame([['id' => 'spk-1', 'name' => 'Front Left', 'state' => 'found'], ['id' => 'AA:BB', 'name' => 'Rear', 'state' => null]], $state['speakers']);
    }

    public function test_starts_and_stops_a_scan_the_way_the_collection_documents_it(): void
    {
        $api = (new FakeAseConnector)->seed('BeoZone/Zone/Sound/SpeakerWirelessSetup', ['speakerWirelessSetup' => ['scan' => 'notInitiated']]);

        $this->driver($api)->startWirelessScan();
        $this->driver($api)->stopWirelessScan();

        $this->assertSame([['speakerWirelessSetup' => ['scan' => 'start']], ['speakerWirelessSetup' => ['scan' => 'stop']]], $api->bodies);
    }
}
