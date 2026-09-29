<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Health\Heartbeat;
use App\Domain\Media\NowPlaying as NowPlayingData;
use App\Domain\Media\TrackData;
use App\Livewire\DeviceCard;
use App\Livewire\DeviceQueue;
use App\Livewire\Nowplaying;
use App\Livewire\SystemHealth;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

class LivewireControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakePlayerDriver::reset();
    }

    private function makeDevice(State $state = State::Playing): Device
    {
        $device = Device::create([
            'ip_address' => '10.0.0.10',
            'device_name' => 'Living Room',
            'device_brand_name' => 'Test',
            'device_product_type' => 'Speaker',
            'device_driver' => FakePlayerDriver::class,
            'device_driver_name' => 'Fake',
        ]);
        DeviceCache::updateState($device->id, $state);

        return $device;
    }

    public function test_device_card_seeks_and_toggles_mute(): void
    {
        $device = $this->makeDevice();

        Livewire::test(DeviceCard::class, ['device' => $device, 'standalone' => true])
            ->assertSet('supportsSeek', true)
            ->call('seek', 75)
            ->call('toggleMute')
            ->assertSet('muted', true)
            ->call('toggleMute')
            ->assertSet('muted', false);

        $this->assertContains(['seek', 75], FakePlayerDriver::$calls);
        $this->assertContains(['mute', null], FakePlayerDriver::$calls);
        $this->assertContains(['unmute', null], FakePlayerDriver::$calls);
    }

    public function test_playing_cards_render_a_seekable_progress_ticker(): void
    {
        $device = $this->makeDevice();
        $nowPlaying = new NowPlayingData(track: new TrackData(id: 't1', name: 'Song', duration: 200), state: 'playing', position: 50, type: 'music');
        (new DeviceCache)->updateNowPlaying($device->id, $nowPlaying);
        cache()->put("listener_running_{$device->id}", true, 10);

        Livewire::test(DeviceCard::class, ['device' => $device])
            ->assertSeeHtml("liveDevice({$device->id})")
            ->assertSeeHtml("progressTicker({$device->id}, 50, 200, true)")
            ->assertSeeHtml('@click.stop="seekTo($event)"')
            ->assertDontSeeHtml('wire:poll');

        Livewire::test(Nowplaying::class, ['device' => $device])
            ->assertSeeHtml("progressTicker({$device->id}, 50, 200, true)")
            ->assertSeeHtml('toggleMute');
    }

    public function test_device_queue_loads_up_next_after_init(): void
    {
        $device = $this->makeDevice();

        Livewire::test(DeviceQueue::class, ['device' => $device])
            ->assertDontSee('Next Song')
            ->call('load')
            ->assertSee('Next Song')
            ->assertSee('Next Artist · Next Album');
    }

    public function test_device_queue_reports_driver_errors(): void
    {
        $device = $this->makeDevice();
        FakePlayerDriver::$throw = new \RuntimeException('offline');

        Livewire::test(DeviceQueue::class, ['device' => $device])
            ->call('load')
            ->assertSee('Could not read the queue from the device.');
    }

    public function test_system_health_shows_heartbeats_and_devices(): void
    {
        $this->makeDevice();
        Heartbeat::beat(Heartbeat::SCHEDULER);

        Livewire::test(SystemHealth::class)
            ->assertSee('Running')
            ->assertSee('Not processing')
            ->assertSee('Living Room')
            ->assertSee('Listener down');
    }

    public function test_health_page_requires_the_admin_login(): void
    {
        $this->get('/settings/health')->assertRedirect();

        $this->actingAs(User::factory()->create())->get('/settings/health')->assertOk();
    }
}
