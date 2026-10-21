<?php

namespace Tests\Feature;

use App\Domain\Device\State;
use App\Domain\Spotify\LibrespotEvents;
use App\Domain\Spotify\LibrespotStream;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Integrations\Upnp\DidlLite;
use App\Integrations\Upnp\MusicPlayerDriver;
use App\Integrations\Upnp\Services\DeviceListener;
use App\Models\Device;
use App\Models\DeviceMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeItemsDriver;
use Tests\TestCase;

/** The Spotify stream as one item per track: its own title and cover for a renderer, queued gaplessly behind the last. */
class SpotifyTrackItemsTest extends TestCase
{
    use RefreshDatabase;

    private const STREAM = 'http://192.168.1.48:8123/spotify.mp3';

    private const ITEM_1 = 'http://192.168.1.48:8010/tracks/1.mp3';

    private const ITEM_2 = 'http://192.168.1.48:8010/tracks/2.mp3';

    /** What the faked renderer answers, changed by a test between calls. */
    private array $renderer = ['state' => 'PLAYING', 'uri' => self::STREAM, 'meta' => ''];

    protected function setUp(): void
    {
        parent::setUp();

        FakeItemsDriver::$items = [];
        FakeItemsDriver::$playsStream = true;
        FakeItemsDriver::$throw = false;
        config([
            'librespot.enabled' => true,
            'librespot.name' => 'Test Receiver',
            'librespot.event_secret' => 's3cret',
            'librespot.icecast.port' => 8123,
            'librespot.icecast.mount' => 'spotify.mp3',
            'librespot.tracks.enabled' => true,
            'librespot.tracks.port' => 8010,
            'app.stream_url' => 'http://192.168.1.48:8080',
        ]);
    }

    private function fakeRenderer(): void
    {
        Http::fake(function (Request $request) {
            $action = trim(explode('#', $request->header('SOAPAction')[0] ?? '#')[1] ?? '', '"');
            $out = match ($action) {
                'GetTransportInfo' => ['CurrentTransportState' => $this->renderer['state']],
                'GetPositionInfo' => ['TrackURI' => $this->renderer['uri'], 'TrackMetaData' => $this->renderer['meta'], 'TrackDuration' => '0:03:00', 'RelTime' => '0:00:10'],
                'GetVolume' => ['CurrentVolume' => '40'],
                'GetMute' => ['CurrentMute' => '0'],
                default => [],
            };
            $body = '';
            foreach ($out as $k => $v) {
                $body .= "<{$k}>".htmlspecialchars((string) $v, ENT_XML1)."</{$k}>";
            }

            return Http::response('<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
                ."<u:{$action}Response xmlns:u=\"urn:schemas-upnp-org:service:AVTransport:1\">{$body}</u:{$action}Response></s:Body></s:Envelope>");
        });
    }

