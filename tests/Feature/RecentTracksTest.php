<?php

namespace Tests\Feature;

use App\Livewire\RecentTracks;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Media\WantedTrack;
use App\Models\Play;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Mockery;
use SpotifyWebAPI\SpotifyWebAPI;
use Tests\Support\FakesSpotify;
use Tests\TestCase;

/** Recently played as compact rows: the scope's own plays; only the Streaming scope merges in what was played on Spotify. */
class RecentTracksTest extends TestCase
{
    use FakesSpotify, RefreshDatabase;

    private function played(string $name, string $source, string $when): Track
    {
        $artist = Artist::firstOrCreate(['name' => 'Band'], ['source' => $source]);
        $album = Album::firstOrCreate(['artist_id' => $artist->id, 'name' => 'LP'], ['source' => $source]);
        $track = Track::create(['album_id' => $album->id, 'artist_id' => $artist->id, 'name' => $name, 'source' => $source, 'external_id' => $source.':'.$name]);
        Play::create(['device_id' => Device::factory()->create()->id, 'track_id' => $track->id, 'source_type' => 'library', 'played_at' => $when]);

        return $track;
    }

    private function spotifyRecent(array $items): void
    {
        Cache::flush();
        $api = Mockery::mock(SpotifyWebAPI::class);
        $this->connectSpotify($api);
        $api->shouldReceive('getMyRecentTracks')->andReturn(['items' => array_map(fn ($i) => ['played_at' => $i[1], 'track' => ['id' => $i[2], 'type' => 'track', 'name' => $i[0], 'duration_ms' => 1000, 'artists' => [['name' => 'Band']], 'album' => ['name' => 'LP', 'images' => []]]], $items)]);
    }

    public function test_local_shows_only_own_files_and_never_mixes_in_spotify(): void
    {
        $this->played('Local One', 'files', now()->subHour());
        $this->played('Dlna One', 'dlna', now()->subMinutes(30));
        $this->spotifyRecent([['Spotify One', now()->subMinutes(5)->toIso8601String(), 'track000000AB']]);

        Livewire::test(RecentTracks::class, ['scope' => 'local'])->assertSee('Local One')->assertDontSee('Dlna One')->assertDontSee('Spotify One');
        $this->get('/library?scope=local')->assertOk()->assertSee('Local One')->assertDontSee('Spotify One');
    }

    public function test_streaming_merges_dlna_plays_and_spotify_newest_first_one_row_per_song(): void
    {
        $this->played('Local One', 'files', now()->subHours(3));
        $this->played('Dlna Old', 'dlna', now()->subHours(2));
        $this->played('Same Song', 'dlna', now()->subHour());
        $this->spotifyRecent([
            ['Spotify New', now()->subMinutes(5)->toIso8601String(), 'track000000AB'],
            ['Same Song', now()->subMinutes(20)->toIso8601String(), 'track000000CD'],
        ]);

        $html = Livewire::test(RecentTracks::class, ['scope' => 'streaming'])->assertDontSee('Local One')->html();

        $this->assertSame(1, substr_count($html, 'Same Song</span>'), 'a song played on both sides is one row');
        $this->assertMatchesRegularExpression('/Spotify New.*Same Song.*Dlna Old/s', $html, 'newest first');
    }

    public function test_library_rows_can_be_favorited_and_spotify_rows_only_wanted(): void
    {
        $dlna = $this->played('Dlna One', 'dlna', now()->subHour());
        $this->spotifyRecent([['Spotify One', now()->subMinutes(5)->toIso8601String(), 'track000000AB']]);

        Livewire::test(RecentTracks::class, ['scope' => 'streaming'])
            ->assertSeeHtml('data-test="fav-'.$dlna->id.'"')->assertDontSeeHtml('data-test="fav-track000000AB"')
            ->call('favorite', $dlna->id)
            ->call('toggleWant', ['title' => 'Spotify One', 'artist' => 'Band', 'album' => null, 'image' => null])
            ->assertSee('On the wishlist')
            ->call('playLibrary', $dlna->id)->assertSee('Pick a player in the bar below first.');

        $this->assertNotNull($dlna->fresh()->favorited_at);
        $this->assertSame(['Spotify One'], WantedTrack::pluck('title')->all());
    }

    public function test_spotify_failing_still_shows_the_library_plays_and_limits_rows(): void
    {
        $this->played('Dlna One', 'dlna', now()->subHour());
        Cache::flush();
        $api = Mockery::mock(SpotifyWebAPI::class);
        $this->connectSpotify($api);
        $api->shouldReceive('getMyRecentTracks')->andThrow(new \SpotifyWebAPI\SpotifyWebAPIException('Insufficient client scope', 403));

        Livewire::test(RecentTracks::class, ['scope' => 'streaming'])->assertSee('Dlna One');

        foreach (range(1, 14) as $i) {
            $this->played("More {$i}", 'files', now()->subMinutes($i));
        }
        Livewire::test(RecentTracks::class, ['scope' => 'local'])->assertSee('Show all 14')->call('showAll')->assertDontSee('Show all');
    }
}
