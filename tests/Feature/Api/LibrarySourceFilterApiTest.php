<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A client's hidden_sources filter on /api/library/* (docs/api/library.md, "Hiding sources"). */
class LibrarySourceFilterApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\Setting::set(\App\Domain\Library\LeadingSource::SETTING, 'all');
    }

    private function client(array $hidden): Client
    {
        return Client::create([
            'name' => 'Kitchen', 'type' => 'multi', 'status' => 'approved',
            'registration_token' => Client::generateToken(), 'api_token' => Client::generateToken(), 'hidden_sources' => $hidden,
        ]);
    }

    /** An artist with one album of one track from $source. */
    private function release(string $name, string $source): Track
    {
        $artist = Artist::create(['name' => $name, 'source' => $source]);
        $album = Album::create(['artist_id' => $artist->id, 'name' => "{$name} LP", 'source' => $source]);

        return Track::create([
            'album_id' => $album->id, 'artist_id' => $artist->id, 'name' => "{$name} song",
            'external_id' => "{$source}:{$name}", 'source' => $source,
        ]);
    }

    public function test_no_client_sees_everything(): void
    {
        $this->release('Dlna Band', 'dlna');
        $this->release('Spot Band', 'spotify');

        $this->getJson('/api/library/artists')->assertJsonCount(2, 'data');
    }

    public function test_unknown_client_is_404(): void
    {
        $this->getJson('/api/library/artists?client=nope')->assertNotFound();
    }
}
