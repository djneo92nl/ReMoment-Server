<?php

namespace Tests\Feature;

use App\Domain\Beolink\BeolinkStream;
use App\Domain\Device\State;
use App\Livewire\DeviceCard;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\FakeStreamDriver;
use Tests\TestCase;

/** The multiroom picker offers devices outside the brand ("pseudo multiroom") that can play the BeoLink stream. */
class BeolinkMultiroomTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeStreamDriver::$played = [];
        Cache::flush();
        config(['beolink.enabled' => true, 'beolink.api_url' => 'http://beolink.test:8081', 'app.stream_url' => 'http://192.168.1.48:8080']);
    }

    private function host(State $state = State::Playing): Device
    {
        $host = Device::factory()->inState($state)->create(['device_brand_name' => 'Bang & Olufsen']);
        $host->meta()->create(['key' => 'ase_jid', 'value' => '3150.1.1@products.bang-olufsen.com']);

        return $host;
    }

    private function speaker(string $brand = 'Sonos', State $state = State::Standby): Device
    {
        return Device::factory()->driver(FakeStreamDriver::class)->inState($state)->create(['device_brand_name' => $brand]);
    }

    public function test_stream_targets_of_other_brands_are_listed_for_a_playing_host(): void
    {
        $host = $this->host();
        $sonos = $this->speaker();
        $this->speaker('Bang & Olufsen');                 // native multiroom, not a stream target
        $this->speaker('Philips', State::Unreachable);    // cannot be told anything

        Livewire::test(DeviceCard::class, ['device' => $host])
            ->call('loadMultiRoomData')
            ->assertSet('streamTargets', [['id' => $sonos->id, 'device_name' => $sonos->device_name]]);
    }

    public function test_no_stream_targets_while_the_stream_is_off_or_the_host_is_not_playing(): void
    {
        $host = $this->host();
        $this->speaker();

        config(['beolink.enabled' => false]);
        Livewire::test(DeviceCard::class, ['device' => $host])->call('loadMultiRoomData')->assertSet('streamTargets', []);

        config(['beolink.enabled' => true]);
        Livewire::test(DeviceCard::class, ['device' => $this->host(State::Standby)])->call('loadMultiRoomData')->assertSet('streamTargets', []);
    }

    public function test_streaming_to_a_target_joins_the_host_and_plays_the_stream_there(): void
    {
        Http::fake(['beolink.test:8081/*' => Http::response([], 202)]);
        $host = $this->host();
        $sonos = $this->speaker();

        Livewire::test(DeviceCard::class, ['device' => $host])
            ->call('loadMultiRoomData')
            ->call('streamTo', $sonos->id)
            ->assertSet('multiroomError', null)
            ->assertSet('streamPlaying', [$sonos->id]);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/beolink/join/3150.1.1%40products.bang-olufsen.com'));
        $this->assertSame(BeolinkStream::url(), FakeStreamDriver::$played[0][0]);
        $this->assertSame($host->id, BeolinkStream::status()['host_device_id']);
    }

    public function test_a_join_that_fails_is_shown_and_nothing_plays(): void
    {
        Http::fake(['beolink.test:8081/*' => Http::response('', 500)]);
        $host = $this->host();
        $sonos = $this->speaker();

        Livewire::test(DeviceCard::class, ['device' => $host])
            ->call('loadMultiRoomData')
            ->call('streamTo', $sonos->id)
            ->assertSet('streamPlaying', []);

        $this->assertSame([], FakeStreamDriver::$played);
    }
}
