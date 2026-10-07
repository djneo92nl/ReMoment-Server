<?php

namespace Tests\Feature\Listeners\Device;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\TrackData;
use App\Events\Device\DeviceStateChanged;
use App\Listeners\Device\SwitchToDefaultSource;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSourceDriver;
use Tests\TestCase;

class SwitchToDefaultSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeSourceDriver::$activated = [];
    }

    private function device(?string $default = 'tv'): Device
    {
        $device = Device::create([
            'ip_address' => '10.0.0.10',
            'device_name' => 'TV',
            'device_brand_name' => 'Test',
            'device_product_type' => 'Soundbar',
            'device_driver' => FakeSourceDriver::class,
            'device_driver_name' => 'Fake',
        ]);

        if ($default) {
            $device->meta()->create(['key' => SwitchToDefaultSource::META_KEY, 'value' => $default]);
        }

        return $device;
    }

    private function wake(Device $device, State $state = State::Playing, ?State $previous = State::Standby): void
    {
        $event = new DeviceStateChanged($device->id, $state, $previous);
        $listener = new SwitchToDefaultSource;

        if ($listener->shouldQueue($event)) {
            $listener->handle($event);
        }
    }

    public function test_waking_without_content_switches_to_the_default_input(): void
    {
        $this->wake($this->device());

        $this->assertSame(['tv'], FakeSourceDriver::$activated);
    }

    public function test_nothing_happens_without_a_default_input(): void
    {
        $this->wake($this->device(default: null));

        $this->assertSame([], FakeSourceDriver::$activated);
    }

    public function test_only_a_wake_switches(): void
    {
        $device = $this->device();

        $this->wake($device, State::Playing, State::Paused);
        $this->wake($device, State::Standby, State::Playing);

        $this->assertSame([], FakeSourceDriver::$activated);
    }

    public function test_a_track_that_started_the_wake_is_left_alone(): void
    {
        $device = $this->device();
        DeviceCache::updateNowPlaying($device->id, new NowPlaying(track: new TrackData(name: 'Song')));

        $this->wake($device);

        $this->assertSame([], FakeSourceDriver::$activated);
    }

    public function test_a_failing_device_does_not_break_the_queue(): void
    {
        FakeSourceDriver::$throw = new \RuntimeException('no answer');

        $this->wake($this->device());

        $this->assertSame([], FakeSourceDriver::$activated);
        FakeSourceDriver::$throw = null;
    }
}
