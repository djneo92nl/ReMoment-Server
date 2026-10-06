<?php

namespace Tests\Feature\Library;

use App\Jobs\FetchSpotifyArtistImages;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Services\SpotifyTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use SpotifyWebAPI\SpotifyWebAPI;
use Tests\TestCase;

class ArtistImagesTest extends TestCase
{
    use RefreshDatabase;

    private function connect(SpotifyWebAPI $api): void
    {
        $this->mock(SpotifyTokenService::class, function ($mock) use ($api) {
            $mock->shouldReceive('makeApiClient')->andReturn($api);
            $mock->shouldReceive('isConnected')->andReturn(true);
        });
    }

    private function artist(string $name, ?string $spotifyId = null): Artist
    {
        $artist = Artist::create(['name' => $name, 'source' => 'spotify']);
        if ($spotifyId) {
            Metadata::create(['metadatable_type' => Artist::class, 'metadatable_id' => $artist->id, 'key' => 'spotify_id', 'value' => $spotifyId, 'type' => 'string', 'source' => 'spotify']);
        }

        return $artist;
    }

    public function test_job_stores_the_smallest_image_that_is_big_enough_and_looks_up_unknown_artists(): void
    {
        $known = $this->artist('Muse', 'm1');
        $unknown = $this->artist('Coldplay');
        $nothing = $this->artist('Nobody Special');

        $api = Mockery::mock(SpotifyWebAPI::class);
        $api->shouldReceive('search')->with('artist:Coldplay', 'artist', Mockery::any())
            ->andReturn(['artists' => ['items' => [['id' => 'c1', 'name' => 'Coldplay']]]]);
        $api->shouldReceive('search')->with('artist:Nobody Special', 'artist', Mockery::any())
            ->andReturn(['artists' => ['items' => [['id' => 'x', 'name' => 'Someone Else']]]]);
        $api->shouldReceive('getArtists')->once()->with(['m1', 'c1'])->andReturn(['artists' => [
            ['id' => 'm1', 'images' => [['url' => 'big', 'width' => 640], ['url' => 'mid', 'width' => 320], ['url' => 'tiny', 'width' => 160]]],
            ['id' => 'c1', 'images' => []],
        ]]);
        $this->connect($api);

        (new FetchSpotifyArtistImages([$known->id, $unknown->id, $nothing->id]))->handle(app(SpotifyTokenService::class));

        $this->assertSame('mid', $known->metaValue('spotify_image'));
        $this->assertSame('mid', $known->photoUrl());
        $this->assertNull($unknown->fresh()->metaValue('spotify_image'));
        $this->assertSame('c1', $unknown->metaValue('spotify_id'));
        // Both "no photo" outcomes are recorded, so they aren't asked again.
        $this->assertTrue($unknown->metadata()->where('key', 'spotify_image')->exists());
        $this->assertTrue($nothing->metadata()->where('key', 'spotify_image')->exists());
    }

    public function test_command_queues_artists_without_a_photo_in_batches(): void
    {
        Queue::fake();
        $with = $this->artist('Muse', 'm1');
        $without = $this->artist('Coldplay');
        $done = $this->artist('Done', 'd1');
        foreach ([$with, $without, $done] as $a) {
            \App\Models\Media\Album::create(['artist_id' => $a->id, 'name' => 'X', 'source' => 'spotify']);
        }
        Metadata::create(['metadatable_type' => Artist::class, 'metadatable_id' => $done->id, 'key' => 'spotify_image', 'value' => 'u', 'type' => 'url', 'source' => 'spotify']);
        $this->connect(Mockery::mock(SpotifyWebAPI::class));

        $this->artisan('library:artist-images')->assertSuccessful();

        Queue::assertPushed(FetchSpotifyArtistImages::class, 2);
        Queue::assertPushed(FetchSpotifyArtistImages::class, fn ($job) => $job->artistIds === [$with->id]);
        Queue::assertPushed(FetchSpotifyArtistImages::class, fn ($job) => $job->artistIds === [$without->id]);
    }
}
