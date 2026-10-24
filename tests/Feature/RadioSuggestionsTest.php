<?php

namespace Tests\Feature;

use App\Domain\Radio\RadioPlayLog;
use App\Domain\Radio\RadioSuggestions;
use App\Integrations\Radio\Nts\NtsClient;
use App\Livewire\NtsBrowser;
use App\Models\Media\Artist;
use App\Models\RadioStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class RadioSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_similar_stations_follow_the_favourite_genres_and_skip_owned_ones(): void
    {
        Cache::flush();
        $fav = RadioStation::create(['name' => 'Jazz One', 'genre' => 'Jazz', 'favorited_at' => now()]);
        $fav->setMeta('url', 'https://s.test/owned');
        Http::fake(['*/json/stations/search*' => Http::response([
            ['stationuuid' => 'a', 'name' => 'Owned', 'url' => 'https://s.test/owned', 'hls' => 0],
            ['stationuuid' => 'b', 'name' => 'New Jazz', 'url' => 'https://s.test/new', 'hls' => 0],
        ])]);

        $result = app(RadioSuggestions::class)->similarToFavourites();

        $this->assertSame(['New Jazz'], array_column($result, 'name'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'tag=jazz'));
    }

    public function test_a_logged_song_links_to_the_library_track_when_it_has_one(): void
    {
        $artist = Artist::create(['name' => 'Radiohead']);
        $album = \App\Models\Media\Album::create(['name' => 'OK Computer', 'artist_id' => $artist->id]);
        \App\Models\Media\Track::create(['name' => 'Lucky', 'artist_id' => $artist->id, 'album_id' => $album->id, 'source' => 'dlna', 'external_id' => '1:2']);

        $station = RadioStation::create(['name' => 'Fip']);
        $play = RadioPlayLog::record($station, 'icy', 'Radiohead - Lucky');

        $this->assertSame($album->id, RadioPlayLog::libraryTrack($play)?->album_id);
        $this->get(route('radio.show', $station))->assertOk()->assertSee('In library');
    }

    public function test_no_favourites_means_no_suggestions(): void
    {
        $this->assertSame([], app(RadioSuggestions::class)->similarToFavourites());
    }

    public function test_artists_you_play_are_found_on_the_stations_that_played_them(): void
    {
        $artist = Artist::create(['name' => 'The Cure']);
        $album = \App\Models\Media\Album::create(['name' => 'Disintegration', 'artist_id' => $artist->id]);
        $track = \App\Models\Media\Track::create(['name' => 'Lovesong', 'artist_id' => $artist->id, 'album_id' => $album->id, 'source' => 'dlna', 'external_id' => '1:1']);
        \App\Models\Play::create(['device_id' => \App\Models\Device::factory()->create()->id, 'track_id' => $track->id, 'played_at' => now()]);

        $station = RadioStation::create(['name' => 'Fip']);
        RadioPlayLog::record($station, 'icy', 'Cure - Other Song');
        RadioPlayLog::record($station, 'icy', 'Somebody - Else');

        $result = app(RadioSuggestions::class)->yourArtistsOnTheRadio();

        $this->assertCount(1, $result);
        $this->assertSame(['artist' => 'The Cure', 'station_id' => $station->id, 'station' => 'Fip', 'count' => 1], $result[0]);
    }

    public function test_nts_episodes_and_tracklists_are_browsable(): void
    {
        Cache::flush();
        Http::fake([
            '*/api/v2/shows/100-elements/episodes/ep1/tracklist' => Http::response(['results' => [
                ['artist' => 'Unknown Artist', 'title' => 'Dey Know', 'offset' => 8],
                ['artist' => 'Biggavell', 'title' => 'So Hard', 'offset' => null, 'offset_estimate' => 281],
            ]]),
            '*/api/v2/shows/100-elements/episodes*' => Http::response(['results' => [['episode_alias' => 'ep1', 'name' => 'Ep &amp; One', 'broadcast' => '2026-10-09T12:00:00+00:00', 'mixcloud' => 'https://mixcloud.test/x', 'audio_sources' => [['source' => 'soundcloud', 'url' => 'https://sc.test/x']]]]]),
            '*/api/v2/shows*' => Http::response(['metadata' => ['resultset' => ['count' => 1]], 'results' => [['show_alias' => '100-elements', 'name' => '100 Elements', 'description' => '<h3>Beats</h3>', 'genres' => [['value' => 'Beats ']], 'media' => []]]]),
        ]);

        $client = app(NtsClient::class);
        $this->assertSame(['100 Elements'], array_column($client->shows()['shows'], 'name'));
        $this->assertSame('Ep & One', $client->episodes('100-elements')[0]['name']);
        $this->assertSame([['artist' => null, 'title' => 'Dey Know', 'offset' => 8], ['artist' => 'Biggavell', 'title' => 'So Hard', 'offset' => 281]], $client->tracklist('100-elements', 'ep1'));

        Livewire::test(NtsBrowser::class)->assertSee('100 Elements')
            ->call('openShow', '100-elements')->assertSee('Ep & One')
            ->call('openEpisode', 'ep1')->assertSee('So Hard')->assertSee('4:41')
            ->call('back')->assertSee('Ep & One');

        $this->get(route('radio.nts'))->assertOk();
    }
}
