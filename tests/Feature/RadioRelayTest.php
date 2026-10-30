<?php

namespace Tests\Feature;

use App\Domain\Radio\Relay\IcecastStatus;
use App\Domain\Radio\Relay\RadioRelay;
use App\Domain\Radio\Relay\RelayCommand;
use App\Domain\Radio\Relay\RelayManager;
use App\Domain\Radio\Relay\RelayProcess;
use App\Models\RadioStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A process that is "running" until the test says it ended. */
final class FakeRelayProcess implements RelayProcess
{
    public bool $alive = true;

    public bool $stopped = false;

    public function __construct(public readonly array $command) {}

    public function running(): bool
    {
        return $this->alive;
    }

    public function stop(): void
    {
        $this->alive = false;
        $this->stopped = true;
    }

    public function exitCode(): ?int
    {
        return $this->alive ? null : 1;
    }

    public function tail(): string
    {
        return 'Server returned 403 Forbidden';
    }
}

/** The radio relay: the ffmpeg command, Icecast status, the demand cache and the manager that starts and stops the relays. */
class RadioRelayTest extends TestCase
{
    use RefreshDatabase;

    /** @var FakeRelayProcess[] every process the manager started, in order */
    private array $launched = [];

    /** @var array<string, int> the mounts Icecast says are live, with their listeners */
    private array $mounts = [];

