<?php

namespace Tests\Feature\Library;

use App\Domain\Library\LibraryCache;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WarmLibraryCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_warms_every_scope_and_the_api_overviews(): void
    {
        $artist = Artist::create(['name' => 'Band', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'LP', 'source' => 'dlna']);
        Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'name' => 'Song', 'external_id' => 'dlna:1', 'source' => 'dlna']);

        $this->artisan('library:warm')
            ->expectsOutputToContain('Warmed local.')
            ->expectsOutputToContain('Warmed streaming.')
            ->expectsOutputToContain('Warmed all.')
            ->assertSuccessful();

        $entry = Cache::get('library:api-stats:dlna:'.md5(implode(',', \App\Domain\Library\LibrarySources::allBut('dlna'))));
        $this->assertIsArray($entry);
        $this->assertSame(1, $entry['v']['albums']);

        // A later visit is a cache read: no recompute, however often it is asked.
        LibraryCache::observe('select 1');
        $this->getJson('/api/library/libraries')->assertOk()->assertJsonPath('data.0.album_count', 1);
    }
}
