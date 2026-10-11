<?php

namespace Tests\Feature\Api;

use App\Domain\Control\SourceControls;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Source;
use App\Integrations\BangOlufsen\Ase\Control\ConverterDigitTransport;
use App\Integrations\BangOlufsen\Ase\MusicPlayerDriver;
use App\Integrations\Common\HttpConnector;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAseConnector;
use Tests\Support\FakeBareDriver;
use Tests\TestCase;

/**
 * FM presets of a converter's tuner, pressed from an ASE player that borrowed
 * its RADIO source (verified on a BeoSound Moment: a digit sent to the converter
 * itself does nothing, the same digit sent to the Moment changes the preset).
 */
class ConverterDigitTransportTest extends TestCase
{
    use RefreshDatabase;

    private const CONVERTER_RADIO = 'RADIO:1790.1179011.24978398@products.bang-olufsen.com';

    private FakeAseConnector $api;

    protected function setUp(): void
    {
        parent::setUp();

        config(['control-profiles.transports' => [ConverterDigitTransport::class]]);

        $this->api = new FakeAseConnector;
        $this->app->bind(HttpConnector::class, fn () => $this->api);
    }

    private function converterRadio(): Source
    {
        return new Source(name: 'RADIO', category: 'RADIO', jid: self::CONVERTER_RADIO, sourceType: 'RADIO', connector: '');
    }

    private function playing(Source $source, string $driver = MusicPlayerDriver::class): Device
    {
        $device = Device::factory()->create([
            'ip_address' => '10.0.0.29',
            'device_name' => 'Moment',
            'device_driver' => $driver,
        ]);

        DeviceCache::updateState($device->id, State::Playing);
        DeviceCache::updateNowPlaying($device->id, new NowPlaying(source: $source, state: 'playing'));

        return $device;
    }

    public function test_a_converter_radio_is_an_fm_source_by_its_exact_type(): void
    {
        $this->assertSame('fm', SourceControls::profileForSource($this->converterRadio())->key);
        $this->assertSame('fm', SourceControls::profileForSource(new Source(sourceType: 'radio'))->key);

        $this->assertNull(SourceControls::profileForSource(new Source(sourceType: 'NET RADIO', name: 'N.RADIO')));
        $this->assertNull(SourceControls::profileForSource(new Source(sourceType: 'TUNEIN', name: 'TuneIn', category: 'RADIO')));
    }

    public function test_a_player_playing_the_converter_radio_lists_only_the_preset_numbers(): void
    {
        $device = $this->playing($this->converterRadio());

        $this->assertContains('source_controls', $this->getJson("/api/devices/{$device->id}")->json('data.capabilities'));

        $controls = $this->getJson("/api/devices/{$device->id}/controls")
            ->assertOk()
            ->assertJsonPath('profile', 'fm')
            ->json('controls');

        $this->assertSame(
            ['preset_1', 'preset_2', 'preset_3', 'preset_4', 'preset_5', 'preset_6', 'preset_7', 'preset_8', 'preset_9'],
            array_column($controls, 'id'),
        );
        $this->assertSame('3', $controls[2]['label']);
    }

    public function test_a_preset_is_sent_as_a_digit_to_the_player(): void
    {
        $device = $this->playing($this->converterRadio());

        $this->postJson("/api/devices/{$device->id}/controls/preset_6")
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'control' => 'preset_6']);

        $this->assertSame(['POST BeoZone/Zone/Digits'], $this->api->requests);
        $this->assertSame([['digits' => 6]], $this->api->bodies);
    }

    public function test_relative_steps_and_tuning_are_not_offered(): void
    {
        $device = $this->playing($this->converterRadio());

        foreach (['next_preset', 'prev_preset', 'tune_up', 'tune_down', 'preset_0', 'preset_10'] as $control) {
            $this->postJson("/api/devices/{$device->id}/controls/{$control}")
                ->assertStatus(422)
                ->assertJsonPath('error', 'unsupported');
        }

        $this->assertSame([], $this->api->requests);
    }

    public function test_a_player_that_rejects_the_digit_is_a_bad_gateway(): void
    {
        $device = $this->playing($this->converterRadio());

        $this->app->bind(HttpConnector::class, fn () => new class extends FakeAseConnector
        {
            protected function send(string $method, string $url, array $data = []): array
            {
                return ['status' => 400, 'body' => json_encode(['error' => ['type' => 'INVALID_ARGUMENT']])];
            }
        });

        $this->postJson("/api/devices/{$device->id}/controls/preset_2")
            ->assertStatus(502)
            ->assertJsonPath('error', 'driver_error');
    }

    public function test_other_radio_sources_and_other_drivers_get_no_controls(): void
    {
        // TuneIn on the player itself
        $tunein = $this->playing(new Source(name: 'TuneIn', category: 'RADIO', jid: 'radio:2997.1290084.26093928@products.bang-olufsen.com', sourceType: 'TUNEIN'));
        $this->getJson("/api/devices/{$tunein->id}/controls")->assertExactJson(['profile' => null, 'label' => null, 'controls' => []]);

        // A "RADIO" type source of a product that is not a converter
        $other = $this->playing(new Source(name: 'RADIO', jid: 'RADIO:2872.1293028.26806396@products.bang-olufsen.com', sourceType: 'RADIO'));
        $this->getJson("/api/devices/{$other->id}/controls")->assertExactJson(['profile' => null, 'label' => null, 'controls' => []]);

        // A device that is not an ASE player
        $bare = $this->playing($this->converterRadio(), FakeBareDriver::class);
        $this->getJson("/api/devices/{$bare->id}/controls")->assertExactJson(['profile' => null, 'label' => null, 'controls' => []]);
        $this->postJson("/api/devices/{$bare->id}/controls/preset_1")->assertStatus(422);

        $this->assertSame([], $this->api->requests);
    }
}
