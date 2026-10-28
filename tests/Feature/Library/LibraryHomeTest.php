<?php

namespace Tests\Feature\Library;

use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibraryHome;
use App\Domain\Library\RandomAlbum;
use App\Domain\Library\RecentItems;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Play;
use App\Models\RadioStation;
use App\Models\Setting;
use App\Support\SelectedDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeLibraryPlaybackDriver;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** The redesigned /library: hero, shelves, favourites, shuffle (docs/frontend/library.md). */
class LibraryHomeTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        FakeLibraryPlaybackDriver::reset();
        Setting::set(LeadingSource::SETTING, 'all');
    }

    private function release(string $artist, string $album, bool $favorite = false, ?array $colors = null): Album
    {
        $a = Artist::firstOrCreate(['name' => $artist], ['source' => 'dlna', 'favorited_at' => $favorite ? now() : null]);
        $al = Album::create(['artist_id' => $a->id, 'name' => $album, 'source' => 'dlna', 'favorited_at' => $favorite ? now() : null, 'colors' => $colors]);
        $this->makeLibraryTrack($al, "{$album} song");

        return $al;
    }

    private function speaker(): Device
    {
        return Device::factory()->create(['ip_address' => '10.0.0.1', 'device_name' => 'Kitchen', 'device_driver' => FakeLibraryPlaybackDriver::class]);
    }

    public function test_the_hero_names_the_scope_and_counts_what_is_in_it(): void
    {
        $this->release('Coldplay', 'Parachutes');
        $this->release('Muse', 'Absolution');
        $this->release('Muse', 'Origin of Symmetry');

        $hero = LibraryHome::build('all', [])['hero'];

        $this->assertSame('Everything', $hero['name']);
        $this->assertSame(3, $hero['albums']);
        $this->assertSame(2, $hero['artists']);

        $this->get('/library')->assertOk()->assertSee('3 albums')->assertSee('2 artists')->assertSee('Shuffle');
    }

    public function test_a_cover_colour_tints_the_cards_and_without_one_they_stay_plain(): void
    {
        $this->release('Plain', 'Grey Album');
        $this->get('/library')->assertOk()->assertDontSee('--tint-dark:', false);

        $this->release('Tinted', 'Blue Album', colors: ['#2255cc']);
        Cache::flush();
        $this->get('/library')->assertOk()->assertSee('--tint-dark:#', false)->assertSee('--tint-light:#', false);
    }

    public function test_favourites_shelves_only_exist_when_there_are_favourites(): void
    {
        $this->release('Coldplay', 'Parachutes');
        $this->get('/library')->assertOk()->assertDontSee('Favorite albums')->assertDontSee('Favorite artists');

        $this->release('Muse', 'Absolution', favorite: true);
        $this->get('/library')->assertOk()->assertSee('Favorite albums')->assertSee('Favorite artists');
        $this->get('/library/favorites')->assertOk()->assertSee('Absolution')->assertSee('Muse')->assertDontSee('Parachutes');
    }

    public function test_the_empty_favourites_page_says_so(): void
    {
        $this->release('Coldplay', 'Parachutes');

        $this->get('/library/favorites')->assertOk()->assertSee('No favorites yet');
    }

    public function test_recent_items_mix_albums_playlists_and_radio_newest_first(): void
    {
        $album = $this->release('Coldplay', 'Parachutes');
        $device = Device::factory()->create();
        Play::create(['device_id' => $device->id, 'track_id' => $album->tracks()->first()->id, 'source_type' => 'dlna', 'played_at' => now()->subHours(3)]);

        $playlist = Playlist::create(['name' => 'Morning', 'source' => 'local', 'last_played_at' => now()->subHours(2)]);
        $playlist->tracks()->attach($album->tracks()->first()->id, ['position' => 1]);
        RadioStation::create(['name' => 'Radio Fip', 'last_played_at' => now()->subHour()]);

        ['items' => $items] = RecentItems::get([], 10);

        $this->assertSame(['radio', 'playlist', 'album'], $items->pluck('type')->all());
        $this->assertSame('Radio Fip', $items[0]['station']->name);
        $this->assertSame('Morning', $items[1]['playlist']->name);
        $this->assertSame('Parachutes', $items[2]['album']->name);

        // The web shows the tracks played (rows, see RecentTracksTest); the mixed list stays the API's recent-items.
        $this->get('/library')->assertOk()->assertSee('Recently played')->assertSee('data-test="recent-tracks"', false);
    }

    public function test_random_album_prefers_one_the_device_can_play(): void
    {
        $this->release('Coldplay', 'Parachutes');

        $this->assertSame('Parachutes', RandomAlbum::pick([], $this->speaker())->name);
        Album::query()->delete();
        $this->assertNull(RandomAlbum::pick([]));
    }

    public function test_shuffle_needs_a_pinned_player(): void
    {
        $this->release('Coldplay', 'Parachutes');

        $this->post('/library/shuffle')->assertRedirect()->assertSessionHas('error');
        $this->assertCount(0, FakeLibraryPlaybackDriver::$calls);
    }

    public function test_shuffle_plays_a_random_album_on_the_pinned_player(): void
    {
        $this->release('Coldplay', 'Parachutes');
        $speaker = $this->speaker();

        $this->withCookie(SelectedDevice::COOKIE, (string) $speaker->id)
            ->post('/library/shuffle')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertCount(1, FakeLibraryPlaybackDriver::$calls);
    }

    public function test_shuffle_says_so_when_the_library_is_empty(): void
    {
        $speaker = $this->speaker();

        $this->withCookie(SelectedDevice::COOKIE, (string) $speaker->id)
            ->post('/library/shuffle')
            ->assertSessionHas('error');
    }

    public function test_tracks_page_lists_searches_and_links_from_the_hub(): void
    {
        $this->release('Coldplay', 'Parachutes');
        $this->release('Muse', 'Absolution');

        $this->get('/library')->assertOk()->assertSee(route('library.tracks'));
        $this->get('/library/tracks')->assertOk()->assertSee('2 tracks')->assertSee('Parachutes song')->assertSee('Absolution song')->assertSee('Shuffle all');
        $this->get('/library/tracks?q=muse')->assertOk()->assertSee('Absolution song')->assertDontSee('Parachutes song');
    }

    public function test_shuffle_tracks_needs_a_pinned_player(): void
    {
        $this->release('Coldplay', 'Parachutes');

        $this->post('/library/tracks/shuffle')->assertSessionHas('error');
        $this->assertCount(0, FakeLibraryPlaybackDriver::$calls);
    }

    public function test_shuffle_tracks_queues_the_tracks_of_the_library_on_the_pinned_player(): void
    {
        $this->release('Coldplay', 'Parachutes');
        $this->release('Muse', 'Absolution');
        $speaker = $this->speaker();

        $this->withCookie(SelectedDevice::COOKIE, (string) $speaker->id)
            ->post('/library/tracks/shuffle')
            ->assertSessionHas('success');

        $this->assertCount(2, FakeLibraryPlaybackDriver::$calls[0]['tracks']);
    }

    public function test_api_lists_tracks_in_pages_and_plays_them_shuffled(): void
    {
        $this->release('Coldplay', 'Parachutes');
        $this->release('Muse', 'Absolution');

        $this->getJson('/api/library/tracks')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Absolution song')
            ->assertJsonPath('next_cursor', null);
        $this->getJson('/api/library/tracks?sort=artist&letter=M')->assertOk()->assertJsonPath('data.0.artist_name', 'Muse');

        $speaker = $this->speaker();
        \App\Domain\Device\DeviceCache::updateState($speaker->id, \App\Domain\Device\State::Standby);
        $this->postJson("/api/devices/{$speaker->id}/library/play-tracks")
            ->assertOk()->assertJsonPath('queued', 2);
        $this->assertCount(2, FakeLibraryPlaybackDriver::$calls[0]['tracks']);
    }

    public function test_the_page_does_not_query_per_card(): void
    {
        $count = function (): int {
            Cache::flush();
            $this->get('/library')->assertOk(); // warm the artwork memo path
            Cache::flush();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/library')->assertOk();

            return count(DB::getQueryLog());
        };

        foreach (range(1, 4) as $i) {
            $this->release("Artist {$i}", "Album {$i}", favorite: $i % 2 === 0, colors: ['#336699']);
        }
        $small = $count();

        foreach (range(5, 24) as $i) {
            $this->release("Artist {$i}", "Album {$i}", favorite: $i % 3 === 0, colors: ['#336699']);
        }
        $large = $count();

        $this->assertLessThanOrEqual($small + 4, $large, "4 albums took {$small} queries, 24 took {$large}: something queries per card");
    }
}
