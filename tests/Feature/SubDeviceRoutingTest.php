<?php

namespace Tests\Feature;

use App\Domain\Device\AvailableSource;
use App\Domain\Device\Cache\Modes;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\DeviceRouting;
use App\Domain\Device\State;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Source;
use App\Domain\Media\TrackData;
use App\Domain\SubDevices\ActiveInput;
use App\Domain\SubDevices\DeviceLinks;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Models\Device;
use App\Models\DeviceLink;
use App\Models\DeviceMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeInputHostDriver;
use Tests\Support\FakeLinkedDriver;
use Tests\Support\FakePowerDriver;
use Tests\TestCase;

/** Workstream 2a, step 2: routing on the host while a link is active, the host's source list, now playing of the sub device. */
class SubDeviceRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Device $tv;

    private Device $dongle;

    protected function setUp(): void
    {
        parent::setUp();

        FakeLinkedDriver::reset();
        FakePowerDriver::reset();

        $this->tv = Device::factory()->create(['device_name' => 'V1', 'device_driver' => FakeLinkedDriver::class]);
        $this->dongle = Device::factory()->create(['device_name' => 'Chromecast', 'device_driver' => FakeLinkedDriver::class]);
        DeviceLinks::add($this->dongle, $this->tv, 'input', 'hdmi_1');
    }

    private function hdmi(string $n = '1'): Source
    {
        return new Source(name: 'SET-TOP BOX', category: 'video', jid: "hdmi_{$n}", sourceType: 'HDMI', connector: $n);
    }

    private function select(Device $host, ?Source $source): void
    {
        DeviceCache::updateState($host->id, State::Playing);
        $source === null ? ActiveInput::forget($host->id) : ActiveInput::put($host->id, $source);
    }

    private function casting(string $title = 'Cast Song'): void
    {
        DeviceCache::updateState($this->dongle->id, State::Playing);
        DeviceCache::updateNowPlaying($this->dongle->id, new NowPlaying(track: new TrackData(name: $title, duration: 200), state: 'playing', platform: 'media'));
    }

    public function test_transport_seek_and_queue_go_to_the_sub_device_while_its_link_is_active(): void
    {
        $this->select($this->tv, $this->hdmi());

        $this->postJson("/api/devices/{$this->tv->id}/pause")->assertOk();
        $this->postJson("/api/devices/{$this->tv->id}/next")->assertOk();
        $this->putJson("/api/devices/{$this->tv->id}/seek", ['position' => 90])->assertOk();
        $this->getJson("/api/devices/{$this->tv->id}/queue")->assertOk();

        $this->assertSame([['pause', null], ['next', null], ['seek', 90], ['getUpNext', 20]], FakeLinkedDriver::callsOn($this->dongle->id));
        $this->assertSame([], FakeLinkedDriver::callsOn($this->tv->id));
    }

    public function test_volume_power_and_sources_stay_with_the_host(): void
    {
        $this->select($this->tv, $this->hdmi());

        $this->putJson("/api/devices/{$this->tv->id}/volume", ['volume' => 40])->assertOk();
        $this->putJson("/api/devices/{$this->tv->id}/mute", ['muted' => true])->assertOk();
        $this->putJson("/api/devices/{$this->tv->id}/power", ['on' => false])->assertOk();
        $this->postJson("/api/devices/{$this->tv->id}/sources/activate", ['source_id' => 'MUSIC'])->assertOk();

        $this->assertSame([['setVolume', 40], ['mute', null], ['standby', null], ['activateSource', 'MUSIC']], FakeLinkedDriver::callsOn($this->tv->id));
        $this->assertSame([], FakeLinkedDriver::callsOn($this->dongle->id));
    }

    public function test_an_inactive_link_routes_nothing(): void
    {
        $this->select($this->tv, $this->hdmi('2'));

        $this->postJson("/api/devices/{$this->tv->id}/pause")->assertOk();

        $this->assertSame([['pause', null]], FakeLinkedDriver::callsOn($this->tv->id));
        $this->assertSame([], FakeLinkedDriver::callsOn($this->dongle->id));
    }

    public function test_a_contract_the_sub_device_lacks_stays_with_the_host(): void
    {
        $bare = Device::factory()->create(['device_name' => 'Dongle', 'device_driver' => FakePowerDriver::class]);
        DeviceLink::where('device_id', $this->dongle->id)->delete();
        DeviceLinks::add($bare, $this->tv, 'input', 'hdmi_1');
        $this->select($this->tv, $this->hdmi());

        $this->postJson("/api/devices/{$this->tv->id}/pause")->assertOk();

        $this->assertSame([['pause', null]], FakeLinkedDriver::callsOn($this->tv->id));
        $this->assertSame($this->tv->id, DeviceRouting::deviceFor($this->tv, MediaControlsInterface::class)->id);
    }

    public function test_the_host_reports_the_playback_capabilities_of_the_active_sub_device_on_top_of_its_own(): void
    {
        $host = Device::factory()->create(['device_name' => 'Box', 'device_driver' => FakeInputHostDriver::class]);
        DeviceLinks::add($this->dongle, $host, 'input', 'hdmi_1');

        $own = $this->getJson("/api/devices/{$host->id}")->json('data.capabilities');
        $this->assertSame(['sub_devices'], $own, 'A host with no playback contract has none of its own.');

        $this->select($host, $this->hdmi());
        $routed = $this->getJson("/api/devices/{$host->id}")->json('data.capabilities');

        $this->assertEqualsCanonicalizing(['media_controls', 'seek', 'queue', 'queue_jump', 'shuffle', 'sub_devices'], $routed);
        $this->assertNotContains('volume_control', $routed, 'Volume stays out: it is not delegated.');
        $this->assertNotContains('source_activation', $routed);

        // And the host can now be paused, because the call reaches the sub device.
        $this->postJson("/api/devices/{$host->id}/pause")->assertOk();
        $this->assertSame([['pause', null]], FakeLinkedDriver::callsOn($this->dongle->id));
    }

    public function test_the_host_shows_the_sub_devices_now_playing_while_it_plays_something(): void
    {
        $this->select($this->tv, $this->hdmi());
        DeviceCache::updateNowPlaying($this->tv->id, new NowPlaying(state: 'playing', type: 'video', platform: 'media', source: $this->hdmi()));

        // The dongle plays nothing: the host's own now playing (the input).
        $this->getJson("/api/devices/{$this->tv->id}")->assertJsonPath('data.now_playing.source.name', 'SET-TOP BOX')->assertJsonMissingPath('data.now_playing.track');

        $this->casting('Cast Song');
        $this->getJson("/api/devices/{$this->tv->id}")
            ->assertJsonPath('data.now_playing.track.name', 'Cast Song')
            ->assertJsonPath('data.now_playing.state', 'playing');

        // The sub device's own page is unchanged, and an inactive link shows the host's own again.
        $this->getJson("/api/devices/{$this->dongle->id}")->assertJsonPath('data.now_playing.track.name', 'Cast Song');
        $this->select($this->tv, $this->hdmi('2'));
        $this->getJson("/api/devices/{$this->tv->id}")->assertJsonMissingPath('data.now_playing.track');
    }

    public function test_the_host_shows_the_modes_of_the_sub_device_and_a_change_is_recorded_for_it(): void
    {
        $this->select($this->tv, $this->hdmi());
        $this->casting();

        $this->putJson("/api/devices/{$this->tv->id}/shuffle", ['shuffle' => true])->assertOk();

        $this->assertSame([['setShuffle', true]], FakeLinkedDriver::callsOn($this->dongle->id));
        $this->assertSame([], FakeLinkedDriver::callsOn($this->tv->id));
        $this->assertTrue(Modes::get($this->dongle->id)->shuffle);
        $this->assertNull(Modes::get($this->tv->id)->shuffle);
        $this->getJson("/api/devices/{$this->tv->id}")->assertJsonPath('data.now_playing.modes.shuffle', true);
    }

    public function test_the_hosts_source_list_has_an_entry_per_link(): void
    {
        $this->tv->deviceSources()->delete();
        FakeLinkedDriver::$sourceList = [
            new AvailableSource('hdmi_1', 'SET-TOP BOX', 'HDMI', 'video', true, false, null, null, input: 'HDMI 1'),
            new AvailableSource('hdmi_2', 'Console', 'HDMI', 'video', true, false, null, null, input: 'HDMI 2'),
        ];
        $other = Device::factory()->create(['device_name' => 'Box', 'device_driver' => FakeLinkedDriver::class]);
        DeviceLinks::add($other, $this->tv, 'input', 'hdmi_9');
        $this->select($this->tv, $this->hdmi());

        $rows = collect($this->getJson("/api/devices/{$this->tv->id}/sources")->assertOk()->json('sources'))->keyBy('source_id');

        // The V1's own input is marked, not duplicated: the dongle sits behind it, and it is the one selected.
        $this->assertCount(3, $rows);
        $this->assertSame(['id' => $this->dongle->id, 'name' => 'Chromecast'], $rows['hdmi_1']['sub_device']);
        $this->assertTrue($rows['hdmi_1']['active']);
        $this->assertArrayNotHasKey('sub_device', $rows['hdmi_2']);

        // A link that names no listed source gets an entry of its own, last, that activates by its via.
        $this->assertSame(['id' => $other->id, 'name' => 'Box'], $rows['hdmi_9']['sub_device']);
        $this->assertSame('HDMI', $rows['hdmi_1']['source_type']);
        $this->assertSame('INPUT', $rows['hdmi_9']['source_type']);
        $this->assertSame('hdmi_9', $rows['hdmi_9']['input']);
        $this->assertFalse($rows['hdmi_9']['active']);
        $this->assertSame('hdmi_9', collect($this->getJson("/api/devices/{$this->tv->id}/sources")->json('sources'))->last()['source_id']);

        // What the driver reported is what is stored: the entry is only in the answer.
        $this->assertSame(['hdmi_1', 'hdmi_2'], $this->tv->deviceSources()->orderBy('source_id')->pluck('source_id')->all());
    }

    public function test_a_borrowed_source_of_the_sub_device_is_marked_on_the_host(): void
    {
        $converter = Device::factory()->create(['device_name' => 'Converter', 'device_driver' => FakeLinkedDriver::class]);
        DeviceMeta::create(['device_id' => $converter->id, 'key' => 'converter_jid', 'value' => '1790.1.2@products.bang-olufsen.com']);
        $player = Device::factory()->create(['device_name' => 'Moment', 'device_driver' => FakeLinkedDriver::class]);
        DeviceLinks::add($converter, $player, 'borrow');
        FakeLinkedDriver::$sourceList = [
            new AvailableSource('RADIO:1790.1.2@products.bang-olufsen.com', 'RADIO', 'RADIO', 'MUSIC', true, true, '1790.1.2@products.bang-olufsen.com', 'Converter'),
            new AvailableSource('SPOTIFY:9.9@products.bang-olufsen.com', 'Spotify', 'SPOTIFY', 'MUSIC', true, false, null, null),
        ];

        $rows = collect($this->getJson("/api/devices/{$player->id}/sources")->json('sources'))->keyBy('friendly_name');

        $this->assertCount(2, $rows, 'A borrow link without via adds no entry of its own.');
        $this->assertSame($converter->id, $rows['RADIO']['sub_device']['id']);
        $this->assertArrayNotHasKey('sub_device', $rows['Spotify']);
    }

    public function test_a_device_without_links_lists_its_sources_as_before(): void
    {
        $plain = Device::factory()->create(['device_driver' => FakeLinkedDriver::class]);

        $rows = $this->getJson("/api/devices/{$plain->id}/sources")->json('sources');

        $this->assertSame(['MUSIC'], array_column($rows, 'source_id'));
        $this->assertArrayNotHasKey('sub_device', $rows[0]);
    }

    public function test_the_routing_contracts_are_the_same_as_spotifys(): void
    {
        $this->assertSame(DeviceRouting::DELEGATED_CONTRACTS, \App\Domain\Device\SpotifyRouting::DELEGATED_CONTRACTS);
        $this->assertContains(SeekInterface::class, DeviceRouting::DELEGATED_CONTRACTS);
    }
}
