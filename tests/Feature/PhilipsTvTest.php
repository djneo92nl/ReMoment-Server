<?php

namespace Tests\Feature;

use App\Domain\Device\DedicatedDrivers;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Integrations\Philips\JointspaceClient;
use App\Integrations\Philips\Pairing;
use App\Integrations\Philips\Services\DeviceListener;
use App\Integrations\Philips\VideoPlayerDriver;
use App\Models\Device;
use App\Models\DeviceMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A Philips Android TV: the Jointspace layer on top of the UPnP driver, against a faked TV. */
class PhilipsTvTest extends TestCase
{
    use RefreshDatabase;

    private const NETFLIX = ['action' => 'android.intent.action.MAIN', 'component' => ['className' => 'com.netflix.Main', 'packageName' => 'com.netflix.ninja']];

    private function tv(bool $paired = true): Device
    {
        $device = Device::factory()->driver(VideoPlayerDriver::class, 'Philips')->create(['ip_address' => '10.0.0.70', 'device_brand_name' => 'Philips', 'device_product_type' => 'Philips TV DMR']);

        $meta = ['upnp_avtransport_url' => 'http://10.0.0.70/av', 'upnp_capabilities' => '["seek"]'] + ($paired ? ['philips_user' => 'dev1234567890abc', 'philips_password' => 'secretkey'] : []);
        foreach ($meta as $key => $value) {
            DeviceMeta::create(['device_id' => $device->id, 'key' => $key, 'value' => $value]);
        }

        return $device;
    }

    public function test_the_signature_is_the_base64_of_the_hex_hmac_of_timestamp_and_pin(): void
    {
        $signature = Pairing::signature(1700000000, '1234');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', base64_decode($signature));
        $this->assertNotSame($signature, Pairing::signature(1700000000, '1235'));
        $this->assertSame($signature, Pairing::signature('1700000000', '1234'));
    }

