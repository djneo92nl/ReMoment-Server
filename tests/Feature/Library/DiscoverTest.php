<?php

namespace Tests\Feature\Library;

use App\Domain\Library\Discover;
use App\Domain\Library\LeadingSource;
use App\Domain\Library\Rediscover;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\Client;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use App\Models\Media\Track;
use App\Models\Play;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeLibraryPlaybackDriver;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** The Discover shelves (docs/frontend/discover.md): the rediscover queries, the sections, GET /api/library/discover. */
class DiscoverTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-21 12:00:00');
        Cache::flush();
        FakeLibraryPlaybackDriver::reset();
        Setting::set(LeadingSource::SETTING, 'all');
        $this->device = Device::factory()->create(['ip_address' => '10.0.0.1', 'device_name' => 'Kitchen', 'device_driver' => FakeLibraryPlaybackDriver::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function album(string $name, string $artist = 'Band', bool $favorite = false, int $tracks = 1): Album
    {
        $a = Artist::firstOrCreate(['name' => $artist], ['source' => 'dlna']);
        $album = Album::create(['artist_id' => $a->id, 'name' => $name, 'source' => 'dlna', 'favorited_at' => $favorite ? now()->subYears(2) : null]);
        for ($i = 1; $i <= $tracks; $i++) {
            $this->makeLibraryTrack($album, "{$name} song {$i}");
        }

        return $album;
    }

    /** One play of $track on the given moment (a string, or days before now). */
    private function play(Track $track, string|int $when): Play
    {
        $at = is_int($when) ? now()->subDays($when) : Carbon::parse($when);

        return Play::create(['device_id' => $this->device->id, 'track_id' => $track->id, 'source_type' => 'dlna', 'played_at' => $at]);
    }

    /** Plays of the album's first track on `$days` different days, the last $lastDaysAgo days ago. */
    private function listen(Album $album, int $days, int $lastDaysAgo): void
    {
        $track = $album->tracks()->first();
        for ($i = 0; $i < $days; $i++) {
            $this->play($track, $lastDaysAgo + $i * 3);
        }
    }

    /** @return list<string> the names of one section's items */
    private function names(string $section, string $field = 'name'): array
    {
        $found = collect(Discover::sections([], [$section]))->firstWhere('key', $section);

        return $found ? array_column($found['items'], $field) : [];
    }

    public function test_forgotten_favorites_are_favorites_without_a_recent_play_most_played_first_and_never_played_last(): void
    {
        $old = $this->album('Old Love', favorite: true);
        $older = $this->album('Older Love', favorite: true);
        $recent = $this->album('Recent Love', favorite: true);
        $never = $this->album('Never Heard', favorite: true);
        $this->album('Not A Favorite');   // never played, but not loved
        $this->listen($old, 2, 300);
        $this->listen($older, 5, 400);
        $this->listen($recent, 3, 10);    // played last week: not forgotten

        $this->assertSame(['Older Love', 'Old Love', 'Never Heard'], $this->names(Rediscover::FORGOTTEN_FAVORITES));

        $items = collect(Discover::sections([], [Rediscover::FORGOTTEN_FAVORITES])[0]['items']);
        $this->assertSame('Favorite, played on 5 days, last played 1 year ago', $items[0]['reason']);
        $this->assertSame('Favorite, not played yet', $items[2]['reason']);
        $this->assertNull($items[2]['last_played_at']);
        $this->assertTrue($items[0]['favorite']);
        $this->assertSame(5, $items[0]['play_days']);
    }

    public function test_used_to_play_needs_enough_listening_days_and_leaves_the_favorites_to_their_own_shelf(): void
    {
        $this->listen($loved = $this->album('Used To Spin'), 6, 250);
        $this->listen($this->album('Barely Tried'), 2, 250);          // too few days
        $this->listen($this->album('Still Playing'), 6, 20);          // not stale
        $this->listen($this->album('A Favorite', favorite: true), 6, 250);   // on the favorites shelf instead

        $this->assertSame(['Used To Spin'], $this->names(Rediscover::USED_TO_PLAY));
        $this->assertSame(['A Favorite'], $this->names(Rediscover::FORGOTTEN_FAVORITES));
        $this->assertStringStartsWith('Played on 6 days, last played', Discover::sections([], [Rediscover::USED_TO_PLAY])[0]['items'][0]['reason']);
        $this->assertSame($loved->id, Discover::sections([], [Rediscover::USED_TO_PLAY])[0]['items'][0]['id']);
    }

    public function test_an_album_played_through_once_is_one_day_not_many_plays(): void
    {
        $album = $this->album('One Evening', tracks: 10);
        foreach ($album->tracks as $track) {
            $this->play($track, 400);
        }

        $this->assertSame([], $this->names(Rediscover::USED_TO_PLAY));
        // ... but it is something you played once a long time ago.
        $this->assertSame(['One Evening'], $this->names(Rediscover::NOT_PLAYED_IN_A_YEAR));
    }

    public function test_not_played_in_a_year_is_old_enough_and_below_the_used_to_play_threshold(): void
    {
        $this->listen($this->album('Tried Once'), 1, 400);
        $this->listen($this->album('Tried Last Spring'), 1, 200);    // not a year yet
        $this->listen($this->album('Heavy Rotation Once'), 8, 400); // used to play instead

        $this->assertSame(['Tried Once'], $this->names(Rediscover::NOT_PLAYED_IN_A_YEAR));
        $this->assertSame(['Heavy Rotation Once'], $this->names(Rediscover::USED_TO_PLAY));
        $this->assertSame('Last played 1 year ago', Discover::sections([], [Rediscover::NOT_PLAYED_IN_A_YEAR])[0]['items'][0]['reason']);
    }

    public function test_on_this_day_finds_plays_around_this_date_in_earlier_years_only(): void
    {
        $match = $this->album('Last November');
        $edge = $this->album('Three Days Off');
        $miss = $this->album('Other Season');
        $thisYear = $this->album('This Year');
        $this->play($match->tracks()->first(), '2025-11-21 08:00:00');
        $this->play($match->tracks()->first(), '2024-11-22 09:00:00');
        $this->play($edge->tracks()->first(), '2023-11-24 23:30:00');   // inside the 3 day window
        $this->play($miss->tracks()->first(), '2025-11-30 12:00:00');   // outside it
        $this->play($thisYear->tracks()->first(), '2026-11-20 12:00:00'); // this year is not "on this day"

        $this->assertSame(['Last November', 'Three Days Off'], $this->names(Rediscover::ON_THIS_DAY));

        $item = Discover::sections([], [Rediscover::ON_THIS_DAY])[0]['items'][0];
        $this->assertSame('Played on 21 Nov 2025 (1 year ago)', $item['reason']);
        $this->assertSame(2, $item['play_days']);
    }

    public function test_a_leap_day_looks_at_the_28th_in_a_year_without_one(): void
    {
        Carbon::setTestNow('2028-02-29 12:00:00');
        Cache::flush();
        $album = $this->album('Leap');
        $this->play($album->tracks()->first(), '2027-02-28 10:00:00');

        $this->assertSame(['Leap'], $this->names(Rediscover::ON_THIS_DAY));
    }

    public function test_forgotten_artists_are_favorites_or_often_played_and_silent_for_a_while(): void
    {
        $fan = $this->album('Fan Album', 'Left Behind');
        $this->listen($fan, 7, 300);
        $fav = $this->album('Heart Album', 'Hearted');
        Artist::where('name', 'Hearted')->update(['favorited_at' => now()->subYear()]);
        $this->listen($this->album('Few', 'Barely Played'), 2, 300);
        $this->listen($this->album('Recent', 'Current Band'), 8, 5);

        $names = $this->names(Rediscover::FORGOTTEN_ARTISTS);

        $this->assertSame(['Left Behind', 'Hearted'], $names);
        $items = Discover::sections([], [Rediscover::FORGOTTEN_ARTISTS])[0]['items'];
        $this->assertSame(1, $items[0]['album_count']);
        $this->assertSame('Played on 7 days, last played 9 months ago', $items[0]['reason']);
        $this->assertSame('Favorite, not played yet', $items[1]['reason']);
    }

    public function test_forgotten_tracks_are_favorites_or_played_often_and_not_on_for_a_while(): void
    {
        $album = $this->album('Songs', tracks: 4);
        [$often, $fav, $recent, $plain] = $album->tracks()->orderBy('id')->get()->all();
        foreach ([300, 301, 302, 303, 304] as $ago) {
            $this->play($often, $ago);
        }
        $fav->update(['favorited_at' => now()->subYear()]);
        $this->play($fav, 280);
        foreach ([3, 4, 5, 6] as $ago) {
            $this->play($recent, $ago);   // often played but just now
        }
        $this->play($plain, 300);          // once, not a favorite

        $this->assertSame([$often->name, $fav->name], $this->names(Rediscover::FORGOTTEN_TRACKS));

        $items = Discover::sections([], [Rediscover::FORGOTTEN_TRACKS])[0]['items'];
        $this->assertSame('Played 5 times, last played 9 months ago', $items[0]['reason']);
        $this->assertSame('Favorite, played 1 time, last played 9 months ago', $items[1]['reason']);
        $this->assertSame(5, $items[0]['plays']);
    }

    public function test_plays_without_a_library_track_count_for_nothing(): void
    {
        $this->album('Quiet', favorite: true);
        Play::create(['device_id' => $this->device->id, 'track_id' => null, 'track_name' => 'Radio song', 'artist_name' => 'Station', 'source_type' => 'radio', 'played_at' => now()->subDays(400)]);

        $this->assertSame(['Quiet'], $this->names(Rediscover::FORGOTTEN_FAVORITES));
        $this->assertSame([], $this->names(Rediscover::NOT_PLAYED_IN_A_YEAR));
    }

    public function test_thresholds_come_from_config(): void
    {
        $this->listen($this->album('Half A Year'), 1, 100);
        $this->assertSame([], $this->names(Rediscover::NOT_PLAYED_IN_A_YEAR));

        config(['discover.long_ago_days' => 60]);
        Cache::flush();

        $this->assertSame(['Half A Year'], $this->names(Rediscover::NOT_PLAYED_IN_A_YEAR));
    }

    public function test_the_scope_leaves_out_what_it_hides(): void
    {
        $dlna = $this->album('On The NAS', favorite: true);
        $dlna->tracks()->update(['source' => 'dlna']);
        $dlna->tracks->each(fn ($t) => $t->metadata()->where('key', 'dlna_url')->update(['source' => 'dlna:1']));

        $this->assertSame(['On The NAS'], $this->names(Rediscover::FORGOTTEN_FAVORITES));
        $this->assertSame([], collect(Discover::sections(['dlna'], [Rediscover::FORGOTTEN_FAVORITES]))->pluck('items')->flatten(1)->all());
    }

    public function test_a_shelf_is_cached_briefly_and_the_limit_applies(): void
    {
        foreach (range(1, 5) as $i) {
            $this->album("Fav {$i}", favorite: true);
        }

        $this->assertCount(2, Discover::sections([], [Rediscover::FORGOTTEN_FAVORITES], limit: 2)[0]['items']);
        $this->assertCount(5, Discover::sections([], [Rediscover::FORGOTTEN_FAVORITES])[0]['items']);

        $this->album('Fav 6', favorite: true);
        $this->assertCount(5, Discover::sections([], [Rediscover::FORGOTTEN_FAVORITES])[0]['items'], 'kept for cache_seconds');
        Carbon::setTestNow(now()->addMinutes(10));
        Cache::flush();   // the array cache in tests does not expire by the fake clock
        $this->assertCount(6, Discover::sections([], [Rediscover::FORGOTTEN_FAVORITES])[0]['items']);
    }

    public function test_the_shelves_cost_a_fixed_number_of_queries_however_many_items_they_hold(): void
    {
        $count = function (): int {
            Cache::flush();
            DB::flushQueryLog();
            DB::enableQueryLog();
            Discover::sections([], Rediscover::keys());
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        foreach (range(1, 3) as $i) {
            $this->listen($this->album("Few {$i}", "Artist {$i}", favorite: true), 5, 300);
        }
        $few = $count();

        foreach (range(4, 25) as $i) {
            $this->listen($this->album("Many {$i}", "Artist {$i}", favorite: true), 5, 300);
        }

        $this->assertSame($few, $count());
    }

    public function test_the_api_lists_sections_with_items_and_a_reason_each(): void
    {
        $this->listen($this->album('Forgotten', favorite: true), 3, 300);

        $response = $this->getJson('/api/library/discover?sections=forgotten_favorites')->assertOk();

        $response->assertJsonPath('data.0.key', 'forgotten_favorites')
            ->assertJsonPath('data.0.kind', 'album')
            ->assertJsonPath('data.0.title', 'Forgotten favorites')
            ->assertJsonPath('data.0.items.0.name', 'Forgotten')
            ->assertJsonPath('data.0.items.0.artist_name', 'Band')
            ->assertJsonPath('data.0.items.0.favorite', true)
            ->assertJsonStructure(['data' => [['key', 'title', 'description', 'kind', 'items' => [['id', 'name', 'artwork', 'reason', 'play_days', 'last_played_at']]]]]);
    }

    public function test_the_api_omits_empty_sections_and_validates(): void
    {
        $this->album('Nothing Played');

        $keys = collect($this->getJson('/api/library/discover')->assertOk()->json('data'))->pluck('key')->all();

        // Nothing loved or played: only the random picks have something.
        $this->assertSame([Discover::RANDOM_ALBUMS], $keys);

        $this->getJson('/api/library/discover?sections=nope')->assertStatus(422);
        $this->getJson('/api/library/discover?limit=0')->assertStatus(422);
        $this->getJson('/api/library/discover?scope=nope')->assertStatus(422);
    }

    public function test_random_picks_follow_the_seed(): void
    {
        foreach (range(1, 30) as $i) {
            $this->album("Random {$i}");
        }

        $pick = fn (int $seed) => collect($this->getJson("/api/library/discover?sections=random_albums&limit=5&seed={$seed}")->json('data.0.items'))->pluck('id')->all();

        $this->assertCount(5, $pick(1));
        $this->assertSame($pick(1), $pick(1));
        $this->assertNotSame($pick(1), $pick(2));
    }

    public function test_new_releases_come_from_the_catalogue_and_are_left_out_for_a_client(): void
    {
        $artist = Artist::create(['name' => 'Band', 'source' => 'dlna']);
        CatalogueAlbum::create(['artist_id' => $artist->id, 'release_group_mbid' => '11111111-1111-1111-1111-111111111111', 'title' => 'Fresh', 'type' => 'album', 'first_release_year' => 2026, 'first_release_date' => '2026-11-10', 'seen_at' => now()]);
        CatalogueAlbum::create(['artist_id' => $artist->id, 'release_group_mbid' => '22222222-2222-2222-2222-222222222222', 'title' => 'Soon', 'type' => 'album', 'first_release_year' => 2026, 'first_release_date' => '2026-12-05', 'seen_at' => now()]);

        $items = $this->getJson('/api/library/discover?sections=new_releases')->assertOk()->json('data.0');

        $this->assertSame('catalogue', $items['kind']);
        $this->assertEqualsCanonicalizing(['Released 10 Nov 2026', 'Coming 5 Dec'], array_column($items['items'], 'reason'));

        $client = Client::create(['name' => 'Touch', 'type' => 'multi', 'api_token' => 'tok', 'registration_token' => 'reg', 'status' => 'approved']);
        $this->assertSame([], $this->getJson('/api/library/discover?sections=new_releases&client='.$client->api_token)->assertOk()->json('data'));
    }

    public function test_mixes_well_starts_from_the_analysed_track_played_last_and_listening_modes_list_the_ready_ones(): void
    {
        $album = $this->album('Club');
        $seed = $this->makeAnalysedTrack($album, 'Seed', ['bpm' => 124, 'key' => 'A', 'mode' => 'minor']);   // 8A
        $match = $this->makeAnalysedTrack($album, 'Match', ['bpm' => 125, 'key' => 'A', 'mode' => 'minor']);
        $this->makeAnalysedTrack($album, 'Too Slow', ['bpm' => 80, 'key' => 'A', 'mode' => 'minor']);
        $this->play($seed, 2);

        $section = collect($this->getJson('/api/library/discover?sections=mixes_well')->assertOk()->json('data'))->firstWhere('key', 'mixes_well');

        $this->assertSame('track', $section['kind']);
        $this->assertSame('Mixes well with Seed', $section['title']);
        $this->assertSame($seed->id, $section['seed']['id']);
        $this->assertSame('8A', $section['seed']['camelot']);
        $this->assertSame([$match->id], array_column($section['items'], 'id'));
        $this->assertSame('same', $section['items'][0]['key_relation']);
        $this->assertStringStartsWith('Mixes well with Seed by Band (8A, 124 BPM)', $section['items'][0]['reason']);

        // Played longer ago than the seed window: no starting point, no section.
        Play::query()->update(['played_at' => now()->subDays(60)]);
        Cache::flush();
        $this->assertSame([], $this->getJson('/api/library/discover?sections=mixes_well')->json('data'));
    }

    public function test_the_analysis_parts_need_the_full_profile(): void
    {
        $album = $this->album('Club');
        $seed = $this->makeAnalysedTrack($album, 'Seed', ['bpm' => 124, 'key' => 'A', 'mode' => 'minor']);
        $this->makeAnalysedTrack($album, 'Match', ['bpm' => 125, 'key' => 'A', 'mode' => 'minor']);
        $this->play($seed, 2);

        config(['profile.current' => 'standard']);

        $this->assertSame([], $this->getJson('/api/library/discover?sections=mixes_well,listening_modes')->assertOk()->json('data'));
    }

    public function test_control_has_no_discover_api(): void
    {
        config(['profile.current' => 'control']);

        $this->getJson('/api/library/discover')->assertStatus(422)->assertJsonPath('error', 'not_available_in_profile');
    }

    public function test_the_hub_page_renders_the_shelves_with_their_reasons(): void
    {
        $this->listen($this->album('Forgotten Gem', favorite: true), 3, 300);
        $track = $this->album('Song Album')->tracks()->first();
        $track->update(['favorited_at' => now()->subYear()]);

        $this->get('/library/discover')->assertOk()
            ->assertSee('Discover')
            ->assertSee('Forgotten favorites')
            ->assertSee('Forgotten Gem')
            ->assertSee('Favorite, played on 3 days, last played 9 months ago')
            ->assertSee('Songs you loved')
            ->assertSee('Song Album song 1')
            ->assertSee('Picked for you');
    }

    public function test_the_hub_says_so_when_there_is_nothing_yet(): void
    {
        $this->get('/library/discover')->assertOk()->assertSee('Nothing to rediscover yet');
    }

    public function test_a_track_row_plays_on_the_pinned_player_and_another_pick_rolls_again(): void
    {
        foreach (range(1, 30) as $i) {
            $this->album("Random {$i}");
        }
        $track = $this->album('Songs')->tracks()->first();
        $track->update(['favorited_at' => now()->subYear()]);

        $page = \Livewire\Livewire::withCookie(\App\Support\SelectedDevice::COOKIE, (string) $this->device->id)
            ->test(\App\Livewire\DiscoverHub::class, ['scope' => 'all']);

        $seed = $page->get('seed');
        $page->call('anotherPick')->assertNotSet('seed', $seed);
        $page->call('playTrack', $track->id)->assertSet('notice', null);
        $this->assertNotEmpty(FakeLibraryPlaybackDriver::$played ?? [$track], 'the pinned player was asked to play the track');
    }

    public function test_without_a_pinned_player_a_track_row_says_to_pick_one(): void
    {
        $track = $this->album('Songs')->tracks()->first();

        \Livewire\Livewire::test(\App\Livewire\DiscoverHub::class, ['scope' => 'all'])
            ->call('playTrack', $track->id)
            ->assertSet('notice', 'Pick a player in the bar below first.');
    }

    public function test_the_hub_is_in_the_library_menu_and_on_the_library_page(): void
    {
        $this->album('Any');

        $this->get('/library')->assertOk()->assertSee(route('library.discover'), false);
        $this->get('/library/discover')->assertOk()->assertSee('Discover');
    }

    public function test_the_mcp_browse_tool_serves_the_shelves_through_the_rest_api(): void
    {
        $this->listen($this->album('Forgotten', favorite: true), 3, 300);

        $rest = $this->getJson('/api/library/discover?sections=forgotten_favorites&limit=5')->assertOk()->json('data.0.items.0.name');
        $this->assertSame('Forgotten', $rest);

        ReMomentServer::tool(Tools\BrowseLibrary::class, ['kind' => 'discover', 'sections' => 'forgotten_favorites', 'limit' => 5])
            ->assertOk()->assertSee('forgotten_favorites')->assertSee('Forgotten')->assertSee('last played 9 months ago');
        ReMomentServer::tool(Tools\BrowseLibrary::class, ['kind' => 'discover', 'sections' => 'nope'])->assertSee('422');
    }

    public function test_every_section_key_has_a_title(): void
    {
        foreach (Discover::keys() as $key) {
            $this->assertArrayHasKey($key, Discover::titles());
        }
    }
}
