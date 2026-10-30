<?php

namespace Tests\Feature;

use App\Domain\Device\DedicatedDrivers;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Philips\Streamium\MusicPlayerDriver;
use App\Integrations\Philips\Streamium\RemoteClient;
use App\Integrations\Philips\Streamium\Services\DeviceListener;
use App\Models\Device;
use App\Models\DeviceMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A Philips Streamium HiFi player (MCi900): the web remote on top of the UPnP driver, against a faked player. */
class StreamiumTest extends TestCase
{
    use RefreshDatabase;

    private const STATUS_PLAYING = "{'command':'ELAPSE',\n'value':190,'mute':0,'shuffle':0,'repeat':0,'play':1,'volume':6,'rating':0,'favstatus':0}";

    private const STATUS_MUTED = "{'command':'ELAPSE',\n'value':190,'mute':1,'shuffle':0,'repeat':0,'play':1,'volume':6,'rating':0,'favstatus':0}";

    private const STATUS_PAUSED = "{'command':'ELAPSE',\n'value':190,'mute':0,'shuffle':0,'repeat':0,'play':2,'volume':6,'rating':0,'favstatus':0}";

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/streamium/{$name}"));
    }

    private function player(): Device
    {
        $device = Device::factory()->driver(MusicPlayerDriver::class, 'Streamium')->create(['ip_address' => '10.0.0.80', 'device_brand_name' => 'Royal Philips Electronics', 'device_product_type' => 'MCi']);

        foreach (['upnp_avtransport_url' => 'http://10.0.0.80:7123/UD/action?2', 'upnp_capabilities' => '["seek","volume_control"]'] as $key => $value) {
            DeviceMeta::create(['device_id' => $device->id, 'key' => $key, 'value' => $value]);
        }

        return $device;
    }

    private string $status = self::STATUS_PLAYING;

    private bool $radioPlaying = true;

    private string $screen = 'nowplay.html';

    /** The web remote of a player playing radio (or not; set $status and $radioPlaying between calls), recording what was asked. */
    private function fakeRemote(array &$requests, string $status = self::STATUS_PLAYING, bool $playing = true): void
    {
        $this->status = $status;
        $this->radioPlaying = $playing;

        Http::fake(function (Request $r) use (&$requests) {
            $requests[] = $r->method().' '.$r->url();
            $url = $r->url();

            return match (true) {
                str_contains($url, '/ELAPSE') => Http::response($this->status),
                str_contains($url, '/nowplay') => Http::response($this->radioPlaying ? $this->fixture($this->screen) : '<html><title>Home</title></html>'),
                str_contains($url, '/nav$03$02$001$0') => Http::response($this->fixture('presets.html')),
                str_contains($url, '/HOMESTATUS') => Http::response("{'command':'NOTHING',\n'value':0}"),
                str_contains($url, 'SOAP') || str_contains($url, '/UD/action') => $this->upnpIdle($r),
                default => Http::response("{'command':'SUCCESS',\n'value':0}"),
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

    public function test_the_play_screen_of_a_radio_stream_is_parsed(): void
    {
        $item = RemoteClient::parsePlayItem($this->fixture('nowplay.html'));

        $this->assertSame('NPO Radio 1 - NOS Radio 1 Journaal - NOS,NTR', $item['title']);
        $this->assertSame('NPO Radio 1', $item['station']);
        $this->assertSame('http://radiotime-logos.s3.amazonaws.com/s17523.jpg', $item['art']);
        $this->assertSame([6, 64], [$item['volume'], $item['max_volume']]);
        $this->assertNull(RemoteClient::parsePlayItem('<html><title>Home</title></html>'));
    }

    public function test_the_ten_radio_presets_come_with_the_address_that_plays_them(): void
    {
        $presets = RemoteClient::parsePresets($this->fixture('presets.html'));

        $this->assertCount(10, $presets);
        $this->assertSame(['id' => 3, 'name' => 'NPO 3FM', 'target' => '$03$03$003$1'], ['id' => $presets[2]['id'], 'name' => $presets[2]['name'], 'target' => substr($presets[2]['target'], 4)]);
        $this->assertSame('/nav$03$03$010$1', $presets[9]['target']);
    }

    public function test_the_status_is_only_read_from_the_elapse_form_not_the_change_signal(): void
    {
        $this->assertNull(RemoteClient::parseStatus("{'command':'NOWPLAY','value':0}"));

        $status = RemoteClient::parseStatus(self::STATUS_PAUSED);
        $this->assertTrue($status['paused']);
        $this->assertFalse($status['playing']);
        $this->assertSame(6, $status['volume']);
    }

    public function test_the_player_has_power_sources_and_the_upnp_capabilities(): void
    {
        $capabilities = $this->getJson('/api/devices/'.$this->player()->id)->json('data.capabilities');

        foreach (['power', 'source_control', 'source_activation', 'queue', 'library_playback', 'media_controls', 'volume_control'] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
    }

    public function test_standby_toggles_and_the_server_remembers_it_to_wake_the_player_again(): void
    {
        $requests = [];
        $this->fakeRemote($requests);
        $driver = $this->player()->driver;

        try {
            $driver->powerOn();
            $this->fail('A player the server did not put to standby must not be toggled.');
        } catch (UnsupportedOperationException) {
            $this->assertSame([], $requests);
        }

        $driver->standby();
        $this->assertTrue(MusicPlayerDriver::believedAsleep($driver->device->id));
        $this->assertSame('POST http://10.0.0.80:8889/CTRL$STANDBY', $requests[0]);

        $driver->powerOn();
        $this->assertFalse(MusicPlayerDriver::believedAsleep($driver->device->id));
        $this->assertCount(2, $requests);
    }

    public function test_the_presets_are_the_sources_and_one_is_played_by_its_address(): void
    {
        $requests = [];
        $this->fakeRemote($requests);
        $driver = $this->player()->driver;

        $sources = $driver->getSources();

        $this->assertCount(12, $sources);
        $this->assertSame(['aux', 'disc', 'preset:1'], [$sources[0]->sourceId, $sources[1]->sourceId, $sources[2]->sourceId]);
        $this->assertSame(['NPO Radio 1', 'radio'], [$sources[2]->friendlyName, $sources[2]->category]);
        // The radio is what the screen shows: its station is the active one, the inputs are not.
        $this->assertSame([false, false, true, false], [$sources[0]->active, $sources[1]->active, $sources[2]->active, $sources[3]->active]);

        $driver->activateSource('preset:3');

        $this->assertContains('GET http://10.0.0.80:8889/nav$03$03$003$1', $requests);
        $this->assertTrue(MusicPlayerDriver::isLocal($driver->device->id));

        $this->expectException(\InvalidArgumentException::class);
        $driver->activateSource('preset:99');
    }

    public function test_pause_and_play_toggle_only_when_the_player_is_in_the_other_state(): void
    {
        $toggle = 'GET http://10.0.0.80:8889/CTRL$PLAY_PAUSE';
        $requests = [];
        $this->fakeRemote($requests, self::STATUS_PLAYING);
        $driver = $this->player()->driver;
        MusicPlayerDriver::markLocal($driver->device->id);

        $driver->play(); // already playing, and a local source never falls through to the UPnP queue
        $this->assertNotContains($toggle, $requests);

        $driver->pause();
        $this->assertCount(1, array_keys($requests, $toggle));

        // A live radio stream has no pause: the button mutes it, and play unmutes.
        $this->status = self::STATUS_MUTED;
        $driver->pause();
        $this->assertCount(1, array_keys($requests, $toggle));
        $driver->play();
        $this->assertCount(2, array_keys($requests, $toggle));

        $this->status = self::STATUS_PAUSED;
        $driver->play();
        $this->assertCount(3, array_keys($requests, $toggle));
    }

    public function test_stop_next_and_previous_go_to_the_player_while_a_local_source_plays(): void
    {
        $requests = [];
        $this->fakeRemote($requests);
        $driver = $this->player()->driver;
        MusicPlayerDriver::markLocal($driver->device->id);

        $driver->next();
        $driver->previous();
        $driver->stop();

        foreach (['/CTRL$NEXT', '/CTRL$PREV', '/CTRL$STOP'] as $path) {
            $this->assertContains('GET http://10.0.0.80:8889'.$path, $requests);
        }
        $this->assertFalse(MusicPlayerDriver::isLocal($driver->device->id));
    }

    public function test_a_command_the_player_does_not_accept_is_an_error(): void
    {
        Http::fake(['*' => Http::response("{'command':'ERROR','value':'busy'}")]);

        $this->expectExceptionMessage('did not accept');
        (new RemoteClient('http://10.0.0.80:8889'))->command('/CTRL$STOP');
    }

    public function test_while_upnp_is_idle_the_radio_playing_is_reported_and_ends_with_it(): void
    {
        Event::fake([NowPlayingUpdated::class, NowPlayingEnded::class, \App\Events\Device\DeviceStateChanged::class]);
        $requests = [];
        $this->fakeRemote($requests);
        $device = $this->player();
        $listener = DeviceListener::forDevice($device);

        $listener->poll((string) $device->id);

        Event::assertDispatched(NowPlayingUpdated::class, fn ($e) => $e->nowPlaying->radio?->name === 'NPO Radio 1'
            && $e->nowPlaying->track->name === 'NOS Radio 1 Journaal - NOS,NTR'
            && $e->nowPlaying->source->category === 'radio'
            && $e->nowPlaying->state === 'playing');
        $this->assertSame(State::Playing, DeviceCache::getState($device->id));
        $this->assertTrue(MusicPlayerDriver::isLocal($device->id));

        // The same song again is not reported twice, and a pause is (on radio, a muted stream).
        $this->travel(5)->seconds();
        $listener->poll((string) $device->id);
        Event::assertDispatchedTimes(NowPlayingUpdated::class, 1);

        $this->status = self::STATUS_MUTED;
        $this->travel(5)->seconds();
        $listener->poll((string) $device->id);
        Event::assertDispatchedTimes(NowPlayingUpdated::class, 2);
        $this->assertSame(State::Paused, DeviceCache::getState($device->id));

        // The unit goes back to its menu: nothing plays any more.
        $this->radioPlaying = false;
        $this->travel(5)->seconds();
        $listener->poll((string) $device->id);

        Event::assertDispatched(NowPlayingEnded::class);
        $this->assertFalse(MusicPlayerDriver::isLocal($device->id));
    }

    public function test_a_status_that_only_signals_a_change_leaves_what_was_reported_alone(): void
    {
        Event::fake([NowPlayingUpdated::class, NowPlayingEnded::class]);
        $requests = [];
        $this->fakeRemote($requests);
        $device = $this->player();
        $listener = DeviceListener::forDevice($device);
        $listener->poll((string) $device->id);

        $this->status = "{'command':'NOWPLAY','value':0}";
        $this->travel(5)->seconds();
        $listener->poll((string) $device->id);

        Event::assertNotDispatched(NowPlayingEnded::class);
        $this->assertSame(State::Playing, DeviceCache::getState($device->id));
    }

    public function test_the_play_screen_names_the_source_the_player_is_on(): void
    {
        $this->assertSame('radio', RemoteClient::parseScreen($this->fixture('nowplay.html'))['source']);

        $aux = RemoteClient::parseScreen($this->fixture('aux.html'));
        $this->assertSame(['aux', null], [$aux['source'], $aux['item']]);

        // An empty drive's screen has the disc artwork and a -1 title: a source, but nothing playing.
        $disc = RemoteClient::parseScreen($this->fixture('disc.html'));
        $this->assertSame(['disc', null], [$disc['source'], $disc['item']]);

        $this->assertSame(['source' => null, 'item' => null], RemoteClient::parseScreen('<html><title>Home</title></html>'));
    }

    public function test_the_inputs_are_selected_by_opening_their_page(): void
    {
        $requests = [];
        $this->fakeRemote($requests);
        $driver = $this->player()->driver;

        $driver->activateSource('aux');
        $driver->activateSource('disc');

        $this->assertContains('GET http://10.0.0.80:8889/aux', $requests);
        $this->assertContains('GET http://10.0.0.80:8889/dvd', $requests);
        $this->assertTrue(MusicPlayerDriver::isLocal($driver->device->id));
    }

    public function test_the_aux_input_is_active_and_reported_as_the_playing_source(): void
    {
        Event::fake([NowPlayingUpdated::class, NowPlayingEnded::class]);
        $requests = [];
        $this->fakeRemote($requests);
        $this->screen = 'aux.html';
        $device = $this->player();

        $sources = $device->driver->getSources();
        $this->assertSame([true, false], [$sources[0]->active, $sources[1]->active]);

        DeviceListener::forDevice($device)->poll((string) $device->id);

        Event::assertDispatched(NowPlayingUpdated::class, fn ($e) => $e->nowPlaying->source?->name === 'AUX'
            && $e->nowPlaying->source->sourceType === 'LINE_IN'
            && $e->nowPlaying->track === null
            && $e->nowPlaying->state === 'playing');
        $this->assertSame(State::Playing, DeviceCache::getState($device->id));
    }

    public function test_an_empty_disc_drive_is_a_source_but_not_reported_as_playing(): void
    {
        Event::fake([NowPlayingUpdated::class]);
        $requests = [];
        $this->fakeRemote($requests);
        $this->screen = 'disc.html';
        $device = $this->player();

        $this->assertTrue($device->driver->getSources()[1]->active);

        DeviceListener::forDevice($device)->poll((string) $device->id);

        Event::assertNotDispatched(NowPlayingUpdated::class);
    }

    public function test_a_player_the_server_put_to_standby_is_not_asked_what_it_plays(): void
    {
        Event::fake([NowPlayingUpdated::class]);
        $requests = [];
        $this->fakeRemote($requests);
        $device = $this->player();
        Cache::forever("streamium:{$device->id}:asleep", true);

        DeviceListener::forDevice($device)->poll((string) $device->id);

        Event::assertNotDispatched(NowPlayingUpdated::class);
        $this->assertEmpty(array_filter($requests, fn ($r) => str_contains($r, ':8889/')));
    }

    public function test_enabling_hands_the_player_to_the_streamium_driver(): void
    {
        $requests = [];
        $this->fakeRemote($requests);
        $device = Device::factory()->create(['ip_address' => '10.0.0.80']);

        $this->artisan('streamium:enable', ['device' => $device->id])->assertSuccessful();

        $device->refresh();
        $this->assertSame(MusicPlayerDriver::class, $device->device_driver);
        $this->assertSame('Streamium', $device->device_driver_name);
        $this->assertSame('http://10.0.0.80:8889', $device->meta()->where('key', 'streamium_remote_url')->value('value'));
    }

    public function test_a_player_without_a_web_remote_is_left_alone(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));
        $device = Device::factory()->create(['ip_address' => '10.0.0.81']);
        $driver = $device->device_driver;

        $this->artisan('streamium:enable', ['device' => $device->id])->expectsOutputToContain('Is the player switched on?')->assertFailed();

        $this->assertSame($driver, $device->fresh()->device_driver);
    }

    public function test_generic_discovery_still_finds_a_streamium_player(): void
    {
        $this->assertFalse(DedicatedDrivers::claims('Royal Philips Electronics', 'MCi'));
    }
}
