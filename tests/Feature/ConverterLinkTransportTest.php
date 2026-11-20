<?php

namespace Tests\Feature;

use App\Domain\Control\ControlProfile;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Source;
use App\Domain\SubDevices\DeviceLinks;
use App\Integrations\BangOlufsen\Ase\Control\ConverterDigitTransport;
use App\Integrations\BangOlufsen\Ase\ConverterDriver;
use App\Integrations\BangOlufsen\Ase\MusicPlayerDriver;
use App\Integrations\Common\HttpConnector;
use App\Models\Device;
use App\Models\DeviceMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAseConnector;
use Tests\TestCase;

/** FM presets read the player's link to the converter (workstream 2a) instead of looking the converter up by its jid. */
class ConverterLinkTransportTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '1790.1179011.24978398@products.bang-olufsen.com';

    private FakeAseConnector $api;

    private Device $player;

    private Device $converter;

    protected function setUp(): void
    {
        parent::setUp();

        config(['control-profiles.transports' => [ConverterDigitTransport::class]]);
        $this->api = new FakeAseConnector;
        $this->app->bind(HttpConnector::class, fn () => $this->api);

        $this->player = Device::factory()->create(['ip_address' => '10.0.0.29', 'device_name' => 'Moment', 'device_driver' => MusicPlayerDriver::class]);
        $this->converter = Device::factory()->create(['ip_address' => '10.0.0.30', 'device_name' => 'Converter', 'device_driver' => ConverterDriver::class, 'device_product_type' => 'BeoLink Converter NL/ML']);
        DeviceMeta::create(['device_id' => $this->converter->id, 'key' => 'converter_jid', 'value' => self::JID]);
    }

    private function plays(string $jid): void
    {
        DeviceCache::updateState($this->player->id, State::Playing);
        DeviceCache::updateNowPlaying($this->player->id, new NowPlaying(source: new Source(name: 'RADIO', category: 'RADIO', jid: $jid, sourceType: 'RADIO', connector: ''), state: 'playing'));
    }

    private function supports(): bool
    {
        return (new ConverterDigitTransport)->supports($this->player, new ControlProfile('fm', 'FM', []));
    }

    public function test_a_linked_player_with_the_converters_source_selected_is_offered_the_presets(): void
    {
        DeviceLinks::add($this->converter, $this->player, 'borrow');
        $this->plays('RADIO:'.self::JID);

        $this->assertTrue($this->supports());
        $this->getJson("/api/devices/{$this->player->id}/controls")->assertOk()->assertJsonPath('profile', 'fm');
    }

    public function test_a_linked_player_on_another_converters_or_another_source_is_not(): void
    {
        DeviceLinks::add($this->converter, $this->player, 'borrow');

        // A radio source of some other product that happens to look like a converter's: not the linked one.
        $this->plays('RADIO:1790.9999999.11111111@products.bang-olufsen.com');
        $this->assertFalse($this->supports(), 'The link names the converter, not the product type.');

        $this->plays('SPOTIFY:1234.5@products.bang-olufsen.com');
        $this->assertFalse($this->supports());
    }

    public function test_a_link_with_a_via_is_matched_on_that_source(): void
    {
        DeviceLinks::add($this->converter, $this->player, 'borrow', 'RADIO:'.self::JID);
        $this->plays('RADIO:'.self::JID);

        $this->assertTrue($this->supports());
    }

    public function test_a_player_with_no_link_is_judged_by_the_jid_as_before(): void
    {
        $this->plays('RADIO:'.self::JID);

        $this->assertTrue($this->supports(), 'An install that borrowed before links existed keeps its presets.');

        $this->plays('RADIO:9999.1.2@products.bang-olufsen.com');
        $this->assertFalse($this->supports());
    }

    public function test_the_converter_stays_standalone_and_keeps_its_card(): void
    {
        DeviceLinks::add($this->converter, $this->player, 'borrow');

        $this->assertTrue(\App\Domain\Device\DeviceModels::standalone($this->converter));
        $this->assertSame(['Moment', 'Converter'], DeviceLinks::withoutNestedSubDevices(Device::orderBy('id')->get())->pluck('device_name')->all());
    }
}
