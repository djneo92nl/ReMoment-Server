<?php

namespace Tests\Unit;

use App\Integrations\Common\PlaysDlnaLibrary;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaysDlnaLibraryTest extends TestCase
{
    use RefreshDatabase;

    private function player(): object
    {
        return new class
        {
            use PlaysDlnaLibrary;

            public array $calls = [];

            protected function startDlna(string $url): void
            {
                $this->calls[] = ['start', $url];
            }

            protected function enqueueDlna(string $url): void
            {
                $this->calls[] = ['enqueue', $url];
            }
        };
    }

    private function track(string $name, ?string $url): Track
    {
        $artist = \App\Models\Media\Artist::firstOrCreate(['name' => 'A', 'source' => 'dlna']);
        $track = Track::create(['artist_id' => $artist->id, 'name' => $name, 'external_id' => '1:'.$name, 'source' => 'dlna']);

        if ($url) {
            Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $track->id, 'key' => 'dlna_url', 'value' => $url, 'type' => 'url', 'source' => 'dlna:1']);
        }

        return $track;
    }

    public function test_the_first_playable_track_starts_and_the_rest_is_queued(): void
    {
        $player = $this->player();

        $player->playLibraryTracks(collect([$this->track('a', 'http://x/a'), $this->track('skipped', null), $this->track('b', 'http://x/b')]));

        $this->assertSame([['start', 'http://x/a'], ['enqueue', 'http://x/b']], $player->calls);
    }

    public function test_nothing_playable_is_an_error(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->player()->playLibraryTracks(collect([$this->track('a', null)]));
    }

    public function test_a_single_track_starts_at_once(): void
    {
        $player = $this->player();
        $player->playLibraryTrack($this->track('a', 'http://x/a'));

        $this->assertSame([['start', 'http://x/a']], $player->calls);
    }
}
