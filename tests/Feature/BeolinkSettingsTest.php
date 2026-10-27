<?php

namespace Tests\Feature;

use App\Domain\Device\State;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeStreamDriver;
use Tests\TestCase;

/** /settings/beolink: receiver status, the devices to play on, and leaving the source. */
class BeolinkSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['beolink.api_url' => 'http://beolink.test:8081', 'app.stream_url' => 'http://192.168.1.48:8080']);
    }

    private function admin(): User
    {
        return User::factory()->create();
    }

    public function test_the_page_needs_the_admin_login(): void
    {
        $this->get('/settings/beolink')->assertRedirect();
    }

    public function test_it_explains_how_to_switch_on_while_off(): void
    {
        config(['beolink.enabled' => false]);

        $this->actingAs($this->admin())->get('/settings/beolink')
            ->assertOk()->assertSee('--profile beolink-stream')->assertSee('BEOLINK_ENABLED=true');
    }

    public function test_it_shows_the_receiver_its_peers_and_the_devices(): void
    {
        config(['beolink.enabled' => true]);
        Http::fake([
            'beolink.test:8081/api/v1/beolink/self' => Http::response(['jid' => 'x@products.bang-olufsen.com', 'friendlyName' => 'ReMoment Stream']),
            'beolink.test:8081/api/v1/beolink/peers' => Http::response([['jid' => 'a', 'friendlyName' => 'Beoplay M5', 'ipAddress' => '192.168.1.20']]),
        ]);
        $host = Device::factory()->inState(State::Playing)->create(['device_name' => 'Keuken M5']);
        $host->meta()->create(['key' => 'ase_jid', 'value' => '3150.1.1@products.bang-olufsen.com']);
        Device::factory()->driver(FakeStreamDriver::class)->inState(State::Standby)->create(['device_name' => 'Sonos Roam']);

        $this->actingAs($this->admin())->get('/settings/beolink')
            ->assertOk()->assertSee('Running')->assertSee('Beoplay M5')->assertSee('192.168.1.20')
            ->assertSee('Keuken M5')->assertSee('Sonos Roam');
    }

    public function test_a_receiver_that_does_not_answer_is_reported(): void
    {
        config(['beolink.enabled' => true]);
        Http::fake(['beolink.test:8081/*' => Http::response('', 500)]);

        $this->actingAs($this->admin())->get('/settings/beolink')->assertOk()->assertSee('Not answering');
    }

    public function test_leaving_tells_the_receiver_and_forgets_the_targets(): void
    {
        config(['beolink.enabled' => true]);
        Http::fake(['beolink.test:8081/*' => Http::response([], 202)]);
        Cache::put('beolink:host', 3);
        Cache::put('beolink:targets', [4]);

        $this->actingAs($this->admin())->post('/settings/beolink/leave')->assertRedirect('/settings/beolink');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/beolink/leave'));
        $this->assertNull(Cache::get('beolink:host'));
        $this->assertNull(Cache::get('beolink:targets'));
    }
}
