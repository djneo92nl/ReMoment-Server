<?php

namespace Tests\Feature\Library;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Library\DailyMix;
use App\Domain\Library\Discover;
use App\Domain\Library\LeadingSource;
use App\Domain\Library\Rediscover;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Play;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeLibraryPlaybackDriver;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** The shelves that need no play history and the daily mix (docs/frontend/discover.md, docs/api/discover.md). */
class RediscoverNewShelvesTest extends TestCase
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
        DeviceCache::updateState($this->device->id, State::Standby);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function album(string $name, string $addedDaysAgo = '200', bool $favorite = false, int $tracks = 2, ?string $released = null): Album
    {
        $artist = Artist::firstOrCreate(['name' => 'Band'], ['source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => $name, 'source' => 'dlna', 'favorited_at' => $favorite ? now()->subYear() : null, 'released_at' => $released]);
        $album->forceFill(['created_at' => now()->subDays((int) $addedDaysAgo)])->saveQuietly();
        for ($i = 1; $i <= $tracks; $i++) {
            $this->makeLibraryTrack($album, "{$name} song {$i}");
        }

        return $album;
    }

    private function play(Track $track, int $daysAgo): void
    {
        Play::create(['device_id' => $this->device->id, 'track_id' => $track->id, 'source_type' => 'dlna', 'played_at' => now()->subDays($daysAgo)]);
    }

    private function section(string $key): ?array
    {
        return collect(Discover::sections([], [$key]))->firstWhere('key', $key);
    }

    public function test_buried_treasure_is_owned_for_a_while_and_never_played(): void
    {
        $this->album('Dusty');
        $played = $this->album('Played');
        $this->play($played->tracks()->first(), 5);
        $this->album('Brand New', addedDaysAgo: '10');   // added last week: give it time

        $section = $this->section(Rediscover::BURIED_TREASURE);

        $this->assertSame(['Dusty'], array_column($section['items'], 'name'));
        $this->assertSame('In your library for 6 months, never played', $section['items'][0]['reason']);
    }

    public function test_buried_treasure_is_the_same_all_day_and_changes_tomorrow(): void
    {
        foreach (range(1, 40) as $i) {
            $this->album("Album {$i}");
        }

        $names = fn () => array_column($this->section(Rediscover::BURIED_TREASURE)['items'], 'name');
        $today = $names();
        Cache::flush();

        $this->assertSame($today, $names());

        Carbon::setTestNow('2026-11-22 12:00:00');
        Cache::flush();
        $this->assertNotSame($today, $names());
    }

    public function test_deep_cuts_are_unplayed_songs_on_albums_you_love(): void
    {
        $loved = $this->album('Loved', favorite: true, tracks: 3);
        $this->play($loved->tracks()->orderBy('id')->first(), 400);
        $spun = $this->album('Spun', tracks: 2);
        foreach ([40, 50, 60, 70] as $days) {
            $this->play($spun->tracks()->orderBy('id')->first(), $days);
        }
        $this->album('Ignored', tracks: 2);   // not loved: its songs are not deep cuts

        $section = $this->section(Rediscover::DEEP_CUTS);

        $this->assertEqualsCanonicalizing(['Loved song 2', 'Loved song 3', 'Spun song 2'], array_column($section['items'], 'name'));
        $this->assertContains('Never played, from Spun', array_column($section['items'], 'reason'));
    }

    public function test_released_on_this_day_looks_at_the_date_in_earlier_years_and_skips_year_only_dates(): void
    {
        $this->album('Twenty Years', released: '2006-11-21');
        $this->album('Close By', released: '2016-11-23');
        $this->album('Too Far', released: '2016-11-30');
        $this->album('Year Only', released: '2011-01-01');   // a bare year is stored as Jan 1
        $this->album('First Of Month', released: '2011-11-01');
        $this->album('This Year', released: '2026-11-21');

        $section = $this->section(Rediscover::RELEASED_ON_THIS_DAY);

        $this->assertSame(['Close By', 'Twenty Years'], array_column($section['items'], 'name'));
        $this->assertSame('Released 20 years ago, on 21 Nov 2006', $section['items'][1]['reason']);
    }

    public function test_a_library_without_any_plays_still_has_shelves(): void
    {
        foreach (range(1, 12) as $i) {
            $this->album("Fresh {$i}");
        }

        $keys = array_column(Discover::sections([]), 'key');

        $this->assertContains(Rediscover::BURIED_TREASURE, $keys);
        $this->assertContains(DailyMix::KEY, $keys);
    }

    public function test_the_daily_mix_alternates_the_kinds_and_says_why(): void
    {
        $loved = $this->album('Loved', favorite: true, tracks: 4);
        $this->play($loved->tracks()->orderBy('id')->first(), 400);
        $song = $this->album('Songs')->tracks()->first();
        $song->update(['favorited_at' => now()->subYears(2)]);
        foreach (range(1, 6) as $i) {
            $this->album("Dusty {$i}");
        }

        $picks = DailyMix::picks([]);
        $reasons = array_column($picks, 'reason');

        $this->assertGreaterThanOrEqual(5, count($picks));
        $this->assertSame(count($picks), count(array_unique(array_column($picks, 'track_id'))), 'no track twice');
        $this->assertStringStartsWith('A song you loved', $reasons[0]);
        $this->assertStringStartsWith('A deep cut', $reasons[1]);
        $this->assertStringStartsWith('From an album you own', $reasons[2]);

        $section = $this->section(DailyMix::KEY);
        $this->assertSame('track', $section['kind']);
        $this->assertSame(count($picks), count($section['items']));
    }

    public function test_there_is_no_daily_mix_without_enough_to_bring_back(): void
    {
        $this->album('Only', addedDaysAgo: '5');

        $this->assertSame([], DailyMix::picks([]));
        $this->assertNull($this->section(DailyMix::KEY));
    }

    public function test_the_api_plays_the_daily_mix_on_a_device(): void
    {
        foreach (range(1, 8) as $i) {
            $this->album("Dusty {$i}");
        }

        $this->postJson("/api/devices/{$this->device->id}/library/play-daily-mix")->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('queued', 6);
        $this->assertCount(6, FakeLibraryPlaybackDriver::$calls[0]['tracks']);
    }

    public function test_the_api_says_so_when_there_is_no_mix(): void
    {
        $this->postJson("/api/devices/{$this->device->id}/library/play-daily-mix")->assertStatus(422)->assertJsonPath('error', 'not_playable');
    }

    public function test_the_hub_plays_the_mix_on_the_pinned_player(): void
    {
        foreach (range(1, 8) as $i) {
            $this->album("Dusty {$i}");
        }

        \Livewire\Livewire::withCookie(\App\Support\SelectedDevice::COOKIE, (string) $this->device->id)
            ->test(\App\Livewire\DiscoverHub::class, ['scope' => 'all'])
            ->assertSee('Your daily mix')->assertSee('Play today')
            ->call('playDailyMix')->assertSet('notice', null);

        $this->assertNotEmpty(FakeLibraryPlaybackDriver::$calls);
    }

    public function test_every_new_key_has_a_title(): void
    {
        foreach ([DailyMix::KEY, Rediscover::BURIED_TREASURE, Rediscover::DEEP_CUTS, Rediscover::RELEASED_ON_THIS_DAY, Discover::SIMILAR_TRACKS] as $key) {
            $this->assertArrayHasKey($key, Discover::titles());
            $this->assertContains($key, Discover::keys());
        }
    }
}
