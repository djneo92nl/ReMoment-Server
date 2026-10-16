<?php

namespace Tests\Feature\Api;

use App\Domain\Library\LeadingSource;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBareDriver;
use Tests\Support\FakeLibraryPlaybackDriver;
use Tests\Support\FakesSpotify;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** GET /api/library/random-album (docs/api/library.md). */
class LibraryRandomAlbumApiTest extends TestCase
{
    use FakesSpotify;
    use MakesLibraryTracks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set(LeadingSource::SETTING, 'all');
        Queue::fake();
    }

    private function album(string $name, bool $dlna, ?string $spotifyId = null): Album
    {
        $artist = Artist::firstOrCreate(['name' => 'Muse'], ['source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => $name, 'source' => $dlna ? 'dlna' : 'spotify']);
        $this->makeLibraryTrack($album, "{$name} track", $dlna, $spotifyId);

        return $album;
    }

    private function names(string $url, int $times = 25): array
    {
        $names = [];
        for ($i = 0; $i < $times; $i++) {
            $names[] = $this->getJson($url)->assertOk()->json('data.name');
        }

        return array_values(array_unique($names));
    }

    public function test_it_returns_an_album_item_and_can_return_each_album(): void
    {
        $this->album('One', true);
        $this->album('Two', true);

        $response = $this->getJson('/api/library/random-album')->assertOk();
        $response->assertJsonStructure(['data' => ['id', 'name', 'artist_name', 'year', 'artwork', 'favorite']]);

        $names = $this->names('/api/library/random-album', 40);
        sort($names);
        $this->assertSame(['One', 'Two'], $names);
    }

    public function test_the_pick_stays_inside_the_scope(): void
    {
        $this->album('Local', true);
        $this->album('Streamed', false, 'abc');

        $this->assertSame(['Local'], $this->names('/api/library/random-album?scope=files'));
        $this->assertSame(['Streamed'], $this->names('/api/library/random-album?scope=spotify'));
        $this->assertSame(['Local'], $this->names('/api/library/random-album?scope=local'));
        $this->assertSame(['Streamed'], $this->names('/api/library/random-album?scope=streaming'));
    }

    public function test_a_library_the_client_hides_is_not_picked(): void
    {
        $this->album('Local', true);
        $this->album('Streamed', false, 'abc');
        $client = \App\Models\Client::create([
            'name' => 'Kitchen', 'type' => 'multi', 'status' => 'approved',
            'registration_token' => \App\Models\Client::generateToken(), 'api_token' => \App\Models\Client::generateToken(), 'hidden_sources' => ['spotify'],
        ]);

        $this->assertSame(['Local'], $this->names('/api/library/random-album?scope=all&client='.$client->api_token));
        $this->getJson('/api/library/random-album?scope=spotify&client='.$client->api_token)->assertNotFound();
    }

    public function test_an_empty_library_is_404_and_an_unknown_scope_422(): void
    {
        $this->album('Local', true);

        $this->getJson('/api/library/random-album?scope=spotify')->assertNotFound()->assertJsonPath('message', 'No albums in this library.');
        $this->getJson('/api/library/random-album?scope=nope')->assertStatus(422)->assertJsonValidationErrors('scope');
        $this->getJson('/api/library/random-album?device_id=999')->assertStatus(422)->assertJsonValidationErrors('device_id');
    }

    public function test_a_device_prefers_an_album_it_can_play_else_any(): void
    {
        $this->album('Plays', true);
        $this->album('Streamed', false, 'abc');
        $device = Device::factory()->create(['ip_address' => '10.0.0.1', 'device_driver' => FakeLibraryPlaybackDriver::class]);

        $this->assertSame(['Plays'], $this->names("/api/library/random-album?device_id={$device->id}"));

        // Nothing is playable on a device without library playback: any album comes back.
        $bare = Device::factory()->create(['ip_address' => '10.0.0.2', 'device_driver' => FakeBareDriver::class]);
        $names = $this->names("/api/library/random-album?device_id={$bare->id}", 40);
        sort($names);
        $this->assertSame(['Plays', 'Streamed'], $names);

        // Only unplayable albums in scope: falls back to one of them.
        $this->assertSame(['Streamed'], $this->names("/api/library/random-album?scope=spotify&device_id={$device->id}", 3));
    }
}
