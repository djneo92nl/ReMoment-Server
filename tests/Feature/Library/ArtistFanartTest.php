<?php

namespace Tests\Feature\Library;

use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CachesArtwork;
use Tests\TestCase;

class ArtistFanartTest extends TestCase
{
    use CachesArtwork;
    use RefreshDatabase;

    private function artistWithFanart(?string $url): Artist
    {
        $artist = Artist::create(['name' => 'Radiohead', 'source' => 'dlna']);
        if ($url) {
            Metadata::create(['metadatable_type' => Artist::class, 'metadatable_id' => $artist->id, 'key' => 'images', 'value' => json_encode(['fanart' => $url]), 'type' => 'json', 'source' => 'audiodb']);
        }

        return $artist;
    }

    public function test_api_gives_processed_fanart_with_backdrop_sizes(): void
    {
        $artist = $this->artistWithFanart('https://img.test/fanart.jpg');
        $this->cacheProcessedArtwork('https://img.test/fanart.jpg', ['proxy_bg' => 'http://x/storage/artwork/h/bg_1024x600.jpg']);

        $fanart = $this->getJson("/api/library/artists/{$artist->id}")->assertOk()->json('details.fanart');

        $this->assertSame(md5('https://img.test/fanart.jpg'), $fanart['hash']);
        $this->assertSame('/storage/artwork/h/bg_1024x600.jpg', $fanart['proxy_bg']);
        $this->assertArrayHasKey('proxy_bg_320x480', $fanart);
    }

    public function test_unprocessed_fanart_is_queued_and_null_without_fanart(): void
    {
        Queue::fake();
        $with = $this->artistWithFanart('https://img.test/new.jpg');

        $this->getJson("/api/library/artists/{$with->id}")->assertOk()->assertJsonPath('details.fanart', null);
        Queue::assertPushed(\App\Jobs\ProcessArtwork::class);

        $without = Artist::create(['name' => 'Nobody', 'source' => 'dlna']);
        $this->getJson("/api/library/artists/{$without->id}")->assertOk()->assertJsonPath('details.fanart', null);
    }
}