    /** @return string[] the SOAP actions sent so far, in order */
    private function sent(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => trim(explode('#', $pair[0]->header('SOAPAction')[0] ?? '#')[1] ?? '', '"'))->reject(fn ($a) => str_starts_with($a, 'Get'))->values()->all();
    }

    private function renderer(): Device
    {
        $device = Device::factory()->driver(MusicPlayerDriver::class, 'UPnP')->create(['ip_address' => '10.0.0.30']);

        foreach (['upnp_avtransport_url' => 'http://10.0.0.30/av', 'upnp_renderingcontrol_url' => 'http://10.0.0.30/rc', 'upnp_capabilities' => json_encode(['gapless'])] as $key => $value) {
            DeviceMeta::create(['device_id' => $device->id, 'key' => $key, 'value' => $value]);
        }

        return $device;
    }

    private function item(Device $device, int $n, string $title = 'Sunset'): void
    {
        $device->driver->playSpotifyItem(LibrespotStream::trackUrl($n), $title, 'The Midnight', 'Endless Summer', LibrespotStream::coverUrl($n), 327);
    }

    public function test_the_urls_use_the_servers_host_on_the_tracks_port(): void
    {
        $this->assertSame(self::ITEM_1, LibrespotStream::trackUrl(1));
        $this->assertSame('http://192.168.1.48:8010/tracks/1.jpg', LibrespotStream::coverUrl(1));
        $this->assertTrue(LibrespotStream::isTrackUrl('http://10.0.0.5:8010/tracks/9.mp3'));
        $this->assertFalse(LibrespotStream::isTrackUrl('http://10.0.0.5:8123/spotify.mp3'));
        $this->assertTrue(LibrespotStream::isStreamUrl('http://10.0.0.5:8123/spotify.mp3'));
    }

    public function test_the_first_track_replaces_the_silent_stream_with_its_own_item_carrying_title_and_cover(): void
    {
        $this->fakeRenderer();
        $device = $this->renderer(); // plays the endless stream, the silence before the first track

        $this->assertTrue($device->driver->playsSpotifyStream());
        $this->item($device, 1);

        $this->assertSame(['Stop', 'SetAVTransportURI', 'Play'], $this->sent());
        Http::assertSent(function (Request $r) {
            if (!str_contains($r->body(), '<CurrentURI>'.self::ITEM_1.'</CurrentURI>')) {
                return false;
            }
            preg_match('#<CurrentURIMetaData>(.*?)</CurrentURIMetaData>#s', $r->body(), $m);
            $didl = DidlLite::parse(html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_XML1));

            return $didl['title'] === 'Sunset' && $didl['artist'] === 'The Midnight' && $didl['album'] === 'Endless Summer'
                && $didl['art'] === 'http://192.168.1.48:8010/tracks/1.jpg';
        });
        $this->assertSame(self::ITEM_1, MusicPlayerDriver::spotifyItem($device->id)['uri']);
    }

    public function test_the_next_track_is_queued_behind_the_playing_one(): void
    {
        $this->fakeRenderer();
        $device = $this->renderer();
        $this->item($device, 1);

        $this->renderer['uri'] = self::ITEM_1;
        $this->item($device, 2, 'Days Go By');

        $this->assertSame(['Stop', 'SetAVTransportURI', 'Play', 'SetNextAVTransportURI'], $this->sent());
        $this->assertSame(self::ITEM_2, MusicPlayerDriver::spotifyItem($device->id)['next']['uri']);
        $this->assertSame(self::ITEM_1, MusicPlayerDriver::spotifyItem($device->id)['uri'], 'still the playing one until the renderer moves on');
    }

    public function test_the_listener_promotes_the_queued_item_when_the_renderer_moves_on(): void
    {
        Event::fake([NowPlayingUpdated::class]);
        $this->fakeRenderer();
        $device = $this->renderer();
        $this->item($device, 1);
        $this->renderer['uri'] = self::ITEM_1;
        $this->item($device, 2, 'Days Go By');
        $listener = DeviceListener::forDevice($device->fresh());

        // The renderer reaches the end of item 1 and plays item 2 by itself, with the metadata we handed over.
        $this->renderer['uri'] = self::ITEM_2;
        $this->renderer['meta'] = DidlLite::build(self::ITEM_2, 'Days Go By', 'The Midnight', 'Endless Summer', 'http://192.168.1.48:8010/tracks/2.jpg', 327, 'audio/mpeg');
        $listener->poll((string) $device->id);

        $item = MusicPlayerDriver::spotifyItem($device->id);
        $this->assertSame(self::ITEM_2, $item['uri']);
        $this->assertNull($item['next']);
        Event::assertDispatched(NowPlayingUpdated::class, fn ($e) => $e->nowPlaying->track->name === 'Days Go By' && $e->nowPlaying->track->artist?->name === 'The Midnight');
    }

    public function test_a_renderer_that_stops_at_the_end_of_the_track_gets_the_queued_item_started(): void
    {
        Event::fake([NowPlayingUpdated::class, NowPlayingEnded::class]);
        $this->fakeRenderer();
        $device = $this->renderer();
        $this->item($device, 1);
        $this->renderer['uri'] = self::ITEM_1;
        $this->item($device, 2, 'Days Go By');
        $listener = DeviceListener::forDevice($device->fresh());
        Http::fake(); // forget the calls so far, answer everything with nothing
        $this->fakeRenderer();

        $this->renderer['state'] = 'STOPPED'; // item 1 ended, the renderer ignored the next-item hint
        $listener->poll((string) $device->id);

        $this->assertSame(['Stop', 'SetAVTransportURI', 'Play'], $this->sent()); // the same start as any other item
        Http::assertSent(fn (Request $r) => str_contains($r->body(), '<CurrentURI>'.self::ITEM_2.'</CurrentURI>'));
        Event::assertNotDispatched(NowPlayingEnded::class);
        $this->assertSame(self::ITEM_2, MusicPlayerDriver::spotifyItem($device->id)['uri']);
    }

    public function test_a_stop_from_us_does_not_start_the_queued_item(): void
    {
        Event::fake([NowPlayingUpdated::class, NowPlayingEnded::class]);
        $this->fakeRenderer();
        $device = $this->renderer();
        $this->item($device, 1);
        $this->renderer['uri'] = self::ITEM_1;
        $this->item($device, 2);
        $listener = DeviceListener::forDevice($device->fresh());
        $listener->poll((string) $device->id); // sees item 1 play

        $device->driver->stop();
        $this->renderer['state'] = 'STOPPED';
        $listener->poll((string) $device->id);

        Event::assertDispatched(NowPlayingEnded::class);
        $this->assertNull(MusicPlayerDriver::spotifyItem($device->id));
    }

    public function test_a_renderer_playing_something_else_is_not_taken_over(): void
    {
        $this->fakeRenderer();
        $device = $this->renderer();

        $this->renderer['uri'] = 'http://dlna.test/song.flac';
        $this->assertFalse($device->driver->playsSpotifyStream());

        $this->renderer = ['state' => 'STOPPED', 'uri' => self::STREAM, 'meta' => ''];
        $this->assertFalse($device->driver->playsSpotifyStream(), 'stopped by the user');

        $this->renderer = ['state' => 'PAUSED_PLAYBACK', 'uri' => self::ITEM_1, 'meta' => ''];
        $this->assertFalse($device->driver->playsSpotifyStream(), 'paused on the speaker');
    }

    // --- the hook ---

    private function hook(array $fields)
    {
        return $this->postJson('/api/librespot/event', $fields, ['X-Librespot-Secret' => 's3cret']);
    }

    private function itemsDevice(): Device
    {
        $device = Device::factory()->driver(FakeItemsDriver::class)->inState(State::Playing)->create();
        LibrespotEvents::setTarget($device->id);

        return $device;
    }

    public function test_a_track_change_with_an_item_hands_the_item_to_the_speaker_playing_the_stream(): void
    {
        Http::fake();
        $this->itemsDevice();

        $this->hook(['event' => 'track_changed', 'name' => 'Sunset', 'artists' => "The Midnight\nGuest", 'album' => 'Endless Summer', 'duration_ms' => '327000', 'item' => '5'])->assertOk();

        $this->assertSame([[LibrespotStream::trackUrl(5), 'Sunset', 'The Midnight', 'Endless Summer', LibrespotStream::coverUrl(5), 327]], FakeItemsDriver::$items);
    }

    public function test_nothing_is_handed_over_when_off_without_an_item_or_when_the_speaker_plays_something_else(): void
    {
        Http::fake();
        $this->itemsDevice();
        $event = ['event' => 'track_changed', 'name' => 'Sunset', 'item' => '5'];

        config(['librespot.tracks.enabled' => false]);
        $this->hook($event)->assertOk();

        config(['librespot.tracks.enabled' => true]);
        $this->hook(['event' => 'track_changed', 'name' => 'Sunset', 'item' => ''])->assertOk();
        $this->hook(['event' => 'playing', 'item' => '5'])->assertOk();

        FakeItemsDriver::$playsStream = false;
        $this->hook($event)->assertOk();

        $this->assertSame([], FakeItemsDriver::$items);
    }

    public function test_a_speaker_that_fails_does_not_fail_the_hook(): void
    {
        Http::fake();
        $this->itemsDevice();
        FakeItemsDriver::$throw = true;

        $this->hook(['event' => 'track_changed', 'name' => 'Sunset', 'item' => '5'])->assertOk();
    }
}
