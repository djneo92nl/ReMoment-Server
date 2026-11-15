<?php

namespace Tests\Feature;

use App\Domain\Device\DedicatedDrivers;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Events\Device\BatteryUpdated;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\PlaybackModesUpdated;
use App\Events\Device\VolumeUpdated;
use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Wiim\Console\WiimProbe;
use App\Integrations\Wiim\MusicPlayerDriver;
use App\Integrations\Wiim\Services\DeviceListener;
use App\Integrations\Wiim\WiimClient;
use App\Integrations\Wiim\WiimDiscovery;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Services\Discovery\SsdpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A WiiM (LinkPlay): the HTTP API client, driver, listener and discovery, against the vendor document's example answers (tests/Fixtures/Wiim). */
class WiimTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/Wiim/{$name}"));
    }

    private function wiim(): Device
    {
        $device = Device::factory()->driver(MusicPlayerDriver::class, 'WiiM')->create(['ip_address' => '10.0.0.90', 'device_brand_name' => 'WiiM', 'device_product_type' => 'WiiM Mini']);

        foreach (['upnp_avtransport_url' => 'http://10.0.0.90:49152/upnp/control/rendertransport1', 'upnp_capabilities' => '["seek","volume_control"]'] as $key => $value) {
            DeviceMeta::create(['device_id' => $device->id, 'key' => $key, 'value' => $value]);
        }

        return $device;
    }

    /** @var string[] */
    private array $requests = [];

    private string $playerStatus = '';

    /** The WiiM's HTTP API from the fixtures (the player status can be swapped), and an idle UPnP side. */
    private function fakeWiim(?string $playerStatus = null): void
    {
        $this->playerStatus = $playerStatus ?? $this->fixture('getPlayerStatus.json');
        $this->requests = [];

        Http::fake(function (Request $r) {
            $this->requests[] = rawurldecode($r->url());

            if (!str_contains($r->url(), 'httpapi.asp')) {
                return $this->upnpIdle($r);
            }

            return match (true) {
                str_contains($r->url(), 'command=getStatusEx') => Http::response($this->fixture('getStatusEx.json')),
                str_contains($r->url(), 'command=getPlayerStatus') => Http::response($this->playerStatus),
                str_contains($r->url(), 'command=getMetaInfo') => Http::response($this->fixture('getMetaInfo.json')),
                str_contains($r->url(), 'command=getPresetInfo') => Http::response($this->fixture('getPresetInfo.json')),
                str_contains($r->url(), 'command=EQGetList') => Http::response($this->fixture('EQGetList.json')),
                str_contains($r->url(), 'command=EQ') || str_contains($r->url(), 'setShutdown') => Http::response('{"status":"OK"}'),
                default => Http::response('OK'),
            };
        });
    }

    private function upnpIdle(Request $r)
    {
        $action = trim(explode('#', $r->header('SOAPAction')[0] ?? '#')[1] ?? '', '"');

        return Http::response('<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><u:'.$action.'Response xmlns:u="x">'
            .($action === 'GetTransportInfo' ? '<CurrentTransportState>NO_MEDIA_PRESENT</CurrentTransportState>' : '<TrackURI></TrackURI><TrackMetaData></TrackMetaData><TrackDuration>0:00:00</TrackDuration><RelTime>NOT_IMPLEMENTED</RelTime>')
            .'</u:'.$action.'Response></s:Body></s:Envelope>');
    }

    private function sent(string $needle): bool
    {
        return collect($this->requests)->contains(fn ($u) => str_contains($u, $needle));
    }

    public function test_the_documents_example_answers_parse_despite_their_quirks(): void
    {
        $status = WiimClient::parsePlayerStatus(WiimClient::decode($this->fixture('getPlayerStatus.json')));

        // `" status"` has a leading space in the document; curpos is ms, seek takes seconds.
        $this->assertSame('play', $status['status']);
        $this->assertSame([10, 4, 184, 0, 39, false], [$status['mode'], $status['loop'], $status['position'], $status['duration'], $status['volume'], $status['muted']]);

        $this->fakeWiim();
        $client = new WiimClient('https://10.0.0.90');

        $meta = $client->metaInfo();
        $this->assertSame(['Old Dirt Roads', 'Owen Riegling', 'Country Heat', 44100, 16], [$meta['title'], $meta['artist'], $meta['album'], $meta['sample_rate'], $meta['bit_depth']]);
        $this->assertSame('https://m.media-amazon.com/images/I/51iU0odzJwL.jpg', $meta['art']);

        $presets = $client->presets();
        $this->assertSame([1, 6, 8], array_column($presets, 'number'));
        $this->assertSame('Radio Paradise', $presets[1]['name']);
        $this->assertCount(22, $client->eqList());
    }

    public function test_the_client_keeps_colons_raw_and_escapes_the_rest(): void
    {
        $this->assertSame('EQLoad:Bass%20Booster', WiimClient::encode('EQLoad:Bass Booster'));
        $this->assertSame('setPlayerCmd:seek:90', WiimClient::encode('setPlayerCmd:seek:90'));
    }

    public function test_it_has_the_capabilities_the_api_supports_and_the_upnp_ones(): void
    {
        $capabilities = $this->getJson('/api/devices/'.$this->wiim()->id)->json('data.capabilities');

        foreach (['media_controls', 'volume_control', 'seek', 'shuffle', 'repeat', 'source_control', 'source_activation', 'power', 'device_info', 'queue', 'library_playback', 'stream_url'] as $capability) {
            $this->assertContains($capability, $capabilities);
        }

        $this->assertNotContains('sound_adjustment', $capabilities);
        $this->assertNotContains('battery', $capabilities); // listed once the listener has reported one
        $this->assertNotContains('multi_room', $capabilities);
    }

    public function test_volume_mute_and_seek_use_the_wiim_api(): void
    {
        $this->fakeWiim();
        $driver = $this->wiim()->driver;

        $this->assertSame(39, $driver->getVolume());
        $this->assertSame(100, $driver->setVolume(140));
        $driver->mute();
        $driver->unmute();
        $driver->seek(90);

        foreach (['setPlayerCmd:vol:100', 'setPlayerCmd:mute:1', 'setPlayerCmd:mute:0', 'setPlayerCmd:seek:90'] as $command) {
            $this->assertTrue($this->sent($command), $command);
        }
    }

    public function test_transport_goes_to_the_wiim_while_it_plays_something_of_its_own(): void
    {
        $this->fakeWiim();
        $driver = $this->wiim()->driver;

        $driver->pause();
        $driver->play();
        $driver->next();
        $driver->previous();
        $driver->stop();

        foreach (['setPlayerCmd:pause', 'setPlayerCmd:resume', 'setPlayerCmd:next', 'setPlayerCmd:prev', 'setPlayerCmd:stop'] as $command) {
            $this->assertTrue($this->sent($command), $command);
        }
    }

    public function test_transport_stays_with_the_servers_queue_while_the_server_plays(): void
    {
        $this->fakeWiim();
        $device = $this->wiim();
        Cache::put("upnp:{$device->id}:playing", ['track_id' => 1, 'uri' => 'http://x/1.flac'], 600);

        $device->driver->pause();

        $this->assertFalse($this->sent('setPlayerCmd:pause'));
        $this->assertTrue(collect($this->requests)->contains(fn ($u) => str_contains($u, '49152')), 'the pause went over UPnP');
    }

    public function test_shuffle_and_repeat_become_one_loop_code(): void
    {
        $this->fakeWiim();
        $driver = $this->wiim()->driver;

        $driver->setShuffle(true); // reported loop 4 = none: shuffle, no repeat
        $this->assertTrue($this->sent('setPlayerCmd:loopmode:3'));

        $driver->setRepeat(\App\Domain\Device\RepeatMode::One);
        $this->assertTrue($this->sent('setPlayerCmd:loopmode:1'));

        $driver->setRepeat(\App\Domain\Device\RepeatMode::All);
        $this->assertTrue($this->sent('setPlayerCmd:loopmode:-1'));

        $this->fakeWiim(str_replace('"loop":"4"', '"loop":"0"', $this->fixture('getPlayerStatus.json'))); // loop all
        $driver->setShuffle(true);
        $this->assertTrue($this->sent('setPlayerCmd:loopmode:2'));
    }

    public function test_sources_are_the_inputs_then_the_presets_and_activating_one_switches_it(): void
    {
        $this->fakeWiim(str_replace('"mode":"10"', '"mode":"43"', $this->fixture('getPlayerStatus.json')));
        $driver = $this->wiim()->driver;

        $sources = collect($driver->getSources())->keyBy('sourceId');
        $this->assertSame(['line-in', 'bluetooth', 'optical', 'udisk', 'wifi', 'preset:1', 'preset:6', 'preset:8'], $sources->keys()->all());
        $this->assertTrue($sources['optical']->active);
        $this->assertFalse($sources['bluetooth']->active);

        $driver->activateSource('bluetooth');
        $driver->activateSource('preset:6');
        $this->assertTrue($this->sent('setPlayerCmd:switchmode:bluetooth'));
        $this->assertTrue($this->sent('MCUKeyShortClick:6'));

        $this->expectException(\InvalidArgumentException::class);
        $driver->activateSource('preset:2');
    }

    public function test_info_battery_and_standby(): void
    {
        $this->fakeWiim();
        $driver = $this->wiim()->driver;

        $info = $driver->getDeviceInfo();
        $this->assertSame(['WiiM Mini-8FA2', 'Linkplay.4.6.425351', '08:E9:F6:8F:8F:A2', false], [$info->name, $info->firmware, $info->macAddress, $info->renamable]);

        $this->assertNull($driver->getBattery()); // battery "0": a mains model
        $this->assertSame(63, MusicPlayerDriver::batteryOf(['battery' => '1', 'battery_percent' => '63'])->level);

        $driver->standby();
        $this->assertTrue($this->sent('setShutdown:0'));

        $this->expectException(UnsupportedOperationException::class);
        $driver->powerOn();
    }

    public function test_equaliser_presets_are_loaded_by_name(): void
    {
        $this->fakeWiim();
        $driver = $this->wiim()->driver;

        $driver->setEqPreset('Bass Booster');
        $this->assertTrue($this->sent('command=EQOn'));
        $this->assertTrue($this->sent('EQLoad:Bass Booster'));

        $this->expectException(\InvalidArgumentException::class);
        $driver->setEqPreset('Nope');
    }

    public function test_the_listener_reports_what_the_wiim_plays_by_itself(): void
    {
        Event::fake([NowPlayingUpdated::class, NowPlayingEnded::class, VolumeUpdated::class, PlaybackModesUpdated::class, BatteryUpdated::class]);
        $this->fakeWiim(str_replace('"mode":"10"', '"mode":"31"', $this->fixture('getPlayerStatus.json')));
        $device = $this->wiim();
        $listener = DeviceListener::forDevice($device);

        $listener->poll((string) $device->id);

        Event::assertDispatched(NowPlayingUpdated::class, function ($e) {
            return $e->nowPlaying->track->name === 'Old Dirt Roads'
                && $e->nowPlaying->source->name === 'Spotify Connect'
                && $e->nowPlaying->state === 'playing'
                && $e->nowPlaying->album->name === 'Country Heat';
        });
        Event::assertDispatched(VolumeUpdated::class, fn ($e) => $e->volume === 39 && $e->muted === false);
        Event::assertDispatched(PlaybackModesUpdated::class);
        Event::assertNotDispatched(BatteryUpdated::class);
        $this->assertSame(State::Playing, DeviceCache::getState($device->id));
    }

    public function test_the_listener_ends_the_now_playing_when_the_wiim_stops(): void
    {
        Event::fake([NowPlayingUpdated::class, NowPlayingEnded::class, VolumeUpdated::class, PlaybackModesUpdated::class]);
        $this->fakeWiim();
        $device = $this->wiim();
        $listener = DeviceListener::forDevice($device);
        $listener->poll((string) $device->id);

        $this->playerStatus = str_replace('"play"', '"stop"', $this->playerStatus);
        $this->travel(5)->seconds();
        $listener->poll((string) $device->id);

        Event::assertDispatched(NowPlayingEnded::class);
        $this->assertSame(State::Standby, DeviceCache::getState($device->id));
    }

    public function test_a_group_member_is_not_reported_as_playing(): void
    {
        Event::fake([NowPlayingUpdated::class, VolumeUpdated::class, PlaybackModesUpdated::class]);
        $this->fakeWiim(str_replace(['"type":"0"', '"mode":"10"'], ['"type":"1"', '"mode":"99"'], $this->fixture('getPlayerStatus.json')));
        $device = $this->wiim();

        DeviceListener::forDevice($device)->poll((string) $device->id);

        Event::assertNotDispatched(NowPlayingUpdated::class);
    }

    public function test_discovery_finds_a_wiim_by_its_descriptor_and_leaves_other_renderers(): void
    {
        $xml = fn (string $maker, string $model) => '<root xmlns="urn:schemas-upnp-org:device-1-0"><device><friendlyName>Kitchen</friendlyName><manufacturer>'.$maker.'</manufacturer><modelName>'.$model.'</modelName><UDN>uuid:FF970016</UDN><serviceList><service><serviceType>urn:schemas-upnp-org:service:AVTransport:1</serviceType><controlURL>/upnp/control/rendertransport1</controlURL><SCPDURL>/scpd.xml</SCPDURL></service></serviceList></device></root>';

        Http::fake([
            'http://10.0.0.90/*' => Http::response($xml('Linkplay Technology Inc.', 'WiiM Pro')),
            'http://10.0.0.91/*' => Http::response($xml('Acme', 'Box')),
            '*' => Http::response('', 404),
        ]);

        $ssdp = \Mockery::mock(SsdpClient::class);
        $ssdp->shouldReceive('search')->andReturn([
            ['ip' => '10.0.0.90', 'location' => 'http://10.0.0.90/description.xml', 'st' => null, 'usn' => null],
            ['ip' => '10.0.0.91', 'location' => 'http://10.0.0.91/description.xml', 'st' => null, 'usn' => null],
        ]);

        $found = (new WiimDiscovery($ssdp))->discover();

        $this->assertCount(1, $found);
        $this->assertSame(['10.0.0.90', 'WiiM', 'WiiM Pro', MusicPlayerDriver::class, 'WiiM'], [$found[0]->ip_address, $found[0]->device_brand_name, $found[0]->device_product_type, $found[0]->device_driver, $found[0]->device_driver_name]);
        $this->assertContains('seek', json_decode($found[0]->meta['upnp_capabilities'], true));
        $this->assertSame('uuid:FF970016', $found[0]->meta['upnp_uuid']);
    }

    public function test_the_generic_upnp_driver_leaves_a_wiim_alone(): void
    {
        $this->assertTrue(DedicatedDrivers::claims('Linkplay Technology Inc.', 'Whatever'));
        $this->assertTrue(DedicatedDrivers::claims('Someone', 'WiiM Amp'));
        $this->assertFalse(DedicatedDrivers::claims('Acme', 'Box'));
    }

    public function test_the_probe_only_asks_read_only_commands_and_records_the_answers(): void
    {
        $this->fakeWiim();
        $file = sys_get_temp_dir().'/wiim-probe-'.uniqid().'.json';

        $this->artisan('wiim:probe', ['host' => '10.0.0.90', '--out' => $file])->assertSuccessful();

        $record = json_decode(file_get_contents($file), true);
        unlink($file);

        $this->assertSame(WiimProbe::COMMANDS, array_keys($record['results']));
        $this->assertSame('WiiM Mini-8FA2', $record['results']['getStatusEx']['json']['DeviceName']);
        $this->assertFalse(collect($this->requests)->contains(fn ($u) => str_contains($u, 'setPlayerCmd') || str_contains($u, 'EQLoad') || str_contains($u, 'setShutdown') || str_contains($u, 'reboot')));
    }
}
