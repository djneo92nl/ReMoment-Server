<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\TrackData;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

class EasterEggsTest extends TestCase
{
    use RefreshDatabase;

    private function playSomething(): void
    {
        $device = Device::create([
            'ip_address' => '10.0.0.10',
            'device_name' => 'Living Room',
            'device_brand_name' => 'Test',
            'device_product_type' => 'Speaker',
            'device_driver' => FakePlayerDriver::class,
            'device_driver_name' => 'Fake',
        ]);

        DeviceCache::updateState($device->id, State::Playing);
        (new DeviceCache)->updateNowPlaying($device->id, new NowPlaying(
            track: new TrackData(name: 'Café del Mar', artist: new ArtistData(name: 'Energy 52')),
            state: 'playing',
        ));
    }

    public function test_api_hums_silence_when_nothing_plays(): void
    {
        $this->getJson('/api/devices')->assertHeader('X-Now-Humming', 'silence');
    }

    public function test_api_hums_along_with_the_playing_track(): void
    {
        $this->playSomething();

        $this->getJson('/api/devices')->assertHeader('X-Now-Humming', 'Cafe del Mar - Energy 52');
    }

    public function test_vibe_renders_silence_when_idle(): void
    {
        $this->artisan('remoment:vibe --once')
            ->expectsOutputToContain('silence')
            ->assertSuccessful();
    }

    public function test_vibe_shows_the_playing_track(): void
    {
        $this->playSomething();

        $this->artisan('remoment:vibe --once')
            ->expectsOutputToContain('Energy 52')
            ->assertSuccessful();
    }
}
