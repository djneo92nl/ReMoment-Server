<?php

namespace Tests\Feature;

use App\Domain\Device\DedicatedDrivers;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Radio\StreamTitles;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Integrations\Common\DeviceQueue;
use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Common\UpnpSoap;
use App\Integrations\Upnp\DeviceDescription;
use App\Integrations\Upnp\DidlLite;
use App\Integrations\Upnp\MusicPlayerDriver;
use App\Integrations\Upnp\Services\DeviceListener;
use App\Integrations\Upnp\UpnpCapabilities;
use App\Integrations\Upnp\UpnpDiscovery;
use App\Jobs\PollRadioTitle;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Models\RadioStation;
use App\Services\Discovery\SsdpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** The generic UPnP MediaRenderer driver: SOAP, queue, listener and discovery against a faked renderer. */
class UpnpRendererTest extends TestCase
{
    use RefreshDatabase;

    /** What the faked renderer answers, changed by a test between polls. */
    private array $renderer = ['state' => 'STOPPED', 'uri' => '', 'meta' => '', 'duration' => '0:03:00', 'position' => '0:00:00', 'volume' => '40'];

    private function envelope(string $action, array $out = []): string
    {
        $body = '';
        foreach ($out as $k => $v) {
            $body .= "<{$k}>".htmlspecialchars((string) $v, ENT_XML1)."</{$k}>";
        }

        return '<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
            ."<u:{$action}Response xmlns:u=\"urn:schemas-upnp-org:service:AVTransport:1\">{$body}</u:{$action}Response></s:Body></s:Envelope>";
    }

    private function fakeRenderer(): void
    {
        Http::fake(function (Request $request) {
            $action = trim(explode('#', $request->header('SOAPAction')[0] ?? '#')[1] ?? '', '"');

            $out = match ($action) {
                'GetTransportInfo' => ['CurrentTransportState' => $this->renderer['state']],
                'GetPositionInfo' => ['TrackURI' => $this->renderer['uri'], 'TrackMetaData' => $this->renderer['meta'], 'TrackDuration' => $this->renderer['duration'], 'RelTime' => $this->renderer['position']],
                'GetVolume' => ['CurrentVolume' => $this->renderer['volume']],
                'GetMute' => ['CurrentMute' => '0'],
                default => [],
            };

            return Http::response($this->envelope($action, $out));
        });
    }

