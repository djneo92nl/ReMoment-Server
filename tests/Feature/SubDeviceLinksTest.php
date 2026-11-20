<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\DeviceCapabilities;
use App\Domain\Device\State;
use App\Domain\Media\Source;
use App\Domain\SubDevices\ActiveInput;
use App\Domain\SubDevices\DeviceLinks;
use App\Domain\SubDevices\InvalidDeviceLink;
use App\Domain\SubDevices\LinkMatcher;
use App\Domain\SubDevices\LinkState;
use App\Integrations\BangOlufsen\Ase\MusicPlayerDriver as AseMusic;
use App\Integrations\BangOlufsen\Ase\VideoPlayerDriver as AseVideo;
use App\Integrations\Contracts\ReportsActiveSource;
use App\Integrations\Philips\VideoPlayerDriver as PhilipsTv;
use App\Models\Device;
use App\Models\DeviceLink;
use App\Models\DeviceMeta;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBareDriver;
use Tests\Support\FakeLinkedDriver;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

/** Workstream 2a, step 1: the `device_links` table, its rules, the state of a link and what the device detail says. */
class SubDeviceLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeLinkedDriver::reset();
    }

    private function device(string $name, string $driver = FakeLinkedDriver::class, array $attributes = []): Device
    {
        return Device::factory()->create(['device_name' => $name, 'device_driver' => $driver, ...$attributes]);
    }

    private function hdmi(string $connector = '1', string $id = 'hdmi_1'): Source
    {
        return new Source(name: 'SET-TOP BOX', category: 'video', jid: $id, sourceType: 'HDMI', connector: $connector);
    }

    private function selected(Device $host, ?Source $source, State $state = State::Playing): void
    {
        DeviceCache::updateState($host->id, $state);
        $source === null ? ActiveInput::forget($host->id) : ActiveInput::put($host->id, $source);
    }

    public function test_a_link_connects_a_sub_device_to_a_host(): void
    {
        $tv = $this->device('V1');
        $dongle = $this->device('Chromecast');

        $link = DeviceLinks::add($dongle, $tv, 'input', ' hdmi_1 ', ['follow' => 'off']);

        $this->assertSame('hdmi_1', $link->via);
        $this->assertSame($dongle->id, $link->device->id);
        $this->assertSame($tv->id, $link->host->id);
        $this->assertSame(['follow' => 'off'], $link->fresh()->meta);
    }

    public function test_the_same_link_cannot_exist_twice_but_a_device_can_have_several_hosts_and_subs(): void
    {
        $tv = $this->device('V1');
        $other = $this->device('Philips');
        $dongle = $this->device('Chromecast');
        $box = $this->device('Box');

        DeviceLinks::add($dongle, $tv, 'input', 'hdmi_1');
        DeviceLinks::add($dongle, $tv, 'input', 'hdmi_2');
        DeviceLinks::add($dongle, $other, 'input', 'hdmi_1');
        DeviceLinks::add($box, $tv, 'input', 'hdmi_3');

        $this->expectException(UniqueConstraintViolationException::class);
        DeviceLinks::add($dongle, $tv, 'input', 'hdmi_1');
    }

    public function test_a_borrow_link_may_have_no_via_but_an_input_needs_one(): void
    {
        $player = $this->device('Moment');
        $converter = $this->device('Converter');

        $this->assertSame('', DeviceLinks::add($converter, $player, 'borrow')->via);

        $this->expectException(InvalidDeviceLink::class);
        DeviceLinks::add($converter, $player, 'input');
    }

    public function test_it_rejects_an_unknown_kind_and_a_link_to_itself(): void
    {
        $a = $this->device('A');
        $b = $this->device('B');

        try {
            DeviceLinks::add($a, $b, 'magic', 'x');
            $this->fail('An unknown kind is refused.');
        } catch (InvalidDeviceLink $e) {
            $this->assertStringContainsString('Unknown link kind', $e->getMessage());
        }

        $this->expectException(InvalidDeviceLink::class);
        DeviceLinks::add($a, $a, 'input', 'x');
    }

    public function test_it_goes_one_level_deep(): void
    {
        $tv = $this->device('V1');
        $dongle = $this->device('Chromecast');
        $other = $this->device('Other');
        DeviceLinks::add($dongle, $tv, 'input', 'hdmi_1');

        // The host is no sub device, ...
        try {
            DeviceLinks::add($tv, $other, 'input', 'hdmi_2');
            $this->fail('A host cannot become a sub device.');
        } catch (InvalidDeviceLink $e) {
            $this->assertStringContainsString('host itself', $e->getMessage());
        }

        // ... and the sub device is no host.
        try {
            DeviceLinks::add($other, $dongle, 'input', 'hdmi_2');
            $this->fail('A sub device cannot become a host.');
        } catch (InvalidDeviceLink $e) {
            $this->assertStringContainsString('sub device itself', $e->getMessage());
        }

        // Saving the one link again changes nothing about that.
        $link = DeviceLink::first();
        $link->via = 'hdmi_2';
        $link->save();
        $this->assertSame('hdmi_2', $link->fresh()->via);
    }

    public function test_deleting_either_device_ends_the_link(): void
    {
        $tv = $this->device('V1');
        $dongle = $this->device('Chromecast');
        DeviceLinks::add($dongle, $tv, 'input', 'hdmi_1');

        $dongle->delete();

        $this->assertSame(0, DeviceLink::count());
        $this->assertNotNull($tv->fresh());
    }

    public function test_the_matcher_reads_the_via_as_id_input_or_name_ignoring_punctuation(): void
    {
        $tv = $this->device('V1');
        $dongle = $this->device('Chromecast');
        $source = $this->hdmi();

        foreach (['hdmi_1', 'HDMI 1', 'hdmi1', 'set-top box'] as $via) {
            $link = new DeviceLink(['device_id' => $dongle->id, 'host_device_id' => $tv->id, 'kind' => 'input', 'via' => $via]);
            $this->assertTrue(LinkMatcher::matches($link, $source), "{$via} names the input");
        }

        $other = new DeviceLink(['device_id' => $dongle->id, 'host_device_id' => $tv->id, 'kind' => 'input', 'via' => 'hdmi_2']);
        $this->assertFalse(LinkMatcher::matches($other, $source));
        $this->assertFalse(LinkMatcher::matches($other, null));
    }

    public function test_a_borrow_link_without_via_matches_every_source_the_sub_device_provides(): void
    {
        $player = $this->device('Moment');
        $converter = $this->device('Converter');
        DeviceMeta::create(['device_id' => $converter->id, 'key' => 'converter_jid', 'value' => '1790.1179011.24978398@products.bang-olufsen.com']);
        $link = DeviceLinks::add($converter, $player, 'borrow');

        $radio = new Source(name: 'RADIO', category: 'RADIO', jid: 'RADIO:1790.1179011.24978398@products.bang-olufsen.com', sourceType: 'RADIO');
        $spotify = new Source(name: 'Spotify', category: 'MUSIC', jid: 'spotify:1234.5@products.bang-olufsen.com', sourceType: 'SPOTIFY');

        $this->assertTrue(LinkMatcher::matches($link, $radio));
        $this->assertFalse(LinkMatcher::matches($link, $spotify));
    }

    public function test_a_link_is_active_while_the_host_has_its_input_selected(): void
    {
        $tv = $this->device('V1');
        $dongle = $this->device('Chromecast');
        $link = DeviceLinks::add($dongle, $tv, 'input', 'hdmi_1');

        $this->selected($tv, $this->hdmi());
        $this->assertTrue(LinkState::active($link));

        $this->selected($tv, $this->hdmi('2', 'hdmi_2'));
        $this->assertFalse(LinkState::active($link));

        $this->selected($tv, $this->hdmi(), State::Standby);
        $this->assertFalse(LinkState::active($link), 'A host in standby has nothing selected.');

        $this->selected($tv, null);
        $this->assertFalse(LinkState::active($link));
    }

    public function test_a_host_that_does_not_report_its_input_gives_an_unknown_state(): void
    {
        $philips = $this->device('Philips', FakePlayerDriver::class);
        $dongle = $this->device('Chromecast');
        $link = DeviceLinks::add($dongle, $philips, 'input', 'hdmi_1');

        $this->selected($philips, $this->hdmi());

        $this->assertNull(LinkState::active($link));
        $this->assertCount(0, DeviceLinks::activeSubLinks($philips), 'Nothing is routed on a guess.');
    }

    public function test_active_sub_links_and_the_active_host_link(): void
    {
        $tv = $this->device('V1');
        $dongle = $this->device('Chromecast');
        $box = $this->device('Box');
        DeviceLinks::add($dongle, $tv, 'input', 'hdmi_1');
        DeviceLinks::add($box, $tv, 'input', 'hdmi_2');

        $this->selected($tv, $this->hdmi('2', 'hdmi_2'));

        $this->assertSame([$box->id], DeviceLinks::activeSubLinks($tv)->pluck('device_id')->all());
        $this->assertSame($tv->id, DeviceLinks::activeHostLink($box)?->host_device_id);
        $this->assertNull(DeviceLinks::activeHostLink($dongle));
    }

    public function test_the_device_detail_says_the_relation_both_ways(): void
    {
        $tv = $this->device('V1');
        $dongle = $this->device('Chromecast');
        $philips = $this->device('Philips', FakePlayerDriver::class);
        DeviceLinks::add($dongle, $tv, 'input', 'hdmi_1');
        DeviceLinks::add($dongle, $philips, 'input', 'hdmi_2');
        $this->selected($tv, $this->hdmi());

        $host = $this->getJson("/api/devices/{$tv->id}")->assertOk();
        $host->assertJsonPath('data.host', null);
        $host->assertJsonPath('data.sub_devices', [['id' => $dongle->id, 'name' => 'Chromecast', 'via' => 'hdmi_1', 'active' => true]]);
        $this->assertContains('sub_devices', $host->json('data.capabilities'));

        // The sub device names the host that has it selected, not the oldest one.
        $sub = $this->getJson("/api/devices/{$dongle->id}")->assertOk();
        $sub->assertJsonPath('data.host', ['id' => $tv->id, 'name' => 'V1', 'via' => 'hdmi_1']);
        $sub->assertJsonPath('data.sub_devices', []);
        $this->assertNotContains('sub_devices', $sub->json('data.capabilities'));

        $this->getJson("/api/devices/{$philips->id}")->assertJsonPath('data.sub_devices.0.active', null);
    }

    public function test_a_device_without_links_has_no_relation(): void
    {
        $device = $this->device('Lonely');

        $this->getJson("/api/devices/{$device->id}")
            ->assertOk()
            ->assertJsonPath('data.host', null)
            ->assertJsonPath('data.sub_devices', []);
        $this->assertNotContains('sub_devices', $this->getJson("/api/devices/{$device->id}")->json('data.capabilities'));
    }

    public function test_the_host_capability_follows_a_link_saved_or_removed_in_the_same_request(): void
    {
        $tv = $this->device('V1');
        $dongle = $this->device('Chromecast');

        $this->assertNotContains('sub_devices', DeviceCapabilities::for($tv));
        $link = DeviceLinks::add($dongle, $tv, 'input', 'hdmi_1');
        $this->assertContains('sub_devices', DeviceCapabilities::for($tv));
        $this->assertNotContains('sub_devices', DeviceCapabilities::for($dongle));

        $link->delete();
        $this->assertNotContains('sub_devices', DeviceCapabilities::for($tv));
    }

    public function test_the_ase_drivers_report_their_selected_source(): void
    {
        $this->assertTrue(is_a(AseVideo::class, ReportsActiveSource::class, true));
        $this->assertTrue(is_a(AseMusic::class, ReportsActiveSource::class, true));
        $this->assertFalse(is_a(PhilipsTv::class, ReportsActiveSource::class, true), 'A Philips TV cannot read its HDMI input.');
    }

    public function test_a_model_is_standalone_unless_its_config_says_it_is_not(): void
    {
        $converter = $this->device('Converter', FakeBareDriver::class, ['device_product_type' => 'BeoLink Converter NL/ML']);
        $unknown = $this->device('Whatever', FakeBareDriver::class, ['device_product_type' => 'Speaker']);

        $this->assertTrue(\App\Domain\Device\DeviceModels::standalone($converter));
        $this->assertTrue(\App\Domain\Device\DeviceModels::standalone($unknown));

        config(['devices.Google' => ['Chromecast' => ['driver_name' => 'Cast', 'driver' => FakeBareDriver::class, 'standalone' => false]]]);
        $dongle = $this->device('Dongle', FakeBareDriver::class, ['device_product_type' => 'Chromecast']);

        $this->assertFalse(\App\Domain\Device\DeviceModels::standalone($dongle));
    }

    public function test_the_grid_nests_a_sub_device_that_cannot_run_by_itself_under_its_host(): void
    {
        config(['devices.Google' => ['Chromecast' => ['driver_name' => 'Cast', 'driver' => FakeBareDriver::class, 'standalone' => false]]]);

        $tv = $this->device('V1');
        $dongle = $this->device('Dongle', FakeBareDriver::class, ['device_product_type' => 'Chromecast']);
        $converter = $this->device('Converter', FakeBareDriver::class, ['device_product_type' => 'BeoLink Converter NL/ML']);
        $player = $this->device('Moment');
        DeviceLinks::add($dongle, $tv, 'input', 'hdmi_1');
        DeviceLinks::add($converter, $player, 'borrow');

        $all = Device::all();
        $listed = DeviceLinks::withoutNestedSubDevices($all)->pluck('device_name')->all();

        $this->assertEqualsCanonicalizing(['V1', 'Converter', 'Moment'], $listed, 'Only the dongle has no card of its own.');

        // A host that is not in the list never makes a device unreachable.
        $this->assertContains('Dongle', DeviceLinks::withoutNestedSubDevices($all->reject(fn (Device $d) => $d->id === $tv->id))->pluck('device_name')->all());
    }

    public function test_the_grid_page_hides_the_nested_sub_device_and_the_host_card_lists_it_while_active(): void
    {
        config(['devices.Google' => ['Chromecast' => ['driver_name' => 'Cast', 'driver' => FakeBareDriver::class, 'standalone' => false]]]);

        $tv = $this->device('Beoplay V1 TV');
        $dongle = $this->device('Dongle in the hall', FakeBareDriver::class, ['device_product_type' => 'Chromecast']);
        DeviceLinks::add($dongle, $tv, 'input', 'hdmi_1');

        $this->get('/devices')->assertOk()->assertSee('Beoplay V1 TV')->assertDontSee('Dongle in the hall');

        // The host's card carries it, only while the link is active; its page is reached from there.
        $this->selected($tv, $this->hdmi());
        $this->get('/devices')->assertSee('Dongle in the hall')->assertSee(route('devices.show', $dongle), false);
    }
}
