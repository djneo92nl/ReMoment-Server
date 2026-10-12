<?php

namespace Tests\Feature\Api;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Library\LeadingSource;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CachesArtwork;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** The small library artwork object carries the palette ProcessArtwork stored (docs/api/library.md). */
class LibraryArtworkColorsApiTest extends TestCase
{
    use CachesArtwork;
    use MakesLibraryTracks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set(LeadingSource::SETTING, 'all');
        Queue::fake();
    }

    private function album(string $name, string $cover): Album
    {
        $artist = Artist::firstOrCreate(['name' => 'Muse'], ['source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => $name, 'source' => 'dlna', 'images' => [['url' => $cover]]]);
        $this->makeLibraryTrack($album, "{$name} track");

        return $album;
    }

    public function test_album_artist_search_recent_and_libraries_carry_the_colours(): void
    {
        $url = 'https://img.test/a.jpg';
        $this->cacheProcessedArtwork($url, ['proxy_320' => '/storage/a/320.jpg', 'proxy_120' => '/storage/a/120.jpg', 'colors' => ['#102030', '#405060'], 'safe_colors' => ['#a0b0c0', '#d0e0f0']]);
        $album = $this->album('Absolution', $url);
        \App\Models\Play::create(['track_id' => $album->tracks()->first()->id, 'device_id' => \App\Models\Device::factory()->create()->id, 'played_at' => now()]);

        $expected = [
            'hash' => md5($url),
            'proxy_120' => '/storage/a/120.jpg',
            'proxy_320' => '/storage/a/320.jpg',
            'colors' => ['#102030', '#405060'],
            'safe_colors' => ['#a0b0c0', '#d0e0f0'],
        ];

        $this->getJson('/api/library/albums')->assertJsonPath('data.0.artwork', $expected);
        $this->getJson("/api/library/albums/{$album->id}")->assertJsonPath('artwork', $expected);
        $this->getJson('/api/library/artists')->assertJsonPath('data.0.artwork', $expected);
        $this->getJson('/api/library/recent')->assertJsonPath('data.0.artwork', $expected);
        $this->getJson('/api/library/recent-items')->assertJsonPath('data.0.album.artwork', $expected);
        $this->getJson('/api/library/libraries')->assertJsonPath('data.0.artwork', $expected);
        $this->getJson('/api/library/favorites')->assertOk();
        $this->getJson('/api/library/search?q=Absolution')->assertJsonPath('albums.0.artwork', $expected);
        $this->getJson('/api/library/search?q=Mus')->assertJsonPath('artists.0.artwork', $expected);
        $this->getJson('/api/library/search?q=track')->assertJsonPath('tracks.0.artwork', $expected);
    }

    public function test_colours_are_null_but_present_when_the_entry_expired_and_the_files_remain(): void
    {
        $url = 'https://img.test/old.jpg';
        $this->album('Old', $url);
        foreach (['320.jpg', '120.jpg'] as $file) {
            Storage::disk('public')->put('artwork/'.md5($url).'/'.$file, 'x');
        }
        ArtworkCache::forget($url);

        $artwork = $this->getJson('/api/library/albums')->json('data.0.artwork');

        $this->assertSame(['hash', 'proxy_120', 'proxy_320', 'colors', 'safe_colors'], array_keys($artwork));
        $this->assertNull($artwork['colors']);
        $this->assertNull($artwork['safe_colors']);
    }

    public function test_an_entry_from_before_the_palette_has_null_colours_and_unprocessed_stays_null(): void
    {
        $url = 'https://img.test/legacy.jpg';
        ArtworkCache::put($url, ['proxy_320' => '/storage/l/320.jpg', 'proxy_120' => '/storage/l/120.jpg']);
        $this->album('Legacy', $url);
        $this->album('Pending', 'https://img.test/pending.jpg');

        $data = $this->getJson('/api/library/albums')->json('data');
        $byName = collect($data)->keyBy('name');

        $this->assertNull($byName['Legacy']['artwork']['colors']);
        $this->assertNull($byName['Legacy']['artwork']['safe_colors']);
        $this->assertNull($byName['Pending']['artwork']);
    }

    public function test_a_page_costs_one_cache_round_trip_for_all_covers(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->cacheProcessedArtwork("https://img.test/{$i}.jpg");
            $this->album("Album {$i}", "https://img.test/{$i}.jpg");
        }

        $reads = 0;
        $this->app['events']->listen(\Illuminate\Cache\Events\CacheHit::class, function ($event) use (&$reads) {
            if (str_starts_with($event->key, 'artwork:') && !str_starts_with($event->key, 'artwork:library-queued:')) {
                $reads++;
            }
        });

        $this->getJson('/api/library/albums')->assertOk()->assertJsonCount(12, 'data');

        // Per item it was two reads (has + get); preloaded it is one many() for the page, which fires one hit per key.
        $this->assertLessThanOrEqual(12, $reads);
    }
}
