<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Play;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBareDriver;
use Tests\TestCase;

class PlaySecondsListenedTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sums_finished_plays_in_sql(): void
    {
        $device = Device::create([
            'ip_address' => '10.0.0.1', 'device_name' => 'A', 'device_brand_name' => 'T',
            'device_product_type' => 'S', 'device_driver' => FakeBareDriver::class, 'device_driver_name' => 'Fake',
        ]);
        $start = now()->subHour()->startOfSecond();
        Play::create(['device_id' => $device->id, 'track_name' => 'a', 'played_at' => $start, 'ended_at' => $start->copy()->addSeconds(200)]);
        Play::create(['device_id' => $device->id, 'track_name' => 'b', 'played_at' => $start, 'ended_at' => $start->copy()->addSeconds(100)]);
        Play::create(['device_id' => $device->id, 'track_name' => 'running', 'played_at' => $start]);

        $this->assertSame(300, Play::secondsListened());
        $this->assertSame(200, Play::secondsListened(Play::where('track_name', 'a')));
        $this->assertSame(0, Play::secondsListened(Play::where('track_name', 'running')));
    }
}
