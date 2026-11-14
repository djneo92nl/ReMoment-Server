<?php

namespace Tests\Feature;

use App\Domain\Alerts\Alerts;
use App\Domain\Media\Radio;
use App\Domain\Radio\Relay\IcecastStatus;
use App\Domain\Radio\Relay\RadioRelay;
use App\Domain\Radio\Relay\RelayManager;
use App\Domain\Radio\Relay\RelayStatus;
use App\Integrations\Upnp\MusicPlayerDriver;
use App\Models\Device;
use App\Models\RadioStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeRelayProcess;
use Tests\TestCase;

/** Relay operations (17.3, 17.4): titles on the mount, alerts, the status API, a restarted manager. */
class RadioRelayOperationsTest extends TestCase
{
    use RefreshDatabase;

    private int $metadataStatus = 200;

    private int $now = 1_800_000_000;

    /** @var FakeRelayProcess[] */
    private array $launched = [];

    /** @var Request[] */
    private array $metadata = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTimestamp($this->now));

        config([
            'radio_relay.enabled' => true,
            'radio_relay.max' => 4,
            'radio_relay.idle_seconds' => 600,
            'radio_relay.icecast.internal_url' => 'http://icecast:8000',
            'radio_relay.icecast.port' => 8000,
            'app.stream_url' => 'http://192.168.1.113:8080',
        ]);

        Http::fake([
            '*status-json.xsl' => Http::response(['icestats' => []]),
            '*admin/metadata*' => function (Request $request) {
                $this->metadata[] = $request;

                return Http::response('', $this->metadataStatus);
            },
        ]);
    }

    private function station(string $name = 'FIP'): RadioStation
    {
        $station = RadioStation::create(['name' => $name]);
        $station->setMeta('url', 'https://radio.example/live.aac');

        return $station;
    }

    private function manager(): RelayManager
    {
        return new RelayManager(
            fn (array $command) => $this->launched[] = new FakeRelayProcess($command),
            new IcecastStatus,
            '/usr/bin/ffmpeg',
            fn () => $this->now,
        );
    }

    private function advance(int $seconds): void
    {
        $this->now += $seconds;
        $this->travelTo(now()->setTimestamp($this->now));
    }

    public function test_a_title_is_pushed_to_the_mount_once_while_the_station_is_relayed(): void
    {
        $station = $this->station();

        $this->assertFalse(RadioRelay::pushTitle($station, 'Artist - Song'), 'not relayed, nothing to push');

        RadioRelay::want($station);

        $this->assertTrue(RadioRelay::pushTitle($station, 'Artist - Song'));
        $this->assertFalse(RadioRelay::pushTitle($station, 'Artist - Song'), 'same title, not again');
        $this->assertTrue(RadioRelay::pushTitle($station, 'Other - Tune'));

        $this->assertCount(2, $this->metadata);
        $this->assertStringContainsString("mount=%2Frelay-{$station->id}.mp3", $this->metadata[0]->url());
        $this->assertStringContainsString('mode=updinfo', $this->metadata[0]->url());
    }

    public function test_a_title_that_icecast_refused_is_tried_again(): void
    {
        $station = $this->station();
        RadioRelay::want($station);
        $this->metadataStatus = 400;

        $this->assertFalse(RadioRelay::pushTitle($station, 'A - B'));

        $this->metadataStatus = 200;
        $this->assertTrue(RadioRelay::pushTitle($station, 'A - B'));
    }

    public function test_a_station_that_keeps_failing_raises_an_alert_and_a_healthy_run_resolves_it(): void
    {
        $station = $this->station();
        $manager = $this->manager();
        $key = (string) $station->id;

        // Dies soon after every start: 3 in a row raises it.
        foreach ([3, 10, 30] as $i => $wait) {
            RadioRelay::want($station);
            $manager->tick();
            $this->assertCount($i + 1, $this->launched);
            $this->assertFalse(Alerts::isActive('radio_relay', $key), "after {$i} deaths");
            $this->launched[$i]->alive = false;
            $this->advance(2);
            $manager->tick();
            $this->advance($wait);
        }

        $this->assertTrue(Alerts::isActive('radio_relay', $key));
        $alert = \App\Models\Alert::where('kind', 'radio_relay')->first();
        $this->assertStringContainsString('FIP', $alert->title);
        $this->assertSame('warning', $alert->severity);

        // It comes up and stays up.
        RadioRelay::want($station);
        $manager->tick();
        $this->advance(31);
        RadioRelay::want($station);
        $manager->tick();

        $this->assertFalse(Alerts::isActive('radio_relay', $key));
    }

    public function test_the_manager_reports_running_and_restarting_relays(): void
    {
        $station = $this->station();
        $manager = $this->manager();

        RadioRelay::want($station);
        $manager->tick();
        $this->assertSame('running', $manager->status()[$station->id]['state']);

        $this->launched[0]->alive = false;
        $this->advance(1);
        $manager->tick();

        $status = $manager->status();
        $this->assertSame('restarting', $status[$station->id]['state']);
        $this->assertSame(1, $status[$station->id]['failures']);
        $this->assertSame($this->now + 3, $status[$station->id]['retry_at']);
    }

    public function test_the_api_lists_the_relays_and_says_when_the_manager_is_down(): void
    {
        $station = $this->station();

        $this->getJson('/api/radio/relays')->assertOk()->assertExactJson(['enabled' => true, 'manager' => false, 'max' => 4, 'data' => []]);

        $manager = $this->manager();
        RadioRelay::want($station);
        $manager->tick();

        $row = $this->getJson('/api/radio/relays')->assertOk()->assertJsonPath('manager', true)->json('data.0');
        $this->assertSame($station->id, $row['station_id']);
        $this->assertSame('FIP', $row['station']);
        $this->assertSame('running', $row['state']);
        $this->assertSame("relay-{$station->id}.mp3", $row['mount']);
        $this->assertSame("http://192.168.1.113:8000/relay-{$station->id}.mp3", $row['url']);

        $this->advance(RelayStatus::STALE_SECONDS + 1);
        $this->getJson('/api/radio/relays')->assertJsonPath('manager', false)->assertJsonPath('data', []);
    }

    public function test_a_restarted_manager_brings_the_wanted_stations_back(): void
    {
        $station = $this->station();
        RadioRelay::want($station);
        $this->manager()->tick();
        $this->assertCount(1, $this->launched);

        // The container restarts: the manager object is new, the demand is still in the cache.
        $this->advance(5);
        $fresh = $this->manager();
        $fresh->tick();

        $this->assertCount(2, $this->launched);
        $this->assertSame([$station->id], $fresh->running());
    }

    public function test_the_playing_card_knows_a_relayed_station(): void
    {
        $station = $this->station();
        $device = Device::factory()->create();

        $this->assertFalse(RadioRelay::playedVia($device, $station));

        Cache::put("upnp:{$device->id}:radio", ['station_id' => $station->id, 'uri' => RadioRelay::url($station)]);
        $this->assertTrue(RadioRelay::playedVia($device, $station));

        Cache::put("upnp:{$device->id}:radio", ['station_id' => $station->id, 'uri' => 'http://radio.example/direct']);
        $this->assertFalse(RadioRelay::playedVia($device, $station));

        $this->assertSame(['name' => 'FIP', 'images' => [], 'relay' => true], (new Radio(name: 'FIP', relay: true))->toArray());
        $this->assertArrayNotHasKey('relay', (new Radio(name: 'FIP'))->toArray());
        $this->assertNull(MusicPlayerDriver::radio(999));
    }
}