    public function test_pairing_stores_the_login_and_hands_the_tv_to_the_philips_driver(): void
    {
        Http::fake([
            'https://10.0.0.70:1926/6/pair/request' => Http::response(['error_id' => 'SUCCESS', 'auth_key' => 'thekey', 'timestamp' => 1700000000, 'timeout' => 60]),
            'https://10.0.0.70:1926/6/pair/grant' => Http::response(['error_id' => 'SUCCESS']),
        ]);
        $device = Device::factory()->driver(\App\Integrations\Upnp\VideoPlayerDriver::class, 'UPnP')->create(['ip_address' => '10.0.0.70']);

        $this->artisan('philips:pair', ['device' => $device->id])
            ->expectsQuestion('PIN shown on the TV', '4321')
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'pair/grant')
            && $r['auth']['pin'] === '4321'
            && $r['auth']['auth_timestamp'] === 1700000000
            && $r['auth']['auth_signature'] === Pairing::signature(1700000000, '4321')
            && str_starts_with($r->header('Authorization')[0] ?? '', 'Digest ') === false); // digest answers a challenge; the fake has none
        $device->refresh();
        $this->assertSame(VideoPlayerDriver::class, $device->device_driver);
        $this->assertSame('Philips', $device->device_driver_name);
        $this->assertSame('thekey', $device->meta()->where('key', 'philips_password')->value('value'));
        $this->assertSame(16, strlen($device->meta()->where('key', 'philips_user')->value('value')));
    }

    public function test_a_tv_that_does_not_answer_says_so(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'));
        $device = Device::factory()->create(['ip_address' => '10.0.0.71']);

        $this->artisan('philips:pair', ['device' => $device->id])->expectsOutputToContain('Is it switched on?')->assertFailed();
    }

    public function test_the_tv_is_a_media_device_with_power_and_apps(): void
    {
        $capabilities = $this->getJson('/api/devices/'.$this->tv()->id)->json('data.capabilities');

        foreach (['power', 'source_control', 'source_activation', 'queue', 'library_playback', 'media_controls'] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
    }

    public function test_standby_presses_the_standby_key_and_power_on_posts_the_power_state(): void
    {
        Http::fake(['*' => Http::response([])]);
        $driver = $this->tv()->driver;

        $driver->standby();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/6/input/key') && $r['key'] === 'Standby');

        $driver->powerOn();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/6/powerstate') && $r['powerstate'] === 'On');
    }

    public function test_the_apps_are_the_sources_and_one_is_launched_by_its_package(): void
    {
        Http::fake([
            '*/6/applications' => Http::response(['applications' => [['label' => 'Netflix', 'intent' => self::NETFLIX], ['label' => 'No intent']]]),
            '*/6/activities/current' => Http::response(['component' => ['packageName' => 'com.netflix.ninja']]),
            '*/6/activities/launch' => Http::response([]),
        ]);
        $driver = $this->tv()->driver;

        $sources = $driver->getSources();

        $this->assertCount(1, $sources);
        $this->assertSame(['com.netflix.ninja', 'Netflix', true], [$sources[0]->sourceId, $sources[0]->friendlyName, $sources[0]->active]);

        $driver->activateSource('com.netflix.ninja');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'activities/launch') && $r['intent']['component']['packageName'] === 'com.netflix.ninja');

        $this->expectException(\InvalidArgumentException::class);
        $driver->activateSource('com.unknown');
    }

    public function test_an_unpaired_or_refused_login_says_to_pair_again(): void
    {
        Http::fake(['*' => Http::response('Unauthorized', 401)]);

        $this->expectExceptionMessage('pair it again');
        JointspaceClient::forDevice($this->tv(paired: false))->applications();
    }

    public function test_while_upnp_is_idle_the_app_in_front_is_reported_as_the_playing_source(): void
    {
        Event::fake([NowPlayingUpdated::class, NowPlayingEnded::class, \App\Events\Device\DeviceStateChanged::class]);
        $package = 'com.netflix.ninja';
        Http::fake(function (Request $r) use (&$package) {
            if (str_ends_with($r->url(), 'applications')) {
                return Http::response(['applications' => [['label' => 'Netflix', 'intent' => self::NETFLIX]]]);
            }
            if (str_ends_with($r->url(), 'activities/current')) {
                return Http::response(['component' => ['packageName' => $package]]);
            }
            $action = trim(explode('#', $r->header('SOAPAction')[0] ?? '#')[1] ?? '', '"');

            return Http::response('<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><u:'.$action.'Response xmlns:u="x">'
                .($action === 'GetTransportInfo' ? '<CurrentTransportState>NO_MEDIA_PRESENT</CurrentTransportState>' : '<TrackURI></TrackURI><TrackMetaData></TrackMetaData><TrackDuration>0:00:00</TrackDuration><RelTime>NOT_IMPLEMENTED</RelTime>')
                .'</u:'.$action.'Response></s:Body></s:Envelope>');
        });
        $device = $this->tv();
        $listener = DeviceListener::forDevice($device);

        $listener->poll((string) $device->id);

        Event::assertDispatched(NowPlayingUpdated::class, fn ($e) => $e->nowPlaying->source?->name === 'Netflix' && $e->nowPlaying->source->category === 'video');
        $this->assertSame(State::Playing, DeviceCache::getState($device->id));

        // Polling again with the app still in front keeps it playing, with no standby in between.
        $changes = Event::dispatched(\App\Events\Device\DeviceStateChanged::class)->count();
        $this->travel(5)->seconds();
        $listener->poll((string) $device->id);
        $this->assertSame(State::Playing, DeviceCache::getState($device->id));
        Event::assertDispatchedTimes(\App\Events\Device\DeviceStateChanged::class, $changes);

        // Back in the TV's launcher: the TV is on at its home screen, not in standby.
        $package = 'com.google.android.tvlauncher';
        $this->travel(5)->seconds();
        $listener->poll((string) $device->id);

        Event::assertDispatched(NowPlayingUpdated::class, fn ($e) => $e->nowPlaying->source?->name === 'Home screen');
        $this->assertSame(State::Playing, DeviceCache::getState($device->id));
    }

    public function test_an_unpaired_philips_tv_is_still_found_by_generic_discovery(): void
    {
        $this->assertFalse(DedicatedDrivers::claims('Philips', 'Philips TV DMR'));
    }

    public function test_volume_is_read_from_and_set_on_the_tvs_own_scale(): void
    {
        Http::fake([
            '*/6/audio/volume' => fn (Request $r) => Http::response(['current' => 30, 'min' => 0, 'max' => 60, 'muted' => false]),
        ]);
        $driver = $this->tv()->driver;

        $this->assertSame(50, $driver->getVolume());

        $driver->setVolume(10);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['current'] === 6 && $r['muted'] === false);
    }

    public function test_the_tv_is_woken_with_a_magic_packet_once_its_mac_is_known(): void
    {
        $sent = [];
        \App\Services\Network\WakeOnLan::$fake = function (string $packet, array $targets) use (&$sent) {
            $sent = [$packet, $targets];
        };
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'));
        $device = $this->tv();

        try {
            $this->putJson("/api/devices/{$device->id}/power", ['on' => true])->assertStatus(503);

            DeviceMeta::create(['device_id' => $device->id, 'key' => 'mac_address', 'value' => '68:07:0A:4B:03:1D']);
            $this->putJson("/api/devices/{$device->id}/power", ['on' => true])->assertOk()->assertJson(['on' => true]);

            $this->assertSame(str_repeat("\xFF", 6).str_repeat(hex2bin('68070A4B031D'), 16), $sent[0]);
            $this->assertSame(['255.255.255.255', '10.0.0.255', '10.0.0.70'], $sent[1]);
        } finally {
            \App\Services\Network\WakeOnLan::$fake = null;
        }
    }

    public function test_the_mac_is_read_from_the_interface_that_has_wake_on_lan(): void
    {
        Http::fake(['*/6/network/devices' => Http::response([
            ['id' => 'wifi0', 'mac' => '1C:30:08:75:70:8B', 'type' => 'Wifi', 'wake-on-lan' => 'Disabled'],
            ['id' => 'eth0', 'mac' => '68:07:0A:4B:03:1D', 'type' => 'Ethernet', 'wake-on-lan' => 'Enabled'],
        ])]);
        $device = $this->tv();

        $this->assertSame('68:07:0A:4B:03:1D', $device->driver->rememberMac());
        $this->assertSame('68:07:0A:4B:03:1D', $device->meta()->where('key', 'mac_address')->value('value'));
    }

    public function test_a_tv_in_standby_keep_is_standby_not_the_home_screen(): void
    {
        Event::fake([NowPlayingUpdated::class, NowPlayingEnded::class, \App\Events\Device\DeviceStateChanged::class]);
        Http::fake(function (Request $r) {
            if (str_ends_with($r->url(), 'powerstate')) {
                return Http::response(['powerstate' => 'StandbyKeep']);
            }
            if (str_ends_with($r->url(), 'activities/current')) {
                return Http::response(['component' => ['packageName' => 'com.google.android.tvlauncher']]);
            }
            $action = trim(explode('#', $r->header('SOAPAction')[0] ?? '#')[1] ?? '', '"');

            return Http::response('<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><u:'.$action.'Response xmlns:u="x">'
                .($action === 'GetTransportInfo' ? '<CurrentTransportState>NO_MEDIA_PRESENT</CurrentTransportState>' : '<TrackURI></TrackURI><TrackMetaData></TrackMetaData><TrackDuration>0:00:00</TrackDuration><RelTime>NOT_IMPLEMENTED</RelTime>')
                .'</u:'.$action.'Response></s:Body></s:Envelope>');
        });
        $device = $this->tv();

        DeviceListener::forDevice($device)->poll((string) $device->id);

        $this->assertSame(State::Standby, DeviceCache::getState($device->id));
        Event::assertNotDispatched(NowPlayingUpdated::class);
    }
}
