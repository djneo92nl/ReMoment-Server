<?php

namespace Tests\Feature\Api;

use App\Domain\Library\LeadingSource;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
}
