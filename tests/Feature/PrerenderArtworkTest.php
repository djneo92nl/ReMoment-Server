<?php

namespace Tests\Feature;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\LibraryArtwork;
use App\Jobs\ProcessArtwork;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Play;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

/** `artwork:prerender` and the "recently played albums" order it shares with the client pre-cache list and SD export. */
class PrerenderArtworkTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    private Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = Device::create([
            'ip_address' => '10.0.0.10', 'device_name' => 'Living Room', 'device_brand_name' => 'Test',
            'device_product_type' => 'Speaker', 'device_driver' => FakePlayerDriver::class, 'device_driver_name' => 'Fake',
        ]);
        $this->artist = Artist::create(['name' => 'A', 'source' => 'spotify']);
    }

    private function album(string $url): Album
    {
        return Album::create(['artist_id' => $this->artist->id, 'name' => $url, 'source' => 'spotify', 'images' => [['url' => $url]]]);
    }

    private function play(Album $album, string $at): void
    {
        $track = Track::create(['album_id' => $album->id, 'artist_id' => $this->artist->id, 'name' => 'T', 'source' => 'spotify']);
        Play::create(['device_id' => $this->device->id, 'track_id' => $track->id, 'source_type' => 'spotify', 'played_at' => $at]);
    }

    private function completeEntry(): array
    {
        return array_merge(array_fill_keys(ArtworkCache::REQUIRED_KEYS, '/storage/x.jpg'), ['colors' => ['#111111'], 'safe_colors' => ['#999999']]);
    }

    public function test_recent_covers_are_distinct_albums_newest_play_first(): void
    {
        $old = $this->album('https://x.test/old.jpg');
        $new = $this->album('https://x.test/new.jpg');
        $this->album('https://x.test/never-played.jpg');
        $this->play($new, '2026-09-01 10:00:00');
        $this->play($old, '2026-09-02 10:00:00');
        $this->play($new, '2026-09-03 10:00:00');

        $this->assertSame(['https://x.test/new.jpg', 'https://x.test/old.jpg'], LibraryArtwork::recentCoverUrls()->all());
        $this->assertSame(['https://x.test/new.jpg'], LibraryArtwork::recentCoverUrls(1)->all());
    }

    public function test_library_order_lists_played_albums_first_then_the_rest_by_id(): void
    {
        $neverA = $this->album('https://x.test/a.jpg');
        $played = $this->album('https://x.test/played.jpg');
        $neverB = $this->album('https://x.test/b.jpg');
        $this->play($played, '2026-09-01 10:00:00');

        $this->assertSame([$played->id, $neverA->id, $neverB->id], LibraryArtwork::albumsByRecency()->pluck('id')->all());
    }

    public function test_it_queues_only_recent_covers_with_incomplete_artwork(): void
    {
        Queue::fake();
        foreach (['complete', 'outdated', 'missing'] as $i => $name) {
            $this->play($this->album("https://x.test/{$name}.jpg"), '2026-09-0'.($i + 1).' 10:00:00');
        }
        $this->album('https://x.test/never-played.jpg');
        ArtworkCache::put('https://x.test/complete.jpg', $this->completeEntry());
        ArtworkCache::put('https://x.test/outdated.jpg', ['proxy_512' => 'a', 'proxy_320' => 'b', 'colors' => [], 'safe_colors' => []]);

        $this->artisan('artwork:prerender')->assertSuccessful();

        $queued = Queue::pushed(ProcessArtwork::class)->map(fn (ProcessArtwork $job) => $job->originalUrl)->sort()->values()->all();
        $this->assertSame(['https://x.test/missing.jpg', 'https://x.test/outdated.jpg'], $queued);
    }

    public function test_it_is_bounded_per_run_and_does_not_requeue_pending_covers(): void
    {
        Queue::fake();
        foreach (range(1, 5) as $i) {
            $this->play($this->album("https://x.test/{$i}.jpg"), "2026-09-0{$i} 10:00:00");
        }

        $this->artisan('artwork:prerender', ['--max-jobs' => 2])->assertSuccessful();
        Queue::assertPushed(ProcessArtwork::class, 2);

        // The newest two are queued first; the next run moves on to the rest.
        $this->assertSame(['https://x.test/5.jpg', 'https://x.test/4.jpg'], Queue::pushed(ProcessArtwork::class)->map->originalUrl->all());

        $this->artisan('artwork:prerender', ['--max-jobs' => 10])->assertSuccessful();
        Queue::assertPushed(ProcessArtwork::class, 5);
    }

    public function test_limit_only_considers_the_last_n_albums(): void
    {
        Queue::fake();
        $this->play($this->album('https://x.test/older.jpg'), '2026-09-01 10:00:00');
        $this->play($this->album('https://x.test/newer.jpg'), '2026-09-02 10:00:00');

        $this->artisan('artwork:prerender', ['--limit' => 1])->assertSuccessful();

        Queue::assertPushed(ProcessArtwork::class, fn (ProcessArtwork $job) => $job->originalUrl === 'https://x.test/newer.jpg');
        Queue::assertPushed(ProcessArtwork::class, 1);
    }
}
