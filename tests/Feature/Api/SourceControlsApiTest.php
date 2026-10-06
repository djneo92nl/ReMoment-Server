<?php

namespace Tests\Feature\Api;

use App\Domain\Control\SourceControls;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Source;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBareDriver;
use Tests\Support\FakeControlTransport;
use Tests\TestCase;

/** Source controls: profile by active source, `source_controls` capability, GET/POST /controls. */
class SourceControlsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeControlTransport::$sent = [];
    }

    private function playing(?Source $source, bool $withTransport = true): Device
    {
        config(['control-profiles.transports' => $withTransport ? [FakeControlTransport::class] : []]);

        $device = Device::create([
            'ip_address' => '10.0.0.20',
            'device_name' => 'Beocenter',
            'device_brand_name' => 'Test',
            'device_product_type' => 'Speaker',
            'device_driver' => FakeBareDriver::class,
            'device_driver_name' => 'Fake',
        ]);

        DeviceCache::updateState($device->id, State::Playing);
        if ($source) {
            (new DeviceCache)->updateNowPlaying($device->id, new NowPlaying(source: $source, state: 'playing'));
        }

        return $device;
    }

    public function test_the_source_picks_the_profile(): void
    {
        $this->assertSame('cd', SourceControls::profileForSource(new Source(name: 'CD'))->key);
        $this->assertSame('tape', SourceControls::profileForSource(new Source(name: 'TAPE'))->key);
        $this->assertSame('fm', SourceControls::profileForSource(new Source(name: 'FM'))->key);
        $this->assertNull(SourceControls::profileForSource(new Source(name: 'Spotify')));
        $this->assertNull(SourceControls::profileForSource(new Source(name: 'N.RADIO')));
    }

    public function test_a_cd_source_lists_its_controls_and_the_capability(): void
    {
        $device = $this->playing(new Source(name: 'CD', sourceType: 'cd'));

        $this->getJson("/api/devices/{$device->id}")->assertJsonPath('data.capabilities', ['source_controls']);
        $this->getJson("/api/devices/{$device->id}/controls")
            ->assertOk()
            ->assertJsonPath('profile', 'cd')
            ->assertJsonPath('label', 'CD')
            ->assertJsonPath('controls.1.id', 'next_disc');
    }

    public function test_no_controls_without_a_matching_source_or_a_transport(): void
    {
        $other = $this->playing(new Source(name: 'Spotify'));
        $this->getJson("/api/devices/{$other->id}/controls")->assertOk()->assertExactJson(['profile' => null, 'label' => null, 'controls' => []]);
        $this->postJson("/api/devices/{$other->id}/controls/next_disc")->assertStatus(422)->assertJsonPath('error', 'unsupported');

        $noTransport = $this->playing(new Source(name: 'CD'), withTransport: false);
        $this->getJson("/api/devices/{$noTransport->id}")->assertJsonPath('data.capabilities', []);
    }

    public function test_a_control_is_sent_through_the_transport(): void
    {
        $device = $this->playing(new Source(name: 'CD'));

        $this->postJson("/api/devices/{$device->id}/controls/next_disc")
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'control' => 'next_disc']);
        $this->assertSame([[$device->id, 'next_disc']], FakeControlTransport::$sent);
    }

    public function test_a_control_of_another_profile_is_unsupported(): void
    {
        $device = $this->playing(new Source(name: 'CD'));

        $this->postJson("/api/devices/{$device->id}/controls/tune_up")->assertStatus(422)->assertJsonPath('error', 'unsupported');
        $this->assertSame([], FakeControlTransport::$sent);
    }

    public function test_a_failing_transport_is_a_bad_gateway(): void
    {
        $device = $this->playing(new Source(name: 'CD'));
        config(['control-profiles.profiles.cd.commands' => [['id' => 'broken', 'label' => 'Broken']]]);

        $this->postJson("/api/devices/{$device->id}/controls/broken")->assertStatus(502)->assertJsonPath('error', 'driver_error');
    }
}
