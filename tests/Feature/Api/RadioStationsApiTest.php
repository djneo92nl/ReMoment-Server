<?php

namespace Tests\Feature\Api;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Jobs\ProcessArtwork;
use App\Models\Client;
use App\Models\Device;
use App\Models\RadioStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBareDriver;
use Tests\Support\FakeRadioDriver;
use Tests\TestCase;

class RadioStationsApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeDevice(string $driver = FakeRadioDriver::class, State $state = State::Standby): Device
    {
        $device = Device::factory()->create([
            'device_name' => 'Kitchen',
            'device_driver' => $driver,
        ]);

        DeviceCache::updateState($device->id, $state);

        return $device;
    }

    private function station(string $name, ?string $platform = 'fakeradio', ?string $imageUrl = null): RadioStation
    {
        $station = RadioStation::create(['name' => $name, 'image_url' => $imageUrl]);
        if ($platform) {
            $station->setMeta($platform, strtolower($name));
        }

        return $station;
    }

    public function test_lists_compatible_stations_by_name(): void
    {
        Queue::fake();
        $device = $this->makeDevice();
        $zulu = $this->station('Zulu FM');
        $alpha = $this->station('Alpha Radio');
        $this->station('Other Platform', platform: 'tunein');

        $this->getJson("/api/devices/{$device->id}/radio")
            ->assertOk()
            ->assertJsonCount(2, 'stations')
            ->assertJsonPath('stations.0.id', $alpha->id)
            ->assertJsonPath('stations.0.name', 'Alpha Radio')
            ->assertJsonPath('stations.0.genre', null)
            ->assertJsonPath('stations.1.id', $zulu->id);
    }

    public function test_a_client_hiding_radio_gets_no_stations(): void
    {
        $device = $this->makeDevice();
        $this->station('Alpha Radio');
        $client = Client::create([
            'type' => 'multi', 'status' => 'approved', 'hidden_sources' => ['radio'],
            'registration_token' => Client::generateToken(), 'api_token' => Client::generateToken(),
        ]);

        $this->getJson("/api/devices/{$device->id}/radio?client={$client->api_token}")
            ->assertOk()->assertExactJson(['stations' => []]);
        $this->getJson("/api/devices/{$device->id}/radio")->assertJsonCount(1, 'stations');
    }

    public function test_a_station_without_an_image_gets_the_radio_logo(): void
    {
        $device = $this->makeDevice();
        $this->station('Alpha Radio');

        $artwork = $this->getJson("/api/devices/{$device->id}/radio")->json('stations.0.artwork');

        $this->assertSame('radio', $artwork['kind']);
        foreach (ArtworkCache::REQUIRED_KEYS as $key) {
            $this->assertArrayHasKey($key, $artwork);
        }
    }

    public function test_an_unprocessed_station_image_is_null_and_queued_once(): void
    {
        Queue::fake();
        $device = $this->makeDevice();
        $this->station('Alpha Radio', imageUrl: 'https://img.test/alpha.png');

        $this->getJson("/api/devices/{$device->id}/radio")->assertJsonPath('stations.0.artwork', null);
        $this->getJson("/api/devices/{$device->id}/radio")->assertJsonPath('stations.0.artwork', null);

        Queue::assertPushed(ProcessArtwork::class, 1);
    }

    public function test_a_processed_station_image_is_returned(): void
    {
        Queue::fake();
        $device = $this->makeDevice();
        $this->station('Alpha Radio', imageUrl: 'https://img.test/alpha.png');
        ArtworkCache::put('https://img.test/alpha.png', array_merge(
            array_fill_keys(ArtworkCache::REQUIRED_KEYS, '/storage/artwork/x/320.jpg'),
            ['colors' => ['#111111'], 'safe_colors' => ['#999999']],
        ));

        $this->getJson("/api/devices/{$device->id}/radio")
            ->assertJsonPath('stations.0.artwork.kind', 'radio')
            ->assertJsonPath('stations.0.artwork.proxy_320', '/storage/artwork/x/320.jpg');

        Queue::assertNothingPushed();
    }

    public function test_device_without_radio_control_gets_422(): void
    {
        $device = $this->makeDevice(FakeBareDriver::class);

        $this->getJson("/api/devices/{$device->id}/radio")
            ->assertStatus(422)
            ->assertJsonPath('error', 'unsupported');
    }

    public function test_unreachable_device_gets_503(): void
    {
        $device = $this->makeDevice(state: State::Unreachable);

        $this->getJson("/api/devices/{$device->id}/radio")->assertStatus(503);
    }
}