    private bool $icecastDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'radio_relay.enabled' => true,
            'radio_relay.bitrate' => 128,
            'radio_relay.max' => 4,
            'radio_relay.idle_seconds' => 60,
            'radio_relay.icecast.internal_url' => 'http://icecast:8000',
            'radio_relay.icecast.port' => 8000,
            'radio_relay.icecast.source_password' => 'secret',
            'app.stream_url' => 'http://192.168.1.113:8080',
        ]);

        // One fake, whose answer the tests change: a second Http::fake() would not replace it.
        Http::fake([
            '*status-json.xsl' => function () {
                if ($this->icecastDown) {
                    throw new \Illuminate\Http\Client\ConnectionException('refused');
                }

                $sources = collect($this->mounts)->map(fn ($listeners, $mount) => ['listenurl' => "http://icecast:8000/{$mount}", 'listeners' => $listeners])->values()->all();

                return Http::response(['icestats' => $sources === [] ? [] : ['source' => count($sources) === 1 ? $sources[0] : $sources]]);
            },
            '*admin/metadata*' => Http::response('', 200),
        ]);
    }

    private function station(string $name, ?string $url = 'http://radio.example/stream.mp3'): RadioStation
    {
        $station = RadioStation::create(['name' => $name]);
        $url && $station->setMeta('url', $url);

        return $station;
    }

    private function manager(array $pinned = []): RelayManager
    {
        return new RelayManager(
            function (array $command) {
                return $this->launched[] = new FakeRelayProcess($command);
            },
            new IcecastStatus,
            '/usr/bin/ffmpeg',
            fn () => now()->timestamp,
            $pinned,
        );
    }

    /** Icecast says these mounts are live, with these listener counts. */
    private function icecast(array $mounts): void
    {
        $this->mounts = $mounts;
    }

    public function test_the_command_converts_a_station_to_mp3_on_its_mount(): void
    {
        $command = RelayCommand::build('/usr/bin/ffmpeg', 'https://stream.example/live', 12);

        $this->assertSame('/usr/bin/ffmpeg', $command[0]);
        $this->assertSame('https://stream.example/live', $command[array_search('-i', $command) + 1]);
        $this->assertContains('libmp3lame', $command);
        $this->assertSame('128k', $command[array_search('-b:a', $command) + 1]);
        $this->assertSame('icecast://source:secret@icecast:8000/relay-12.mp3', end($command));
        $this->assertSame('relay-12.mp3', RelayCommand::mount(12));
    }

    public function test_the_source_password_is_url_encoded_in_the_target(): void
    {
        config(['radio_relay.icecast.source_password' => 'p@ss/word']);

        $command = RelayCommand::build('ffmpeg', 'http://x', 3);

        $this->assertSame('icecast://source:p%40ss%2Fword@icecast:8000/relay-3.mp3', end($command));
    }

    public function test_icecast_status_has_no_source_one_or_several(): void
    {
        $this->assertSame([], IcecastStatus::parse(['icestats' => []]));
        $this->assertSame(['relay-1.mp3' => ['listeners' => 2]], IcecastStatus::parse(['icestats' => ['source' => ['listenurl' => 'http://h:8000/relay-1.mp3', 'listeners' => 2]]]));
        $this->assertSame(['a.mp3' => ['listeners' => 0], 'b.mp3' => ['listeners' => 1]], IcecastStatus::parse(['icestats' => ['source' => [
            ['listenurl' => 'http://h:8000/a.mp3', 'listeners' => 0],
            ['listenurl' => 'http://h:8000/b.mp3', 'listeners' => 1],
        ]]]));
    }

    public function test_a_mount_is_live_with_a_source_and_unreachable_icecast_is_nothing_live(): void
    {
        $this->icecast(['relay-5.mp3' => 3]);

        $icecast = new IcecastStatus;
        $this->assertTrue($icecast->isLive('relay-5.mp3'));
        $this->assertFalse($icecast->isLive('relay-6.mp3'));
        $this->assertSame(3, $icecast->listeners('relay-5.mp3'));

        $this->icecastDown = true;
        $this->assertFalse((new IcecastStatus)->isLive('relay-5.mp3'));
    }

    public function test_the_title_goes_to_the_mount_with_the_admin_login(): void
    {
        $this->assertTrue((new IcecastStatus)->setTitle('relay-5.mp3', 'Artist - Song'));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/admin/metadata')
            && $r['mount'] === '/relay-5.mp3' && $r['mode'] === 'updinfo' && $r['song'] === 'Artist - Song'
            && str_starts_with($r->header('Authorization')[0] ?? '', 'Basic '));
    }

    public function test_the_renderer_address_is_the_stream_host_on_the_icecast_port(): void
    {
        $station = $this->station('NTS 1', 'https://x/y');

        $this->assertSame("http://192.168.1.113:8000/relay-{$station->id}.mp3", RadioRelay::url($station));
    }

    public function test_wanting_a_station_records_when_and_releasing_forgets_it(): void
    {
        $station = $this->station('NTS 1');

        RadioRelay::want($station);
        $this->assertSame([$station->id => now()->timestamp], RadioRelay::wanted());

        RadioRelay::release($station->id);
        $this->assertSame([], RadioRelay::wanted());
    }

    public function test_ensure_live_waits_for_the_mount_and_gives_up_when_it_never_comes(): void
    {
        $station = $this->station('NTS 1');

        $this->icecast(["relay-{$station->id}.mp3" => 0]);
        $this->assertTrue(RadioRelay::ensureLive($station, 1));
        $this->assertArrayHasKey($station->id, RadioRelay::wanted());

        $this->icecast([]);
        $this->assertFalse(RadioRelay::ensureLive($station, 0));

        config(['radio_relay.enabled' => false]);
        $this->assertFalse(RadioRelay::ensureLive($station, 0), 'a relay that is off is never waited for');
    }

    public function test_a_wanted_station_gets_one_ffmpeg_and_an_unwanted_one_none(): void
    {
        $wanted = $this->station('NTS 1', 'https://stream.example/live');
        $this->station('Other');
        $this->icecast([]);
        RadioRelay::want($wanted);

        $manager = $this->manager();
        $manager->tick();
        $manager->tick();

        $this->assertCount(1, $this->launched);
        $this->assertSame([$wanted->id], $manager->running());
        $this->assertSame('https://stream.example/live', $this->launched[0]->command[array_search('-i', $this->launched[0]->command) + 1]);
    }

    public function test_a_station_without_an_address_is_dropped_not_started(): void
    {
        $station = $this->station('No address', null);
        RadioRelay::want($station);
        $this->icecast([]);

        $manager = $this->manager();
        $manager->tick();

        $this->assertSame([], $this->launched);
        $this->assertSame([], RadioRelay::wanted());
    }

    public function test_pinned_stations_run_without_anyone_asking(): void
    {
        $station = $this->station('NTS 1');
        $this->icecast([]);

        $manager = $this->manager([$station->id]);
        $manager->tick();

        $this->assertSame([$station->id], $manager->running());
    }

    public function test_an_idle_relay_stops_but_one_with_listeners_or_demand_stays(): void
    {
        $station = $this->station('NTS 1');
        $this->icecast([]);
        RadioRelay::want($station);

        $manager = $this->manager();
        $manager->tick();
        $this->assertSame([$station->id], $manager->running());

        // Still asked for: stays.
        $this->travel(50)->seconds();
        RadioRelay::want($station);
        $manager->tick();
        $this->assertSame([$station->id], $manager->running());

        // Not asked for, but somebody listens: stays.
        $this->icecast(["relay-{$station->id}.mp3" => 1]);
        $this->travel(80)->seconds();
        $manager->tick();
        $this->assertSame([$station->id], $manager->running(), 'a listener keeps it');

        // Nobody asks and nobody listens for longer than the idle time: stops.
        $this->icecast(["relay-{$station->id}.mp3" => 0]);
        $this->travel(70)->seconds();
        $manager->tick();
        $this->assertSame([], $manager->running());
        $this->assertTrue($this->launched[0]->stopped);
    }

    public function test_a_dead_relay_restarts_after_a_growing_pause_and_a_healthy_one_resets_it(): void
    {
        $station = $this->station('NTS 1');
        $this->icecast([]);
        $manager = $this->manager([$station->id]);

        $manager->tick();
        $this->assertCount(1, $this->launched);

        // First death shortly after the start: wait 3 s.
        $this->launched[0]->alive = false;
        $manager->tick();
        $this->assertSame([], $manager->running());
        $this->travel(2)->seconds();
        $manager->tick();
        $this->assertCount(1, $this->launched, 'still waiting');
        $this->travel(2)->seconds();
        $manager->tick();
        $this->assertCount(2, $this->launched);

        // Second death in a row: wait 10 s.
        $this->launched[1]->alive = false;
        $manager->tick();
        $this->travel(5)->seconds();
        $manager->tick();
        $this->assertCount(2, $this->launched);
        $this->travel(6)->seconds();
        $manager->tick();
        $this->assertCount(3, $this->launched);

        // It ran for a good while, then died: the streak starts again at 3 s.
        $this->travel(60)->seconds();
        $this->launched[2]->alive = false;
        $manager->tick();
        $this->travel(4)->seconds();
        $manager->tick();
        $this->assertCount(4, $this->launched);
    }

    public function test_the_limit_makes_room_by_stopping_an_idle_relay_but_never_one_in_use(): void
    {
        config(['radio_relay.max' => 1]);
        $first = $this->station('First');
        $second = $this->station('Second');
        $this->icecast([]);

        RadioRelay::want($first);
        $manager = $this->manager();
        $manager->tick();
        $this->assertSame([$first->id], $manager->running());

        // A listener on the first: the second waits.
        $this->icecast(["relay-{$first->id}.mp3" => 2]);
        $this->travel(6)->seconds();
        RadioRelay::want($first);
        RadioRelay::want($second);
        $manager->tick();
        $this->assertSame([$first->id], $manager->running());

        // The listener leaves: the second takes its place.
        $this->icecast(["relay-{$first->id}.mp3" => 0]);
        $this->travel(6)->seconds();
        RadioRelay::want($second);
        $manager->tick();
        $this->assertSame([$second->id], $manager->running());
    }

    public function test_stopping_everything_ends_every_ffmpeg(): void
    {
        $a = $this->station('A');
        $b = $this->station('B');
        $this->icecast([]);

        $manager = $this->manager([$a->id, $b->id]);
        $manager->tick();
        $manager->stopAll();

        $this->assertSame([], $manager->running());
        $this->assertTrue($this->launched[0]->stopped && $this->launched[1]->stopped);
    }
}
