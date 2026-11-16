<?php

namespace Tests\Feature;

use App\Domain\Device\State;
use App\Domain\LineIn\LineIns;
use App\Models\Client;
use App\Models\Device;
use App\Models\NodeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeStreamDriver;
use Tests\TestCase;

/** Line-in on a node as a stream source (18.2): API, jobs, idle stop. No audio, no real node, no Icecast. */
class LineInTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> what the fake Icecast reports */
    private array $live = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['icecast.test:8000/status-json.xsl' => fn () => Http::response(['icestats' => $this->live])]);

        FakeStreamDriver::$played = [];
        Cache::flush();
        config([
            'app.stream_url' => 'http://192.168.1.48:8080',
            'radio_relay.icecast.port' => 8123,
            'radio_relay.icecast.internal_url' => 'http://icecast.test:8000',
            'radio_relay.icecast.source_password' => 'src-pw',
            'line_in.live_wait_seconds' => 0,
            'line_in.idle_seconds' => 120,
        ]);
    }

    private function node(array $roles = ['line-in'], array $enabled = ['line-in'], array $extra = []): Client
    {
        return Client::create($extra + [
            'name' => 'Hi-fi Pi', 'kind' => 'node', 'status' => 'approved', 'hardware_id' => 'n'.uniqid(), 'registration_token' => Client::generateToken(),
            'api_token' => Client::generateToken(), 'roles' => $roles, 'enabled_roles' => $enabled, 'last_seen_at' => now(),
            'capabilities' => ['line-in' => ['inputs' => [['id' => 'hw:1,0', 'label' => 'USB audio']]]],
        ]);
    }

    /** Icecast's status: the given mount live with this many listeners (null = nothing live). */
    private function icecast(?string $mount, int $listeners = 0): void
    {
        $this->live = $mount === null ? [] : ['source' => ['listenurl' => "http://x:8123/{$mount}", 'listeners' => $listeners]];
    }

    private function speaker(): Device
    {
        return Device::factory()->driver(FakeStreamDriver::class)->inState(State::Standby)->create();
    }

    public function test_the_list_has_one_line_in_per_node_with_the_role_on(): void
    {
        $node = $this->node();
        $this->node(['line-in'], []);          // declared, not switched on
        $this->node(['ripper'], ['ripper']);   // other role
        $this->icecast("linein-{$node->id}.mp3", 2);

        $this->getJson('/api/line-ins')->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [[
            'id' => $node->id, 'name' => 'Line-in on Hi-fi Pi', 'title' => 'Line-in', 'mount' => "linein-{$node->id}.mp3",
            'url' => "http://192.168.1.48:8123/linein-{$node->id}.mp3", 'live' => true, 'listeners' => 2, 'node_online' => true,
            'inputs' => [['id' => 'hw:1,0', 'label' => 'USB audio']],
        ]]]);
    }

    public function test_playing_it_on_a_stream_device_starts_the_node_once_and_plays_the_mount(): void
    {
        $node = $this->node();
        $device = $this->speaker();
        $this->icecast(null);

        // not live in time: a start job exists, the speaker was not told
        $this->postJson("/api/devices/{$device->id}/line-in")->assertStatus(502)->assertJson(['error' => 'line_in_not_live']);
        $this->assertSame([], FakeStreamDriver::$played);
        $this->postJson("/api/devices/{$device->id}/line-in")->assertStatus(502);
        $jobs = NodeJob::where('client_id', $node->id)->where('type', 'line_in.start')->get();
        $this->assertCount(1, $jobs, 'one encode per line-in: the open start job is reused');
        $this->assertSame(['mount' => "linein-{$node->id}.mp3"], $jobs[0]->params);

        $this->icecast("linein-{$node->id}.mp3", 0);
        $this->postJson("/api/devices/{$device->id}/line-in")->assertOk()
            ->assertJson(['status' => 'ok', 'node_id' => $node->id, 'title' => 'Line-in', 'url' => "http://192.168.1.48:8123/linein-{$node->id}.mp3"]);
        $this->assertSame("http://192.168.1.48:8123/linein-{$node->id}.mp3", FakeStreamDriver::$played[0][0]);
        $this->assertSame('Line-in', FakeStreamDriver::$played[0][1]);
        $this->assertSame([$device->id], LineIns::describe($node)['device_ids']);
    }

    public function test_a_live_mount_needs_no_job(): void
    {
        $node = $this->node();
        $this->icecast("linein-{$node->id}.mp3");

        $this->postJson("/api/devices/{$this->speaker()->id}/line-in")->assertOk();
        $this->assertSame(0, NodeJob::count());
    }

    public function test_refusals(): void
    {
        $device = $this->speaker();
        $this->icecast(null);

        $this->postJson("/api/devices/{$device->id}/line-in")->assertStatus(422)->assertJson(['error' => 'unsupported']);

        $a = $this->node();
        $b = $this->node();
        $this->postJson("/api/devices/{$device->id}/line-in")->assertStatus(422)->assertJson(['error' => 'invalid']);
        $this->postJson("/api/devices/{$device->id}/line-in", ['node_id' => 9999])->assertStatus(422);

        $a->update(['last_seen_at' => now()->subHour()]);
        $this->postJson("/api/devices/{$device->id}/line-in", ['node_id' => $a->id])->assertStatus(503)->assertJson(['error' => 'node_offline']);

        $plain = Device::factory()->inState(State::Standby)->create();
        $this->postJson("/api/devices/{$plain->id}/line-in", ['node_id' => $b->id])->assertStatus(422)->assertJson(['error' => 'unsupported']);
    }

    public function test_the_node_gets_the_icecast_credentials_by_rest_only_and_they_are_never_stored(): void
    {
        $node = $this->node();
        $job = \App\Domain\Node\NodeJobs::create($node, 'line_in.start', ['mount' => "linein-{$node->id}.mp3"]);

        $this->getJson('/api/nodes/job', ['Authorization' => 'Bearer '.$node->api_token])->assertOk()
            ->assertJsonPath('job.type', 'line_in.start')->assertJsonPath('job.params.icecast_port', 8123)
            ->assertJsonPath('job.params.source_password', 'src-pw')->assertJsonPath('job.params.mount', "linein-{$node->id}.mp3");
        $this->assertArrayNotHasKey('source_password', $job->fresh()->params);
        $this->assertStringNotContainsString('src-pw', json_encode($job->fresh()->toPayload()));
    }

    public function test_the_job_types_are_validated_and_need_the_role(): void
    {
        $node = $this->node();
        foreach ([['mount' => '../x.mp3'], ['mount' => 'relay-1.mp3'], []] as $params) {
            try {
                \App\Domain\Node\NodeJobs::create($node, 'line_in.start', $params);
                $this->fail('bad mount accepted');
            } catch (\Illuminate\Validation\ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $without = $this->node(['line-in'], []);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        \App\Domain\Node\NodeJobs::create($without, 'line_in.stop');
    }

    public function test_the_sweep_stops_a_line_in_nobody_listens_to_and_keeps_a_listened_one(): void
    {
        $node = $this->node();
        LineIns::addTarget($node, 1);
        $mount = "linein-{$node->id}.mp3";

        $this->icecast($mount, 1);
        $this->assertSame([], LineIns::sweep());

        $this->icecast($mount, 0);
        $this->assertSame([], LineIns::sweep(), 'idle just started');
        $this->travel(119)->seconds();
        $this->assertSame([], LineIns::sweep());
        $this->icecast($mount, 1);
        LineIns::sweep();                      // a listener came back: the idle clock resets
        $this->icecast($mount, 0);
        $this->assertSame([], LineIns::sweep());      // the idle clock starts again here
        $this->travel(119)->seconds();
        $this->assertSame([], LineIns::sweep());
        $this->travel(2)->seconds();
        $this->assertSame([$node->id], LineIns::sweep());

        $stop = NodeJob::where('client_id', $node->id)->where('type', 'line_in.stop')->get();
        $this->assertCount(1, $stop);
        $this->assertFalse(LineIns::anyWanted());
    }

    public function test_the_sweep_gives_up_on_a_mount_that_never_came_up(): void
    {
        $node = $this->node();
        LineIns::addTarget($node, 1);
        $this->icecast(null);

        $this->assertSame([], LineIns::sweep());
        $this->travel(121)->seconds();
        $this->assertSame([$node->id], LineIns::sweep());
    }

    public function test_the_mcp_tool_goes_through_the_api(): void
    {
        $node = $this->node();
        $this->icecast("linein-{$node->id}.mp3");
        $device = $this->speaker();

        [$status, $body] = app(\App\Mcp\ApiBridge::class)->call('GET', 'line-ins');
        $this->assertSame(200, $status);
        $this->assertSame($node->id, $body['data'][0]['id']);

        [$status] = app(\App\Mcp\ApiBridge::class)->call('POST', "devices/{$device->id}/line-in", ['node_id' => $node->id]);
        $this->assertSame(200, $status);
        $this->assertCount(1, FakeStreamDriver::$played);
        $this->assertContains(\App\Mcp\Tools\LineIn::class, (new \ReflectionProperty(\App\Mcp\Servers\ReMomentServer::class, 'tools'))->getDefaultValue());
    }

    public function test_the_device_page_offers_the_line_in_only_to_stream_devices(): void
    {
        $this->node();
        $this->icecast(null);

        $this->get('/devices/'.$this->speaker()->id)->assertOk()->assertSee('Line-in on Hi-fi Pi')->assertSee('2 to 5 seconds');
        $this->get('/devices/'.Device::factory()->inState(State::Standby)->create()->id)->assertOk()->assertDontSee('Line-in on Hi-fi Pi');
    }
}
