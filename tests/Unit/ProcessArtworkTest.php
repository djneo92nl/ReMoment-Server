<?php

namespace Tests\Unit;

use App\Jobs\ProcessArtwork;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class ProcessArtworkTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Eloquent's array cast JSON-encodes without JSON_UNESCAPED_SLASHES, so a
     * stored `images` column contains "https:\/\/..." — a naive LIKE match
     * against the raw (unescaped) URL never matches. This is the exact bug
     * that also broke the original whereJsonContains() implementation.
     */
    public function test_apply_colors_matches_urls_despite_json_slash_escaping(): void
    {
        $artist = Artist::create(['name' => 'Test Artist', 'source' => 'dlna']);
        $album = Album::create([
            'artist_id' => $artist->id,
            'name' => 'Test Album',
            'source' => 'dlna',
            'images' => [['url' => 'https://example.test/image/abc123.jpg']],
        ]);

        $job = new ProcessArtwork('https://example.test/image/abc123.jpg');
        $this->callPrivate($job, 'applyColorsToAlbums', [['#111111', '#222222']]);

        $this->assertSame(['#111111', '#222222'], $album->fresh()->colors);
    }

    public function test_apply_colors_does_nothing_for_empty_colors(): void
    {
        $artist = Artist::create(['name' => 'Test Artist', 'source' => 'dlna']);
        $album = Album::create([
            'artist_id' => $artist->id,
            'name' => 'Test Album',
            'source' => 'dlna',
            'images' => [['url' => 'https://example.test/image/abc123.jpg']],
        ]);

        $job = new ProcessArtwork('https://example.test/image/abc123.jpg');
        $this->callPrivate($job, 'applyColorsToAlbums', [[]]);

        $this->assertNull($album->fresh()->colors);
    }

    public function test_apply_colors_does_not_touch_albums_with_a_different_image_url(): void
    {
        $artist = Artist::create(['name' => 'Test Artist', 'source' => 'dlna']);
        $album = Album::create([
            'artist_id' => $artist->id,
            'name' => 'Test Album',
            'source' => 'dlna',
            'images' => [['url' => 'https://example.test/image/other.jpg']],
        ]);

        $job = new ProcessArtwork('https://example.test/image/abc123.jpg');
        $this->callPrivate($job, 'applyColorsToAlbums', [['#111111', '#222222']]);

        $this->assertNull($album->fresh()->colors);
    }

    private function callPrivate(object $object, string $method, array $args = [])
    {
        $reflection = new ReflectionClass($object);
        $reflectionMethod = $reflection->getMethod($method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($object, $args);
    }
}
