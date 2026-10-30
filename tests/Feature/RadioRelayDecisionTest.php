<?php

namespace Tests\Feature;

use App\Domain\Radio\Relay\RadioRelay;
use App\Domain\Radio\Relay\RelayDecision;
use App\Domain\Radio\Relay\StationProbe;
use App\Integrations\Philips\Streamium\MusicPlayerDriver;
use App\Integrations\Upnp\StreamProfile;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\RadioStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\CachesArtwork;
use Tests\TestCase;

/** Which renderers get which stations through the relay, and what a station really serves. */
class RadioRelayDecisionTest extends TestCase
{
    use CachesArtwork, RefreshDatabase;

    /** @var string[] the SetAVTransportURI bodies sent to the renderer */
    private array $sent = [];

    /** @var array<string, int> the relay mounts Icecast says are live */
    private array $mounts = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'radio_relay.enabled' => true,
            'radio_relay.live_wait_seconds' => 0,
            'radio_relay.icecast.internal_url' => 'http://icecast:8000',
            'radio_relay.icecast.port' => 8000,
            'app.stream_url' => 'http://192.168.1.113:8080',
        ]);

        Http::fake([
            '*status-json.xsl' => function () {
                $sources = collect($this->mounts)->map(fn ($l, $m) => ['listenurl' => "http://icecast:8000/{$m}", 'listeners' => $l])->values()->all();

                return Http::response(['icestats' => $sources === [] ? [] : ['source' => count($sources) === 1 ? $sources[0] : $sources]]);
            },
            'https://tls.example/*' => Http::response('', 200, ['Content-Type' => 'audio/mpeg']),
            'http://aac.example/*' => Http::response('', 200, ['Content-Type' => 'audio/aac']),
            'http://mp3.example/*' => Http::response('', 200, ['Content-Type' => 'audio/mpeg']),
            '*7123/UD/action*' => function (Request $r) {
                $action = trim(explode('#', $r->header('SOAPAction')[0] ?? '#')[1] ?? '', '"');
                $action === 'SetAVTransportURI' && $this->sent[] = html_entity_decode($r->body());

                return Http::response('<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><u:'.$action.'Response xmlns:u="x">'
                    .($action === 'GetTransportInfo' ? '<CurrentTransportState>NO_MEDIA_PRESENT</CurrentTransportState>' : '')
                    .'</u:'.$action.'Response></s:Body></s:Envelope>');
            },
        ]);
    }

    private function station(string $url): RadioStation
    {
        $station = RadioStation::create(['name' => 'Station']);
        $station->setMeta('url', $url);

        return $station;
    }

    private function player(?array $limits = null, ?string $mode = null): Device
    {
        $device = Device::factory()->driver(MusicPlayerDriver::class, 'Streamium')->create(['ip_address' => '10.0.0.80']);

        foreach (['upnp_avtransport_url' => 'http://10.0.0.80:7123/UD/action?2', 'upnp_capabilities' => '["seek","volume_control"]'] as $key => $value) {
            DeviceMeta::create(['device_id' => $device->id, 'key' => $key, 'value' => $value]);
        }

        $limits && StreamProfile::setLimits($device, $limits);
        $mode && RelayDecision::setMode($device, $mode);

        return $device;
    }

    public function test_codecs_and_playlists_are_told_from_the_content_type_or_the_extension(): void
    {
        $this->assertSame('mp3', StationProbe::codec('audio/mpeg', 'http://x/stream'));
        $this->assertSame('mp3', StationProbe::codec('', 'http://x/live.mp3'));
        $this->assertSame('aac', StationProbe::codec('audio/aacp; charset=x', 'http://x/stream'));
        $this->assertSame('aac', StationProbe::codec('', 'http://x/fip-hifi.aac'));
        $this->assertSame('hls', StationProbe::codec('application/vnd.apple.mpegurl', 'http://x/y'));
        $this->assertSame('hls', StationProbe::codec('', 'http://x/y/master.m3u8'));
        $this->assertSame('playlist', StationProbe::codec('audio/x-scpls', 'http://x/y'));
        $this->assertSame('playlist', StationProbe::codec('', 'http://x/listen.pls'));
        $this->assertSame('ogg', StationProbe::codec('audio/ogg', 'http://x/y'));
        $this->assertSame('unknown', StationProbe::codec('text/html', 'http://x/y'));
    }

    public function test_a_station_is_described_by_where_it_ends_up(): void
    {
        $facts = StationProbe::describe('http://old.example/live', 'https://new.example/live', 'audio/mpeg');

        $this->assertSame(['https', false, 'mp3', false], [$facts['scheme'], $facts['ip_host'], $facts['codec'], $facts['playlist']]);
        $this->assertTrue(StationProbe::describe('http://216.235.84.38/x.mp3', 'http://216.235.84.38/x.mp3', 'audio/mpeg')['ip_host']);
        $this->assertTrue(StationProbe::describe('http://x/y', 'http://x/y.m3u8', '')['playlist']);
    }

    public function test_probing_reads_the_content_type_and_a_dead_station_is_described_by_its_address(): void
    {
        $this->assertSame('aac', StationProbe::probe('http://aac.example/fip')['codec']);

        Http::fake(['http://dead.example/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('refused')]);
        $facts = StationProbe::probe('http://dead.example/live.mp3');

        $this->assertSame(['http', 'mp3'], [$facts['scheme'], $facts['codec']]);
    }

    public function test_a_device_without_limits_is_never_relayed_in_auto(): void
    {
        $this->assertFalse(RelayDecision::needed($this->player(), $this->station('https://tls.example/live')));
    }

    public function test_never_and_always_override_everything_and_a_relay_that_is_off_is_never_used(): void
    {
        $station = $this->station('http://mp3.example/live');

        $this->assertTrue(RelayDecision::needed($this->player(mode: 'always'), $station));
        $this->assertFalse(RelayDecision::needed($this->player(['no_tls' => true], 'never'), $this->station('https://tls.example/live')));

        config(['radio_relay.enabled' => false]);
        $this->assertFalse(RelayDecision::needed($this->player(mode: 'always'), $station));
    }

    public function test_auto_relays_what_the_limits_of_the_device_say_it_cannot_play(): void
    {
        $facts = fn (array $f) => fn () => $f + ['url' => 'x', 'final_url' => 'x', 'scheme' => 'http', 'ip_host' => false, 'codec' => 'mp3', 'playlist' => false];
        $station = $this->station('http://mp3.example/live');

        $noTls = $this->player(['no_tls' => true]);
        $this->assertTrue(RelayDecision::needed($noTls, $station, $facts(['scheme' => 'https'])), 'HTTPS on a device without TLS');
        $this->assertFalse(RelayDecision::needed($noTls, $station, $facts(['scheme' => 'http'])), 'plain HTTP plays');

        $noAac = $this->player(['deny_codecs' => ['aac']]);
        $this->assertTrue(RelayDecision::needed($noAac, $station, $facts(['codec' => 'aac'])));
        $this->assertFalse(RelayDecision::needed($noAac, $station, $facts(['codec' => 'mp3'])));

        $noPlaylists = $this->player(['no_playlists' => true]);
        $this->assertTrue(RelayDecision::needed($noPlaylists, $station, $facts(['playlist' => true])));

        $noDns = $this->player(['no_dns' => true]);
        $this->assertTrue(RelayDecision::needed($noDns, $station, $facts(['ip_host' => false])), 'every name fails');
        $this->assertFalse(RelayDecision::needed($noDns, $station, $facts(['ip_host' => true])), 'an address needs no lookup');
    }

    public function test_a_relayed_station_is_played_from_the_relay_and_remembered_as_that(): void
    {
        $station = $this->station('https://tls.example/live');
        $this->mounts = ["relay-{$station->id}.mp3" => 0];
        $device = $this->player(mode: 'always');

        $device->driver->playRadioStation($station);

        $relay = "http://192.168.1.113:8000/relay-{$station->id}.mp3";
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString($relay, $this->sent[0]);
        $this->assertStringNotContainsString('tls.example', $this->sent[0]);
        $this->assertStringContainsString('audio/mpeg', $this->sent[0]);
        $this->assertSame($relay, \App\Integrations\Upnp\MusicPlayerDriver::radio($device->id)['uri'], 'the listener recognises the station by what the renderer reports');
        $this->assertArrayHasKey($station->id, RadioRelay::wanted());
    }

    public function test_a_relay_that_does_not_come_up_falls_back_to_the_stations_own_address(): void
    {
        $station = $this->station('http://mp3.example/live');
        $this->mounts = [];
        $device = $this->player(mode: 'always');

        $device->driver->playRadioStation($station);

        $this->assertStringContainsString('http://mp3.example/live', $this->sent[0]);
        $this->assertSame('http://mp3.example/live', \App\Integrations\Upnp\MusicPlayerDriver::radio($device->id)['uri']);
    }

    public function test_a_station_the_device_can_play_itself_goes_direct(): void
    {
        $station = $this->station('http://mp3.example/live');
        $this->mounts = ["relay-{$station->id}.mp3" => 0];
        $device = $this->player(['no_tls' => true, 'deny_codecs' => ['aac']], 'auto');

        $device->driver->playRadioStation($station);

        $this->assertStringContainsString('http://mp3.example/live', $this->sent[0]);
        $this->assertSame([], RadioRelay::wanted(), 'nothing asked the relay');
    }

    public function test_an_aac_station_is_relayed_for_a_device_that_denies_aac(): void
    {
        $station = $this->station('http://aac.example/fip');
        $this->mounts = ["relay-{$station->id}.mp3" => 0];
        $device = $this->player(['deny_codecs' => ['aac']], 'auto');

        $device->driver->playRadioStation($station);

        $this->assertStringContainsString("relay-{$station->id}.mp3", $this->sent[0]);
    }

    public function test_the_logo_of_the_station_is_the_art_the_renderer_shows(): void
    {
        $station = $this->station('https://tls.example/live');
        $station->update(['image_url' => 'https://img.example/logo.png']);
        $this->cacheProcessedArtwork('https://img.example/logo.png', ['proxy_320' => 'http://remomentserver.test/storage/artwork/abc/320.jpg']);
        $this->mounts = ["relay-{$station->id}.mp3" => 0];

        $this->player(mode: 'always')->driver->playRadioStation($station);

        // The address of the server as the renderer reaches it, not the name only the browser knows.
        $this->assertStringContainsString('<upnp:albumArtURI>http://192.168.1.113:8080/storage/artwork/abc/320.jpg</upnp:albumArtURI>', $this->sent[0]);
    }
}