    /** @return string[] the SOAP actions sent so far, in order */
    private function sent(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => trim(explode('#', $pair[0]->header('SOAPAction')[0] ?? '#')[1] ?? '', '"'))->all();
    }

    private function device(array $capabilities = ['seek', 'volume_control', 'gapless']): Device
    {
        $device = Device::factory()->driver(MusicPlayerDriver::class, 'UPnP')->create(['ip_address' => '10.0.0.30', 'device_brand_name' => 'WiiM', 'device_product_type' => 'Mini']);

        foreach (['upnp_avtransport_url' => 'http://10.0.0.30/av', 'upnp_renderingcontrol_url' => 'http://10.0.0.30/rc', 'upnp_capabilities' => json_encode($capabilities)] as $key => $value) {
            DeviceMeta::create(['device_id' => $device->id, 'key' => $key, 'value' => $value]);
        }

        return $device;
    }

    private function track(string $name, ?string $url = null): Track
    {
        $artist = Artist::firstOrCreate(['name' => 'Artist']);
        $album = Album::firstOrCreate(['name' => 'Album', 'artist_id' => $artist->id]);
        $track = Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'external_id' => uniqid('dlna:', true), 'name' => $name, 'source' => 'dlna', 'duration' => 180]);
        Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $track->id, 'key' => 'dlna_url', 'value' => $url ?? "http://dlna.test/{$name}.flac", 'type' => 'url', 'source' => 'dlna:1']);

        return $track;
    }

    public function test_soap_answers_the_out_arguments_and_turns_faults_into_exceptions(): void
    {
        $this->assertSame(['CurrentVolume' => '12'], UpnpSoap::parse($this->envelope('GetVolume', ['CurrentVolume' => 12]))['out']);

        $fault = '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault><faultstring>UPnPError</faultstring><detail><UPnPError xmlns="urn:schemas-upnp-org:control-1-0"><errorCode>701</errorCode><errorDescription>Transition not available</errorDescription></UPnPError></detail></s:Fault></s:Body></s:Envelope>';
        Http::fake(['*' => Http::response($fault, 500)]);

        $this->expectException(UnsupportedOperationException::class);
        (new UpnpSoap('http://r.test/av', DeviceDescription::AV_TRANSPORT))->call('Next', ['InstanceID' => 0]);
    }

    public function test_the_descriptor_gives_absolute_control_urls_and_the_scpd_the_capabilities(): void
    {
        $xml = '<root xmlns="urn:schemas-upnp-org:device-1-0"><device><friendlyName>Kitchen</friendlyName><manufacturer>WiiM</manufacturer><modelName>Mini</modelName><UDN>uuid:abc</UDN><serviceList>'
            .'<service><serviceType>urn:schemas-upnp-org:service:AVTransport:1</serviceType><controlURL>/upnp/av</controlURL><SCPDURL>av.xml</SCPDURL></service>'
            .'<service><serviceType>urn:schemas-upnp-org:service:RenderingControl:1</serviceType><controlURL>http://other:1/rc</controlURL><SCPDURL>/rc.xml</SCPDURL></service>'
            .'</serviceList></device></root>';
        $scpd = fn (array $names, string $extra = '') => '<scpd xmlns="urn:schemas-upnp-org:service-1-0"><actionList>'.collect($names)->map(fn ($n) => "<action><name>{$n}</name></action>")->implode('').'</actionList><serviceStateTable>'.$extra.'</serviceStateTable></scpd>';
        Http::fake([
            'http://10.0.0.30:49152/desc/root.xml' => Http::response($xml),
            'http://10.0.0.30:49152/desc/av.xml' => Http::response($scpd(['Play', 'Seek', 'SetNextAVTransportURI'])),
            'http://10.0.0.30:49152/rc.xml' => Http::response($scpd(['GetVolume', 'SetVolume'], '<stateVariable><name>Volume</name><allowedValueRange><minimum>0</minimum><maximum>60</maximum></allowedValueRange></stateVariable>')),
        ]);

        $description = DeviceDescription::fetch('http://10.0.0.30:49152/desc/root.xml');

        $this->assertSame('http://10.0.0.30:49152/upnp/av', $description->controlUrl(DeviceDescription::AV_TRANSPORT));
        $this->assertSame('http://other:1/rc', $description->controlUrl(DeviceDescription::RENDERING_CONTROL));
        $this->assertSame(60, $description->volumeMax());
        $this->assertEqualsCanonicalizing(['seek', 'volume_control', 'gapless'], UpnpCapabilities::fromActions($description->actions(DeviceDescription::AV_TRANSPORT), $description->actions(DeviceDescription::RENDERING_CONTROL)));
    }

    public function test_didl_round_trips_and_times_parse(): void
    {
        $didl = DidlLite::build('http://x/a.flac', 'Tom & Jerry', 'Artist', 'Album', 'http://x/c.jpg', 200, 'audio/flac');

        $this->assertSame(['title' => 'Tom & Jerry', 'artist' => 'Artist', 'album' => 'Album', 'art' => 'http://x/c.jpg'], DidlLite::parse($didl));
        $this->assertStringContainsString('duration="0:03:20"', $didl);
        $this->assertSame(201, DidlLite::seconds('0:03:21.500'));
        $this->assertNull(DidlLite::seconds('NOT_IMPLEMENTED'));
    }

    public function test_capabilities_follow_what_the_renderer_declared(): void
    {
        $device = $this->device(['volume_control']);

        $capabilities = $this->getJson("/api/devices/{$device->id}")->json('data.capabilities');

        $this->assertContains('queue', $capabilities);
        $this->assertContains('shuffle', $capabilities);
        $this->assertContains('volume_control', $capabilities);
        $this->assertNotContains('seek', $capabilities);
        $this->assertNotContains('multi_room', $capabilities);
        DeviceCache::updateState($device->id, State::Standby);
        $this->putJson("/api/devices/{$device->id}/seek", ['position' => 10])->assertStatus(422)->assertJsonPath('error', 'unsupported');
    }

    public function test_a_library_playlist_fills_the_queue_and_starts_the_first_track(): void
    {
        $this->fakeRenderer();
        $device = $this->device();
        $tracks = [$this->track('one'), $this->track('two'), $this->track('three')];

        $device->driver->playLibraryTracks(collect($tracks));

        $this->assertSame(['Stop', 'SetAVTransportURI', 'Play'], $this->sent());
        Http::assertSent(fn (Request $r) => str_contains($r->body(), 'http://dlna.test/one.flac') && str_contains($r->body(), 'DIDL-Lite'));
        $this->assertSame([$tracks[1]->id, $tracks[2]->id], DeviceQueue::for($device->id)->upNext(10));
        $this->assertSame(['two', 'three'], array_map(fn ($item) => $item->name, $device->driver->getUpNext()));
    }

    public function test_our_own_stream_is_handed_to_the_renderer_with_the_file_extension(): void
    {
        $this->fakeRenderer();
        $device = $this->device();
        $track = $this->track('own', \App\Domain\Storage\StreamUrl::for(0));
        Metadata::where('metadatable_id', $track->id)->update(['value' => \App\Domain\Storage\StreamUrl::for($track->id)]);
        $location = \App\Models\StorageLocation::create(['name' => 'lib', 'driver' => 'local', 'role' => 'library', 'config' => ['root' => '/tmp/lib']]);
        $file = \App\Models\File::forceCreate(['storage_location_id' => $location->id, 'path' => 'a.flac', 'path_hash' => 'h', 'hash' => 'h', 'size' => 1, 'mtime' => 1, 'mime_type' => 'audio/flac', 'extension' => 'flac', 'status' => 'present']);
        \App\Models\TrackFile::forceCreate(['track_id' => $track->id, 'file_id' => $file->id, 'role' => 'original', 'is_primary' => true]);

        $device->driver->playLibraryTrack($track->fresh());

        Http::assertSent(fn (Request $r) => str_contains($r->body(), '/stream/'.$track->id.'.flac</CurrentURI>'));
    }

    public function test_the_queue_can_be_edited_while_something_plays(): void
    {
        $this->fakeRenderer();
        $device = $this->device();
        $tracks = [$this->track('one'), $this->track('two'), $this->track('three')];
        $extra = [$this->track('four'), $this->track('five')];
        $device->driver->playLibraryTracks(collect($tracks));
        DeviceCache::updateState($device->id, State::Playing);

        $this->postJson("/api/devices/{$device->id}/queue", ['type' => 'track', 'id' => $extra[0]->id])->assertOk();
        $this->postJson("/api/devices/{$device->id}/queue", ['type' => 'track', 'id' => $extra[1]->id, 'next' => true])->assertOk();
        $this->assertSame(['five', 'two', 'three', 'four'], array_map(fn ($i) => $i->name, $device->driver->getUpNext()));

        $this->putJson("/api/devices/{$device->id}/queue/4", ['to' => 1, 'expect' => 'http://dlna.test/four.flac'])->assertOk();
        $this->deleteJson("/api/devices/{$device->id}/queue/2?expect=http://dlna.test/five.flac")->assertOk();
        $this->assertSame(['four', 'two', 'three'], array_map(fn ($i) => $i->name, $device->driver->getUpNext()));

        $this->deleteJson("/api/devices/{$device->id}/queue/1?expect=http://dlna.test/wrong.flac")->assertStatus(422)->assertJsonPath('error', 'invalid');
        $this->assertCount(3, $device->driver->getUpNext());

        $this->deleteJson("/api/devices/{$device->id}/queue")->assertOk();
        $this->assertSame([], $device->driver->getUpNext());
        $this->assertSame($tracks[0]->id, DeviceQueue::for($device->id)->current());
    }

    public function test_adding_needs_something_playing_and_a_playable_track(): void
    {
        $this->fakeRenderer();
        $device = $this->device();
        $track = $this->track('one');
        DeviceCache::updateState($device->id, State::Standby);

        $this->postJson("/api/devices/{$device->id}/queue", ['type' => 'track', 'id' => $track->id])
            ->assertStatus(422)->assertJsonPath('error', 'invalid');

        $device->driver->playLibraryTracks(collect([$track]));
        Metadata::where('metadatable_id', $track->id)->delete();
        $this->postJson("/api/devices/{$device->id}/queue", ['type' => 'track', 'id' => $track->id])
            ->assertStatus(422)->assertJsonPath('error', 'invalid');
    }

    public function test_next_previous_and_jump_move_the_server_queue(): void
    {
        $this->fakeRenderer();
        $device = $this->device();
        $tracks = [$this->track('one'), $this->track('two'), $this->track('three')];
        $device->driver->playLibraryTracks(collect($tracks));

        $device->driver->next();
        $this->assertSame($tracks[1]->id, DeviceQueue::for($device->id)->current());

        $device->driver->skipToQueuePosition(1);
        $this->assertSame($tracks[2]->id, DeviceQueue::for($device->id)->current());

        $device->driver->previous();
        $this->assertSame($tracks[1]->id, DeviceQueue::for($device->id)->current());
        Http::assertSent(fn (Request $r) => str_contains($r->body(), 'http://dlna.test/two.flac'));
    }

    public function test_the_listener_starts_the_next_track_when_one_ends(): void
    {
        Event::fake([NowPlayingUpdated::class, NowPlayingEnded::class]);
        $this->fakeRenderer();
        $device = $this->device(['volume_control']);
        $tracks = [$this->track('one'), $this->track('two')];
        $device->driver->playLibraryTracks(collect($tracks));
        $listener = DeviceListener::forDevice($device->fresh());

        $this->renderer = ['state' => 'PLAYING', 'uri' => 'http://dlna.test/one.flac', 'meta' => '', 'duration' => '0:03:00', 'position' => '0:02:58', 'volume' => '40'];
        $listener->poll((string) $device->id);
        $this->assertSame(State::Playing, DeviceCache::getState($device->id));

        $this->renderer['state'] = 'STOPPED';
        $listener->poll((string) $device->id);

        $this->assertSame($tracks[1]->id, DeviceQueue::for($device->id)->current());
        $this->assertSame(2, count(array_keys($this->sent(), 'SetAVTransportURI')));
        Event::assertNotDispatched(NowPlayingEnded::class);
    }

    public function test_a_stop_in_the_middle_of_a_track_is_no_track_ending(): void
    {
        Event::fake([NowPlayingUpdated::class, NowPlayingEnded::class]);
        $this->fakeRenderer();
        $device = $this->device();
        $device->driver->playLibraryTracks(collect([$this->track('one'), $this->track('two')]));
        $listener = DeviceListener::forDevice($device->fresh());

        $this->renderer = ['state' => 'PLAYING', 'uri' => 'http://dlna.test/one.flac', 'meta' => '', 'duration' => '0:03:00', 'position' => '0:01:00', 'volume' => '40'];
        $listener->poll((string) $device->id);
        $device->driver->stop();
        $this->renderer['state'] = 'STOPPED';
        $listener->poll((string) $device->id);

        Event::assertDispatched(NowPlayingEnded::class);
        $this->assertSame(1, count(array_keys($this->sent(), 'SetAVTransportURI')));
        $this->assertSame(State::Standby, DeviceCache::getState($device->id));
    }

    public function test_near_the_end_a_gapless_renderer_gets_the_next_track_and_the_queue_follows_the_handover(): void
    {
        Event::fake([NowPlayingUpdated::class, NowPlayingEnded::class]);
        $this->fakeRenderer();
        $device = $this->device();
        $tracks = [$this->track('one'), $this->track('two')];
        $device->driver->playLibraryTracks(collect($tracks));
        $listener = DeviceListener::forDevice($device->fresh());

        $this->renderer = ['state' => 'PLAYING', 'uri' => 'http://dlna.test/one.flac', 'meta' => '', 'duration' => '0:03:00', 'position' => '0:02:55', 'volume' => '40'];
        $listener->poll((string) $device->id);
        $listener->poll((string) $device->id);

        $this->assertSame(1, count(array_keys($this->sent(), 'SetNextAVTransportURI')));

        $this->renderer['uri'] = 'http://dlna.test/two.flac';
        $this->renderer['position'] = '0:00:01';
        $listener->poll((string) $device->id);

        $this->assertSame($tracks[1]->id, DeviceQueue::for($device->id)->current());
        $this->assertSame($tracks[1]->id, MusicPlayerDriver::playing($device->id)['track_id']);
    }

    public function test_a_track_started_from_the_renderers_own_app_is_shown_but_has_no_queue(): void
    {
        Event::fake([NowPlayingUpdated::class]);
        $this->fakeRenderer();
        $device = $this->device();
        $device->driver->playLibraryTracks(collect([$this->track('one'), $this->track('two')]));
        $listener = DeviceListener::forDevice($device->fresh());

        $this->renderer = ['state' => 'PLAYING', 'uri' => 'http://elsewhere/song.mp3', 'meta' => DidlLite::build('http://elsewhere/song.mp3', 'Other', 'Them', null, null, null, 'audio/mpeg'), 'duration' => '0:03:00', 'position' => '0:00:10', 'volume' => '40'];
        $listener->poll((string) $device->id);

        Event::assertDispatched(NowPlayingUpdated::class, fn ($e) => $e->nowPlaying->track->name === 'Other');
        $this->assertSame([], $device->driver->getUpNext());
    }

    public function test_a_station_with_a_stream_url_plays_and_shows_the_icy_title_as_its_song(): void
    {
        Queue::fake();
        Event::fake([NowPlayingUpdated::class]);
        $this->fakeRenderer();
        $device = $this->device();
        $station = RadioStation::create(['name' => 'Radio Test', 'genre' => 'Pop']);
        $station->setMeta('url', 'http://stream.test/radio.mp3');

        $this->assertTrue($device->driver->canPlayRadioStation($station->fresh('meta')));
        $device->driver->playRadioStation($station->fresh('meta'));

        $this->assertSame(['Stop', 'SetAVTransportURI', 'Play'], $this->sent());
        Http::assertSent(fn (Request $r) => str_contains($r->body(), 'http://stream.test/radio.mp3</CurrentURI>'));
        Queue::assertPushed(PollRadioTitle::class);

        $listener = DeviceListener::forDevice($device->fresh());
        $this->renderer = ['state' => 'PLAYING', 'uri' => 'http://stream.test/radio.mp3', 'meta' => '', 'duration' => '0:00:00', 'position' => '0:00:10', 'volume' => '40'];

        // No title yet: the station is what plays.
        $listener->poll((string) $device->id);
        Event::assertDispatched(NowPlayingUpdated::class, fn ($e) => $e->nowPlaying->radio?->name === 'Radio Test' && $e->nowPlaying->track->name === 'Radio Test' && $e->nowPlaying->platform === 'radio');

        // The poll job found a song: it becomes the track, the station stays the radio.
        StreamTitles::put($station->id, 'Some Artist - Some Song');
        $listener->poll((string) $device->id);
        Event::assertDispatched(NowPlayingUpdated::class, fn ($e) => $e->nowPlaying->track->name === 'Some Song' && $e->nowPlaying->track->artist?->name === 'Some Artist' && $e->nowPlaying->radio?->name === 'Radio Test');
    }

    public function test_a_station_without_a_stream_url_is_not_playable_and_other_uris_are_not_radio(): void
    {
        Event::fake([NowPlayingUpdated::class]);
        $this->fakeRenderer();
        $device = $this->device();

        $this->assertFalse($device->driver->canPlayRadioStation(RadioStation::create(['name' => 'No URL'])->load('meta')));

        // Radio is remembered for the stream we started only: another URI is shown as the renderer reports it.
        Cache::put("upnp:{$device->id}:radio", ['station_id' => 999, 'uri' => 'http://stream.test/radio.mp3'], 60);
        $this->renderer = ['state' => 'PLAYING', 'uri' => 'http://elsewhere/song.mp3', 'meta' => DidlLite::build('http://elsewhere/song.mp3', 'Other', 'Them', null, null, null, 'audio/mpeg'), 'duration' => '0:03:00', 'position' => '0:00:10', 'volume' => '40'];
        DeviceListener::forDevice($device->fresh())->poll((string) $device->id);

        Event::assertDispatched(NowPlayingUpdated::class, fn ($e) => $e->nowPlaying->track->name === 'Other' && $e->nowPlaying->radio === null);
    }

    public function test_volume_is_scaled_to_the_renderers_range(): void
    {
        $this->fakeRenderer();
        $device = $this->device();
        DeviceMeta::create(['device_id' => $device->id, 'key' => 'upnp_volume_max', 'value' => '60']);

        $device->driver->setVolume(50);

        Http::assertSent(fn (Request $r) => str_contains($r->body(), '<DesiredVolume>30</DesiredVolume>'));
    }

    public function test_dedicated_drivers_claim_their_renderers_and_the_rest_is_generic(): void
    {
        $this->assertTrue(DedicatedDrivers::claims('Sonos, Inc.', 'Sonos Five'));
        $this->assertTrue(DedicatedDrivers::claims('Bang &amp; Olufsen', 'Beosound Balance'));
        $this->assertTrue(DedicatedDrivers::claims('Bang & Olufsen', 'BeoSound Essence'));
        $this->assertFalse(DedicatedDrivers::claims('WiiM', 'Mini'));
        $this->assertFalse(DedicatedDrivers::claims('Spotify', 'x'));
    }

    public function test_discovery_reports_only_audio_renderers_no_dedicated_driver_claims(): void
    {
        $descriptor = fn (string $maker, string $model, string $udn) => '<root xmlns="urn:schemas-upnp-org:device-1-0"><device><friendlyName>'.$model.'</friendlyName><manufacturer>'.$maker.'</manufacturer><modelName>'.$model.'</modelName><UDN>'.$udn.'</UDN><serviceList>'
            .'<service><serviceType>urn:schemas-upnp-org:service:AVTransport:1</serviceType><controlURL>/av</controlURL><SCPDURL>/av.xml</SCPDURL></service>'
            .'<service><serviceType>urn:schemas-upnp-org:service:ConnectionManager:1</serviceType><controlURL>/cm</controlURL></service></serviceList></device></root>';

        Http::fake([
            'http://10.0.0.1/*' => Http::response($descriptor('WiiM', 'Mini', 'uuid:wiim')),
            'http://10.0.0.2/*' => Http::response($descriptor('Sonos, Inc.', 'Sonos Five', 'uuid:sonos')),
            'http://10.0.0.3/desc' => Http::response($descriptor('Acme', 'TV', 'uuid:tv')),
            'http://10.0.0.3/cm' => Http::response($this->envelope('GetProtocolInfo', ['Sink' => 'http-get:*:video/mp4:*'])),
            'http://10.0.0.4/*' => Http::response($descriptor('Bang & Olufsen', 'Beolab 28', 'uuid:bo')),
            'http://10.0.0.1/cm' => Http::response($this->envelope('GetProtocolInfo', ['Sink' => 'http-get:*:audio/flac:*'])),
        ]);
        $ssdp = $this->partialMock(SsdpClient::class, fn ($mock) => $mock->shouldReceive('search')->andReturn(array_map(
            fn ($n) => ['ip' => "10.0.0.{$n}", 'location' => $n === 3 ? 'http://10.0.0.3/desc' : "http://10.0.0.{$n}/desc", 'st' => null, 'usn' => null],
            [1, 2, 3, 4],
        )));

        $found = (new UpnpDiscovery($ssdp))->discover();

        $this->assertCount(1, $found);
        $this->assertSame('WiiM', $found[0]->device_brand_name);
        $this->assertSame('UPnP', $found[0]->device_driver_name);
        $this->assertSame(MusicPlayerDriver::class, $found[0]->device_driver);
        $this->assertSame('uuid:wiim', $found[0]->meta['upnp_uuid']);
        $this->assertSame('http://10.0.0.1/av', $found[0]->meta['upnp_avtransport_url']);
    }

    public function test_a_stored_device_of_another_driver_is_never_taken_over(): void
    {
        $other = Device::factory()->driver('App\Fake\Driver', 'Fake')->create();
        DeviceMeta::create(['device_id' => $other->id, 'key' => 'upnp_uuid', 'value' => 'uuid:mine']);
        Http::fake(['*' => Http::response('<root xmlns="urn:schemas-upnp-org:device-1-0"><device><friendlyName>X</friendlyName><manufacturer>Acme</manufacturer><modelName>Y</modelName><UDN>uuid:mine</UDN><serviceList><service><serviceType>urn:schemas-upnp-org:service:AVTransport:1</serviceType><controlURL>/av</controlURL></service></serviceList></device></root>')]);
        $ssdp = $this->partialMock(SsdpClient::class, fn ($mock) => $mock->shouldReceive('search')->andReturn([['ip' => '10.0.0.9', 'location' => 'http://10.0.0.9/d', 'st' => null, 'usn' => null]]));

        $this->assertSame([], (new UpnpDiscovery($ssdp))->discover());
    }

    public function test_a_given_ip_is_found_by_unicast_when_multicast_gets_nowhere(): void
    {
        Http::fake(['*' => Http::response('<root xmlns="urn:schemas-upnp-org:device-1-0"><device><friendlyName>Study</friendlyName><manufacturer>Acme</manufacturer><modelName>Streamer</modelName><UDN>uuid:study</UDN><serviceList><service><serviceType>urn:schemas-upnp-org:service:AVTransport:1</serviceType><controlURL>/av</controlURL></service></serviceList></device></root>')]);
        $ssdp = $this->partialMock(SsdpClient::class, function ($mock) {
            $mock->shouldReceive('search')->andReturn([]);
            $mock->shouldReceive('searchHost')->with('10.0.0.50', 'urn:schemas-upnp-org:device:MediaRenderer:1')->andReturn([['ip' => '10.0.0.50', 'location' => 'http://10.0.0.50:49152/d', 'st' => null, 'usn' => null]]);
            $mock->shouldReceive('searchHost')->with('10.0.0.51', \Mockery::any())->andReturn([]);
        });

        $discovery = (new UpnpDiscovery($ssdp))->withHosts(['10.0.0.50', '10.0.0.51']);
        $found = $discovery->discover();

        $this->assertCount(1, $found);
        $this->assertSame('10.0.0.50', $found[0]->ip_address);
        $this->assertSame('http://10.0.0.50:49152/av', $found[0]->meta['upnp_avtransport_url']);
        $this->assertCount(2, $discovery->diagnostics());
        $this->assertStringContainsString('10.0.0.51 (given)', $discovery->diagnostics()[1]);
    }

    public function test_a_device_added_by_hand_finds_its_control_urls_on_first_use(): void
    {
        $device = Device::factory()->driver(MusicPlayerDriver::class, 'UPnP')->create(['ip_address' => '10.0.0.60', 'device_brand_name' => 'UPnP', 'device_product_type' => 'Generic MediaRenderer']);
        $descriptor = '<root xmlns="urn:schemas-upnp-org:device-1-0"><device><friendlyName>TV</friendlyName><manufacturer>Acme</manufacturer><modelName>TV</modelName><UDN>uuid:tv</UDN><serviceList><service><serviceType>urn:schemas-upnp-org:service:AVTransport:1</serviceType><controlURL>/av</controlURL></service></serviceList></device></root>';
        Http::fake(['http://10.0.0.60:7676/d' => Http::response($descriptor), '*' => Http::response($this->envelope('GetTransportInfo', ['CurrentTransportState' => 'STOPPED']))]);
        $this->partialMock(SsdpClient::class, fn ($mock) => $mock->shouldReceive('searchHost')->andReturn([['ip' => '10.0.0.60', 'location' => 'http://10.0.0.60:7676/d', 'st' => null, 'usn' => null]]));

        $this->assertSame('STOPPED', $device->driver->renderer()->transportState());
        $this->assertSame('http://10.0.0.60:7676/av', $device->meta()->where('key', 'upnp_avtransport_url')->value('value'));
    }

    public function test_a_device_nothing_answers_for_says_so(): void
    {
        $device = Device::factory()->driver(MusicPlayerDriver::class, 'UPnP')->create(['ip_address' => '10.0.0.61']);
        $this->partialMock(SsdpClient::class, fn ($mock) => $mock->shouldReceive('searchHost')->andReturn([]));

        $this->expectExceptionMessage('Nothing at 10.0.0.61 answers as a UPnP MediaRenderer');
        $device->driver->renderer();
    }
}
