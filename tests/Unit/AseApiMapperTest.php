<?php

namespace Tests\Unit;

use App\Integrations\BangOlufsen\Ase\Services\ApiMapper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AseApiMapperTest extends TestCase
{
    /** @param  array<string, array|int>  $routes  path (no leading slash) => body, or a status code */
    private function fakeDevice(array $routes): void
    {
        Http::fake(function (Request $request) use ($routes) {
            $path = ltrim(parse_url($request->url(), PHP_URL_PATH) ?? '', '/');
            $route = $routes[$path] ?? 404;

            return is_int($route)
                ? Http::response(['error' => ['type' => 'x']], $route)
                : Http::response($route);
        });
    }

    private function map(array $routes, array $seeds = []): array
    {
        $this->fakeDevice($routes);

        return (new ApiMapper('10.0.0.5', 8080, 600, $seeds))->map();
    }

    public function test_follows_relative_links_from_the_service_list(): void
    {
        $map = $this->map([
            '' => ['services' => [['name' => 'BeoDevice', 'path' => 'BeoDevice/']]],
            'BeoDevice/' => ['beoDevice' => ['productId' => ['productType' => 'TEST_SPEAKER'], 'productFriendlyName' => ['productFriendlyName' => 'Kitchen'], 'software' => ['version' => '1.2']]],
            'BeoDevice' => ['beoDevice' => ['productId' => ['productType' => 'TEST_SPEAKER'], 'productFriendlyName' => ['productFriendlyName' => 'Kitchen'], 'software' => ['version' => '1.2']]],
        ]);

        $this->assertArrayHasKey('BeoDevice', $map['endpoints']);
        $this->assertSame('TEST_SPEAKER', $map['device']['product_type']);
        $this->assertSame('Kitchen', $map['device']['name']);
        $this->assertSame('1.2', $map['device']['software']);
    }

    public function test_resolves_hrefs_relative_to_the_resource_and_records_editable_fields(): void
    {
        $map = $this->map([
            '' => ['services' => [['name' => 'BeoDevice', 'path' => 'BeoDevice/']]],
            'BeoDevice/' => ['profile' => ['bluetooth' => ['_links' => ['self' => ['href' => './bluetoothSettings/']]]]],
            'BeoDevice/bluetoothSettings/' => [
                'bluetooth' => [
                    'deviceSettings' => [
                        'discoverabilityMode' => 'notDiscoverable',
                        '_capabilities' => [
                            'editable' => ['discoverabilityMode'],
                            'value' => ['discoverabilityMode' => ['notDiscoverable', 'discoverable']],
                        ],
                        '_links' => ['/relation/modify' => ['href' => './deviceSettings']],
                    ],
                ],
            ],
            'BeoDevice/bluetoothSettings/deviceSettings' => ['deviceSettings' => ['bluetoothOn' => true]],
        ]);

        $node = $map['endpoints']['BeoDevice/bluetoothSettings'];
        $this->assertSame(['bluetooth.deviceSettings.discoverabilityMode'], $node['editable']);
        $this->assertSame(['notDiscoverable', 'discoverable'], $node['values']['bluetooth.deviceSettings.discoverabilityMode']);
        $this->assertSame('./deviceSettings', $node['relations']['bluetooth.deviceSettings./relation/modify']);
        $this->assertArrayHasKey('BeoDevice/bluetoothSettings/deviceSettings', $map['endpoints']);
    }

    public function test_probes_children_named_after_zone_features(): void
    {
        $map = $this->map([
            '' => ['services' => []],
            'BeoZone/Zone/Sound' => ['features' => ['SPEAKER_WIRELESS_SETUP', 'SOUND_ADJUSTMENT', 'VOLUME']],
            'BeoZone/Zone/Sound/SpeakerWirelessSetup' => ['speakerWirelessSetup' => ['scan' => 'notInitiated']],
            'BeoZone/Zone/Sound/Adjustment' => ['adjustment' => ['bass' => 1]],
            'BeoZone/Zone/Sound/Volume' => ['volume' => ['speaker' => ['level' => 28]]],
        ]);

        $this->assertSame(['SPEAKER_WIRELESS_SETUP', 'SOUND_ADJUSTMENT', 'VOLUME'], $map['endpoints']['BeoZone/Zone/Sound']['features']);
        $this->assertArrayHasKey('BeoZone/Zone/Sound/SpeakerWirelessSetup', $map['endpoints']);
        $this->assertArrayHasKey('BeoZone/Zone/Sound/Adjustment', $map['endpoints']);
        $this->assertArrayHasKey('BeoZone/Zone/Sound/Volume', $map['endpoints']);
    }

    public function test_leaves_not_found_guesses_out_of_the_map(): void
    {
        $map = $this->map([
            '' => ['services' => []],
            'BeoZone/Zone/Sound' => ['features' => ['NOT_A_REAL_THING']],
        ]);

        $this->assertSame(['BeoZone/Zone/Sound'], array_keys($map['endpoints']));
    }

    public function test_records_write_only_endpoints_with_their_status(): void
    {
        $map = $this->map([
            '' => ['services' => [['path' => 'BeoDevice/']]],
            'BeoDevice/' => ['_links' => ['/relation/modify' => ['href' => './factoryReset']]],
            'BeoDevice/factoryReset' => 405,
        ]);

        $this->assertSame(405, $map['endpoints']['BeoDevice/factoryReset']['status']);
    }

    public function test_limits_crawling_of_list_members_sharing_a_template(): void
    {
        $favorites = [];
        $routes = ['' => ['services' => [['path' => 'BeoContent/']]]];
        foreach ([1111111, 2222222, 3333333, 4444444, 5555555] as $id) {
            $favorites[] = ['_links' => ['self' => ['href' => "./fav/{$id}"]]];
            $routes["BeoContent/fav/{$id}"] = ['id' => $id];
        }
        $routes['BeoContent/'] = ['favorites' => $favorites];

        $map = $this->map($routes);

        $members = array_filter(array_keys($map['endpoints']), fn ($p) => str_starts_with($p, 'BeoContent/fav/'));
        $this->assertCount(3, $members);
        $this->assertSame(2, $map['skipped']);
    }

    public function test_never_stores_credentials_content_or_secret_looking_keys(): void
    {
        $map = $this->map([
            '' => ['services' => [['path' => 'BeoDevice/']]],
            'BeoDevice/' => ['_links' => ['self' => ['href' => './credentials/']], 'wifi' => ['ssid' => 'home', 'password' => 'hunter2', 'nested' => ['accessToken' => 'abc']]],
            'BeoDevice/credentials/' => ['credential' => [['service' => 'spotify', 'accessToken' => 'abc']]],
        ]);

        $this->assertNull($map['endpoints']['BeoDevice/credentials']['body']);
        $this->assertTrue($map['endpoints']['BeoDevice/credentials']['redacted']);
        $this->assertSame('[redacted]', $map['endpoints']['BeoDevice']['body']['wifi']['password']);
        $this->assertSame('[redacted]', $map['endpoints']['BeoDevice']['body']['wifi']['nested']['accessToken']);
        $this->assertSame('home', $map['endpoints']['BeoDevice']['body']['wifi']['ssid']);
        $this->assertStringNotContainsString('hunter2', json_encode($map));
    }

    public function test_only_ever_issues_get_requests_and_skips_the_notification_stream(): void
    {
        $this->map([
            '' => ['services' => [['path' => 'BeoNotify/'], ['path' => 'BeoDevice/']]],
            'BeoDevice/' => ['x' => 1],
            'BeoNotify/' => ['x' => 1],
        ]);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'BeoNotify'));
    }

    public function test_throws_when_the_device_does_not_answer_at_all(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->expectException(ConnectionException::class);

        (new ApiMapper('10.0.0.5'))->map();
    }

    public function test_short_numeric_ids_of_catalogue_items_collapse_too(): void
    {
        $this->assertSame('BeoContent/music/deezerProfile/album/{id}/track', ApiMapper::template('BeoContent/music/deezerProfile/album/40888/track'));
        $this->assertSame('BeoContent/music/deezerProfile/genre/{id}/albumTop', ApiMapper::template('BeoContent/music/deezerProfile/genre/106/albumTop'));
        $this->assertSame('BeoZone/Zone/Sound/Mode/{id}', ApiMapper::template('BeoZone/Zone/Sound/Mode/1'));
        $this->assertSame('BeoContent/music/moodWheelProfile/moodWheelItem/{id}/track', ApiMapper::template('BeoContent/music/moodWheelProfile/moodWheelItem/0-65338/track'));
        $this->assertSame('BeoZone/Zone/Sound/Adjustment', ApiMapper::template('BeoZone/Zone/Sound/Adjustment'));
    }

    public function test_compare_ignores_ids_of_list_members(): void
    {
        $a = ['endpoints' => ['BeoDevice' => [], 'BeoContent/fav/1111111' => [], 'BeoZone/Zone/Tv' => []]];
        $b = ['endpoints' => ['BeoDevice' => [], 'BeoContent/fav/9999999' => [], 'BeoDevice/bluetoothSettings' => []]];

        $diff = ApiMapper::compare($a, $b);

        $this->assertSame(['BeoZone/Zone/Tv'], $diff['only_a']);
        $this->assertSame(['BeoDevice/bluetoothSettings'], $diff['only_b']);
    }
}
