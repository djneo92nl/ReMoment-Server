<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Integrations\BangOlufsen\Ase\VideoPlayerDriver;
use App\Integrations\Common\UnsupportedOperationException;
use App\Listeners\Device\SwitchToDefaultSource;
use App\Livewire\DeviceSourceManager;
use App\Livewire\TvExtras;
use App\Models\Device;
use App\Models\DeviceMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeAseConnector;
use Tests\Support\FakeTvDriver;
use Tests\TestCase;

/** Phase 1 of docs/architecture/beovision-tv.md: the TV page on what the V1 already offers. */
class TvPageTest extends TestCase
{
    use RefreshDatabase;

    private function tv(string $driver = FakeTvDriver::class): Device
    {
        FakeTvDriver::reset();

        return Device::factory()->create(['device_name' => 'Living room TV', 'device_driver' => $driver, 'ip_address' => '10.0.0.30']);
    }

    private function aseTv(?FakeAseConnector &$api = null): VideoPlayerDriver
    {
        $device = $this->tv(VideoPlayerDriver::class);
        DeviceCache::updateState($device->id, State::Standby);

        $api = new FakeAseConnector;
        $driver = $device->driver;
        $driver->deviceApi = $api;

        return $driver;
    }

    public function test_the_tv_page_shows_inputs_and_apps_as_grids_with_input_badges(): void
    {
        $device = $this->tv();
        $component = Livewire::test(DeviceSourceManager::class, ['device' => $device]);

        $component
            ->assertSee('Input')
            ->assertSee('SET-TOP BOX')
            ->assertSee('HDMI 1')
            ->assertSee('HDMI 2')
            ->assertSee('TV TUNER')
            ->assertSee('Apps')
            ->assertSee('YOUTUBE')
            ->assertSee('ring-2', escape: false)
            ->assertSee('bg-emerald-500', escape: false);

        // Streaming stays out of the grids (the full picker in the modal still lists it).
        $deezer = $device->deviceSources()->where('source_id', 'deezer')->value('id');
        $this->assertStringNotContainsString('wire:key="tv-src-'.$deezer.'"', $component->html());
    }

    public function test_a_hidden_source_is_not_in_the_grid(): void
    {
        $device = $this->tv();

        $component = Livewire::test(DeviceSourceManager::class, ['device' => $device]);
        $hdmi2 = $device->deviceSources()->where('source_id', 'hdmi_2')->first();
        $component->call('toggleHidden', $hdmi2->id)->assertSee('1 hidden');
        $this->assertStringNotContainsString('wire:key="tv-src-'.$hdmi2->id.'"', $component->html());
    }

    public function test_tapping_a_tile_switches_the_source(): void
    {
        $device = $this->tv();
        $component = Livewire::test(DeviceSourceManager::class, ['device' => $device]);
        $id = $device->deviceSources()->where('source_id', 'hdmi_2')->value('id');

        $component->call('activateSource', $id);

        $this->assertSame(['hdmi_2'], FakeTvDriver::$activated);
    }

    public function test_a_speaker_keeps_the_plain_source_list(): void
    {
        $device = Device::factory()->create(['device_driver' => \Tests\Support\FakeSourceDriver::class]);

        Livewire::test(DeviceSourceManager::class, ['device' => $device])->assertSet('tv', false)->assertDontSee('Apps');
    }

    public function test_the_extras_card_has_number_keys_and_a_read_only_sleep_timer(): void
    {
        $device = $this->tv();

        Livewire::test(TvExtras::class, ['device' => $device])
            ->assertSee('Number keys')
            ->assertSee('Sleep timer')
            ->assertSee('Off')
            ->call('pressDigit', 5);

        $this->assertSame([5], FakeTvDriver::$digits);
    }

    public function test_waking_a_tv_sends_it_its_default_input(): void
    {
        $driver = $this->aseTv($api);
        DeviceMeta::create(['device_id' => $driver->device->id, 'key' => SwitchToDefaultSource::META_KEY, 'value' => 'hdmi_2:9503.1@products.bang-olufsen.com']);

        $driver->powerOn();

        $this->assertSame(['POST BeoZone/Zone/ActiveSources'], $api->requests);
        $this->assertSame('hdmi_2:9503.1@products.bang-olufsen.com', $api->bodies[0]['primaryExperience']['source']['id']);
    }

    public function test_without_a_default_input_a_tv_wakes_on_its_first_visible_own_source(): void
    {
        $driver = $this->aseTv($api);
        $device = $driver->device;
        $device->deviceSources()->create(['source_id' => 'deezer:1', 'friendly_name' => 'DEEZER', 'source_type' => 'DEEZER', 'category' => 'MEDIA', 'borrowed' => false, 'hidden' => false, 'sort_order' => 0]);
        $device->deviceSources()->create(['source_id' => 'tv:1', 'friendly_name' => 'TV TUNER', 'source_type' => 'TV', 'category' => 'TV', 'borrowed' => false, 'hidden' => false, 'sort_order' => 1]);
        $device->deviceSources()->create(['source_id' => 'hdmi_1:1', 'friendly_name' => 'SET-TOP BOX', 'source_type' => 'HDMI', 'category' => 'TV', 'borrowed' => false, 'hidden' => true, 'sort_order' => 2]);

        $driver->powerOn();

        $this->assertSame('tv:1', $api->bodies[0]['primaryExperience']['source']['id']);
    }

    public function test_a_tv_with_no_synced_sources_cannot_be_woken_and_says_why(): void
    {
        $driver = $this->aseTv($api);

        $this->expectException(UnsupportedOperationException::class);
        $driver->powerOn();
    }

    public function test_the_ase_sources_carry_the_input_and_the_signal(): void
    {
        $driver = $this->aseTv($api);
        $api->seed('BeoZone/Zone/Sources', ['sources' => [
            ['hdmi_2:1', ['friendlyName' => 'HDMI 2', 'sourceType' => ['type' => 'HDMI', 'connector' => 2], 'category' => 'TV', 'inUse' => false, 'signalSensed' => true]],
            ['tv:1', ['friendlyName' => 'TV TUNER', 'sourceType' => ['type' => 'TV'], 'category' => 'TV', 'inUse' => false, 'signalSensed' => false]],
            ['youtube:1', ['friendlyName' => 'YOUTUBE', 'sourceType' => ['type' => 'YOUTUBE'], 'category' => 'MEDIA', 'inUse' => true]],
        ]]);

        [$hdmi, $tuner, $youtube] = $driver->getSources();

        $this->assertSame('HDMI 2', $hdmi->input);
        $this->assertTrue($hdmi->signal);
        $this->assertNull($tuner->input);
        $this->assertFalse($tuner->signal);
        $this->assertNull($youtube->signal);
        $this->assertArrayNotHasKey('signal', $youtube->toArray());
    }
}
