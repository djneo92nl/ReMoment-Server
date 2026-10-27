<?php

namespace Tests\Feature;

use App\Domain\Beolink\BeolinkStream;
use App\Domain\Device\State;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeStreamDriver;
use Tests\TestCase;

/** The BeoLink stream: its URL, playing it on a device, and the receiver joining/leaving a B&O source. */
class BeolinkStreamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeStreamDriver::$played = [];
        Cache::flush();
        config([
            'beolink.enabled' => true,
            'beolink.name' => 'Test Stream',
            'beolink.api_url' => 'http://beolink.test:8081',
            'beolink.icecast.port' => 8123,
            'beolink.icecast.mount' => 'beolink.mp3',
            'app.stream_url' => 'http://192.168.1.48:8080',
        ]);
    }

    private function host(?string $jid = '3150.1200322.99000001@products.bang-olufsen.com'): Device
    {
        $host = Device::factory()->inState(State::Playing)->create();

        if ($jid) {
            $host->meta()->create(['key' => 'ase_jid', 'value' => $jid]);
        }

        return $host;
    }

    public function test_the_stream_url_is_the_servers_host_on_icecasts_port(): void
    {
        $this->assertSame('http://192.168.1.48:8123/beolink.mp3', BeolinkStream::url());
        $this->getJson('/api/beolink-stream')->assertOk()->assertJson([
            'enabled' => true, 'name' => 'Test Stream', 'url' => 'http://192.168.1.48:8123/beolink.mp3',
            'host_device_id' => null, 'target_device_ids' => [],
        ]);
    }

    public function test_the_info_is_empty_while_the_stream_is_off(): void
    {
        config(['beolink.enabled' => false]);

        $this->getJson('/api/beolink-stream')->assertOk()->assertJson(['enabled' => false, 'url' => null]);
    }

    public function test_playing_on_a_device_makes_the_receiver_join_the_host_and_remembers_the_target(): void
    {
        Http::fake(['beolink.test:8081/*' => Http::response(['requestID' => 'x'], 202)]);
        $host = $this->host();
        $target = Device::factory()->driver(FakeStreamDriver::class)->inState(State::Standby)->create();

        $this->postJson("/api/devices/{$target->id}/beolink-stream", ['host_device_id' => $host->id])
            ->assertOk()->assertJson(['status' => 'ok', 'url' => 'http://192.168.1.48:8123/beolink.mp3',
                'host_device_id' => $host->id, 'target_device_ids' => [$target->id]]);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'http://beolink.test:8081/api/v1/beolink/join/3150.1200322.99000001%40products.bang-olufsen.com');
        $this->assertSame('http://192.168.1.48:8123/beolink.mp3', FakeStreamDriver::$played[0][0]);
        $this->assertSame('BeoLink (Test Stream)', FakeStreamDriver::$played[0][1]);
    }

    public function test_a_second_device_does_not_make_the_receiver_join_again(): void
    {
        Http::fake(['beolink.test:8081/*' => Http::response([], 202)]);
        $host = $this->host();
        $a = Device::factory()->driver(FakeStreamDriver::class)->inState(State::Standby)->create();
        $b = Device::factory()->driver(FakeStreamDriver::class)->inState(State::Standby)->create();

        $this->postJson("/api/devices/{$a->id}/beolink-stream", ['host_device_id' => $host->id])->assertOk();
        $this->postJson("/api/devices/{$b->id}/beolink-stream", ['host_device_id' => $host->id])
            ->assertOk()->assertJson(['target_device_ids' => [$a->id, $b->id]]);

        Http::assertSentCount(1);
    }

    public function test_a_host_whose_jid_is_unknown_is_a_502_and_nothing_plays(): void
    {
        Http::fake();
        $host = $this->host(jid: null);
        $target = Device::factory()->driver(FakeStreamDriver::class)->inState(State::Standby)->create();

        $this->postJson("/api/devices/{$target->id}/beolink-stream", ['host_device_id' => $host->id])
            ->assertStatus(502)->assertJson(['error' => 'beolink_unavailable']);

        $this->assertSame([], FakeStreamDriver::$played);
        Http::assertNothingSent();
    }

    public function test_a_receiver_that_refuses_the_join_is_a_502(): void
    {
        Http::fake(['beolink.test:8081/*' => Http::response('', 500)]);
        $host = $this->host();
        $target = Device::factory()->driver(FakeStreamDriver::class)->inState(State::Standby)->create();

        $this->postJson("/api/devices/{$target->id}/beolink-stream", ['host_device_id' => $host->id])
            ->assertStatus(502)->assertJson(['error' => 'beolink_unavailable']);

        $this->assertNull(Cache::get('beolink:host'));
    }

    public function test_it_is_refused_while_the_stream_is_off(): void
    {
        config(['beolink.enabled' => false]);
        $target = Device::factory()->driver(FakeStreamDriver::class)->inState(State::Standby)->create();

        $this->postJson("/api/devices/{$target->id}/beolink-stream")->assertStatus(422)->assertJson(['error' => 'unsupported']);
    }

    public function test_the_receiver_leaves_when_the_last_target_is_removed(): void
    {
        Http::fake(['beolink.test:8081/*' => Http::response([], 202)]);
        Cache::put('beolink:host', 7);
        Cache::put('beolink:targets', [1, 2]);

        BeolinkStream::removeTarget(1);
        Http::assertNothingSent();
        $this->assertSame(7, Cache::get('beolink:host'));

        BeolinkStream::removeTarget(2);
        Http::assertSent(fn (Request $r) => $r->url() === 'http://beolink.test:8081/api/v1/beolink/leave');
        $this->assertNull(Cache::get('beolink:host'));
    }
}
