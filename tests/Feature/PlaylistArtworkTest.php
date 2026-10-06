<?php

namespace Tests\Feature;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\PlaylistArtwork;
use App\Domain\Artwork\SourceLogo;
use App\Jobs\ProcessArtwork;
use App\Models\Client;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Generated playlist covers: a 2×2 mosaic of the playlist's top 4 album covers (PlaylistArtwork). */
class PlaylistArtworkTest extends TestCase
{
    use RefreshDatabase;

    private Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artist = Artist::create(['name' => 'A', 'source' => 'spotify']);
    }

    private function album(?string $cover): Album
    {
        return Album::create(['artist_id' => $this->artist->id, 'name' => uniqid('album ', true), 'source' => 'spotify', 'images' => $cover ? [['url' => $cover]] : null]);
    }

    /** A playlist of these albums' tracks, in order (an album repeated = several of its tracks). */
    private function playlist(array $albums, array $attributes = []): Playlist
    {
        $playlist = Playlist::create(['name' => 'Mix', 'source' => 'local'] + $attributes);
        $sync = [];
        foreach (array_values($albums) as $i => $album) {
            $track = Track::create(['album_id' => $album?->id, 'artist_id' => $this->artist->id, 'name' => "T{$i}", 'source' => 'spotify']);
            $sync[$track->id] = ['position' => $i];
        }
        $playlist->tracks()->sync($sync);

        return $playlist;
    }

    private function choice(Playlist $playlist): ?array
    {
        return PlaylistArtwork::choose(collect([$playlist]))[$playlist->id];
    }

    private function processed(string $url): void
    {
        ArtworkCache::put($url, array_fill_keys(ArtworkCache::REQUIRED_KEYS, '/storage/x.jpg'));
    }

    private function jpeg(array $rgb): string
    {
        $gd = imagecreatetruecolor(300, 300);
        imagefill($gd, 0, 0, imagecolorallocate($gd, ...$rgb));
        ob_start();
        imagejpeg($gd, null, 95);

        return ob_get_clean();
    }

    public function test_the_four_albums_with_most_tracks_ties_by_playlist_order(): void
    {
        [$a, $b, $c, $d, $e] = array_map(fn ($n) => $this->album("https://x.test/{$n}.jpg"), ['a', 'b', 'c', 'd', 'e']);
        $none = $this->album(null);
        $playlist = $this->playlist([$a, $none, $none, $none, $b, $c, $c, $d, $e, $e, null]);

        $choice = $this->choice($playlist);

        $sources = ['https://x.test/c.jpg', 'https://x.test/e.jpg', 'https://x.test/a.jpg', 'https://x.test/b.jpg'];
        $this->assertSame(['url' => PlaylistArtwork::compositeUrl($sources), 'sources' => $sources, 'from' => 'composite'], $choice);
        $this->assertSame('remoment:playlist/v1/'.md5(implode("\n", $sources)), $choice['url']);
        $this->assertTrue(PlaylistArtwork::isCompositeUrl($choice['url']));
        $this->assertFalse(PlaylistArtwork::isCompositeUrl(SourceLogo::url('music')));
    }

    public function test_fewer_than_four_distinct_covers_use_the_first_albums_cover(): void
    {
        $a = $this->album('https://x.test/a.jpg');
        $b = $this->album('https://x.test/b.jpg');
        $sameAsA = $this->album('https://x.test/a.jpg');
        $c = $this->album('https://x.test/c.jpg');

        $this->assertSame(
            ['url' => 'https://x.test/b.jpg', 'sources' => [], 'from' => 'album'],
            $this->choice($this->playlist([$a, $b, $b, $sameAsA, $c])),
        );
    }

    public function test_without_album_covers_the_playlists_own_image_or_nothing(): void
    {
        $this->assertSame(
            ['url' => 'https://x.test/own.jpg', 'sources' => [], 'from' => 'playlist'],
            $this->choice($this->playlist([$this->album(null)], ['images' => [['url' => 'https://x.test/own.jpg']]])),
        );
        $this->assertNull($this->choice($this->playlist([null])));
    }

    public function test_the_composite_url_changes_with_the_chosen_covers(): void
    {
        $albums = array_map(fn ($n) => $this->album("https://x.test/{$n}.jpg"), [1, 2, 3, 4]);
        $playlist = $this->playlist($albums);
        $before = $this->choice($playlist)['url'];

        $playlist->tracks()->detach($playlist->tracks()->first()->id);
        $playlist->tracks()->attach(Track::create(['album_id' => $this->album('https://x.test/5.jpg')->id, 'artist_id' => $this->artist->id, 'name' => 'New', 'source' => 'spotify'])->id, ['position' => 9]);

        $this->assertNotSame($before, $this->choice($playlist)['url']);
    }

    public function test_process_artwork_renders_the_mosaic_through_the_normal_pipeline(): void
    {
        Storage::fake('public');
        $sources = ['https://x.test/red.jpg', 'https://x.test/green.jpg', 'https://x.test/blue.jpg', 'https://x.test/processed.jpg'];
        Http::fake([
            'https://x.test/red.jpg' => Http::response($this->jpeg([220, 30, 30])),
            'https://x.test/green.jpg' => Http::response($this->jpeg([30, 200, 30])),
            'https://x.test/blue.jpg' => Http::response($this->jpeg([30, 30, 220])),
        ]);
        // An already processed cover is read from its 512 proxy, not downloaded.
        Storage::disk('public')->put('artwork/'.md5($sources[3]).'/512.jpg', $this->jpeg([240, 240, 240]));

        $url = PlaylistArtwork::compositeUrl($sources);
        (new ProcessArtwork($url, $sources))->handle();

        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request) => $request->url() === $sources[3]);

        $entry = ArtworkCache::get($url);
        $this->assertTrue(ArtworkCache::has($url));
        $this->assertCount(5, $entry['safe_colors']);

        $dir = 'artwork/'.md5($url);
        foreach (['512.jpg', '320.jpg', '120.jpg', 'bg_1024x600.jpg', 'bg_320x480.jpg', 'bg_480x480.jpg'] as $file) {
            $bytes = Storage::disk('public')->get("{$dir}/{$file}");
            $this->assertStringContainsString("\xFF\xC0", $bytes, "{$file} is not baseline");
            $this->assertStringNotContainsString("\xFF\xC2", $bytes, "{$file} is progressive");
        }

        $gd = imagecreatefromstring(Storage::disk('public')->get("{$dir}/512.jpg"));
        $this->assertSame([512, 512], [imagesx($gd), imagesy($gd)]);
        $at = fn (int $x, int $y) => array_values(array_slice(imagecolorsforindex($gd, imagecolorat($gd, $x, $y)), 0, 3));
        [$r, $g, $b] = $at(128, 128);
        $this->assertTrue($r > 180 && $g < 80, 'top left is the first cover');
        [$r, $g, $b] = $at(384, 128);
        $this->assertTrue($g > 160 && $r < 80, 'top right is the second cover');
        [$r, $g, $b] = $at(128, 384);
        $this->assertTrue($b > 180 && $r < 80, 'bottom left is the third cover');
        [$r, $g, $b] = $at(384, 384);
        $this->assertTrue(min($r, $g, $b) > 200, 'bottom right is the fourth cover');
    }

    public function test_a_composite_without_its_covers_is_skipped(): void
    {
        Storage::fake('public');
        Http::fake();
        $url = PlaylistArtwork::compositeUrl(['a', 'b', 'c', 'd']);

        (new ProcessArtwork($url))->handle();
        (new ProcessArtwork($url, ['a', 'b', 'c', 'x']))->handle();

        $this->assertNull(ArtworkCache::get($url));
        Http::assertNothingSent();
    }

    public function test_prerender_and_backfill_queue_missing_playlist_covers(): void
    {
        Queue::fake();
        $albums = array_map(fn ($n) => $this->album("https://x.test/{$n}.jpg"), [1, 2, 3, 4]);
        foreach ($albums as $album) {
            $this->processed($album->images[0]['url']);
        }
        $this->playlist($albums);
        $sources = array_map(fn (Album $album) => $album->images[0]['url'], $albums);
        $url = PlaylistArtwork::compositeUrl($sources);

        $this->artisan('artwork:prerender')->assertSuccessful();
        Queue::assertPushed(ProcessArtwork::class, fn (ProcessArtwork $job) => $job->originalUrl === $url && $job->sources === $sources);

        // Not queued again by the next run while it waits in the queue.
        $this->artisan('artwork:prerender')->assertSuccessful();
        Queue::assertPushed(ProcessArtwork::class, 1);

        $this->artisan('library:backfill-artwork')->assertSuccessful();
        Queue::assertPushed(ProcessArtwork::class, fn (ProcessArtwork $job) => $job->originalUrl === $url && $job->sources === $sources);

        $this->processed($url);
        Queue::fake();
        $this->artisan('library:backfill-artwork')->assertSuccessful();
        Queue::assertNotPushed(ProcessArtwork::class, fn (ProcessArtwork $job) => $job->originalUrl === $url);
    }

    public function test_the_client_pre_cache_list_has_processed_composites_as_playlist_items(): void
    {
        Queue::fake();
        foreach (SourceLogo::KEYS as $key) {
            $this->processed(SourceLogo::url($key));
        }
        $albums = array_map(fn ($n) => $this->album("https://x.test/{$n}.jpg"), [1, 2, 3, 4]);
        $this->playlist($albums);
        $composite = PlaylistArtwork::compositeUrl(array_map(fn (Album $album) => $album->images[0]['url'], $albums));
        // A single-album playlist's cover is the album's: listed once, as an album.
        $this->playlist([$albums[0]]);
        $this->processed($composite);
        $this->processed('https://x.test/1.jpg');

        $client = Client::create([
            'status' => 'approved', 'type' => 'multi',
            'registration_token' => Client::generateToken(), 'api_token' => Client::generateToken(),
        ]);

        $data = collect($this->getJson("/api/clients/{$client->api_token}/artwork")->assertOk()->json('data'));

        $this->assertSame([md5($composite)], $data->where('kind', 'playlist')->pluck('hash')->all());
        $this->assertSame([md5('https://x.test/1.jpg')], $data->where('kind', 'album')->pluck('hash')->all());
        $this->assertSame(['source', 'playlist', 'album'], $data->pluck('kind')->unique()->values()->all());
        Queue::assertNothingPushed();
    }
}
