<?php

namespace Tests\Feature\Api;

use App\Domain\Library\LeadingSource;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Play;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** GET /api/library/insights (docs/api/insights.md). Times are in the application timezone (UTC in tests). */
class ListeningInsightsApiTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Carbon::setTestNow('2026-11-13 12:00:00'); // Friday
        Setting::set(LeadingSource::SETTING, 'all');
        config(['profile.current' => 'full']);
        $this->device = Device::factory()->create();
    }

    private function album(string $artist = 'Band'): Album
    {
        return Album::create(['artist_id' => Artist::firstOrCreate(['name' => $artist], ['source' => 'files'])->id, 'name' => "LP $artist", 'source' => 'files']);
    }

    private function play(?Track $track, string $at, ?int $seconds = 100): Play
    {
        $at = Carbon::parse($at);

        return Play::create([
            'device_id' => $this->device->id, 'track_id' => $track?->id, 'source_type' => 'dlna', 'played_at' => $at,
            'ended_at' => $seconds !== null ? $at->copy()->addSeconds($seconds) : null,
        ]);
    }

    private function trackOf(Album $album, string $name, int $duration = 200, array $analysis = []): Track
    {
        $t = $analysis ? $this->makeAnalysedTrack($album, $name, $analysis) : $this->makeLibraryTrack($album, $name);
        $t->update(['duration' => $duration]);

        return $t;
    }

    public function test_skip_rate_uses_the_fraction_of_the_track_and_ignores_unmeasurable_plays(): void
    {
        $skippy = $this->album('Skippy');
        $loved = $this->album('Loved');
        $s = $this->trackOf($skippy, 'S', 200);
        $l = $this->trackOf($loved, 'L', 200);

        $this->play($s, '2026-11-10 10:00:00', 99);   // < 50 % of 200 s: skipped
        $this->play($s, '2026-11-10 11:00:00', 30);   // skipped
        $this->play($s, '2026-11-10 12:00:00', 100);  // exactly half: not skipped
        $this->play($s, '2026-11-10 13:00:00', null); // unfinished: not measured
        $this->play($l, '2026-11-10 14:00:00', 199);
        $this->play($l, '2026-11-10 15:00:00', 190);
        $this->play($l, '2026-11-10 16:00:00', 10);   // one skip of three
        $this->play($s, '2026-08-01 10:00:00', 10);   // outside the 90 days

        $r = $this->getJson('/api/library/insights')->assertOk()->json();

        $this->assertSame(['fraction' => 0.5, 'min_plays' => 3], $r['skips']['rule']);
        $this->assertSame(6, $r['skips']['plays']);
        $this->assertSame(3, $r['skips']['skipped']);
        $this->assertSame(0.5, $r['skips']['rate']);
        $this->assertSame(['Skippy', 'Loved'], array_column($r['skips']['artists'], 'name'));
        $this->assertSame(['plays' => 3, 'skipped' => 2, 'rate' => 0.667], array_intersect_key($r['skips']['artists'][0], array_flip(['plays', 'skipped', 'rate'])));
    }

    public function test_an_artist_below_the_minimum_of_plays_gets_no_rate(): void
    {
        $t = $this->trackOf($this->album(), 'T', 200);
        $this->play($t, '2026-11-10 10:00:00', 5);
        $this->play($t, '2026-11-10 11:00:00', 5);

        $r = $this->getJson('/api/library/insights')->assertOk()->json();

        $this->assertSame(2, $r['skips']['skipped']);
        $this->assertSame([], $r['skips']['artists']);
    }

    public function test_weekly_new_against_repeat_counts_the_first_play_ever(): void
    {
        $album = $this->album();
        $old = $this->trackOf($album, 'Old');
        $fresh = $this->trackOf($album, 'Fresh');
        $another = $this->trackOf($album, 'Another');

        $this->play($old, '2026-06-01 10:00:00');       // long before the window, so `old` is a repeat later
        $this->play($old, '2026-11-02 10:00:00');       // Monday of week 2026-11-02: repeat
        $this->play($fresh, '2026-11-08 23:59:00');     // Sunday, same week: new
        $this->play($fresh, '2026-11-09 00:00:00');     // Monday of the next week: repeat
        $this->play($another, '2026-11-12 09:00:00');   // new
        $this->play(null, '2026-11-12 10:00:00');       // no track: not counted

        $weekly = collect($this->getJson('/api/library/insights?days=30')->assertOk()->json('weekly'))->keyBy('week');

        $this->assertSame(['new' => 1, 'repeat' => 1], ['new' => $weekly['2026-11-02']['new'], 'repeat' => $weekly['2026-11-02']['repeat']]);
        $this->assertSame(['new' => 1, 'repeat' => 1], ['new' => $weekly['2026-11-09']['new'], 'repeat' => $weekly['2026-11-09']['repeat']]);
        $this->assertSame(['2026-10-12', '2026-10-19', '2026-10-26', '2026-11-02', '2026-11-09'], $weekly->keys()->all()); // zero-filled, Mondays
        $this->assertSame(0, $weekly['2026-10-19']['new'] + $weekly['2026-10-19']['repeat']);
    }

    public function test_parts_of_day_aggregate_mood_and_tempo_and_cross_midnight_correctly(): void
    {
        $album = $this->album();
        $calm = $this->trackOf($album, 'Calm', 200, ['bpm' => 80, 'arousal' => 0.2, 'mood_tags' => [['name' => 'relaxed', 'score' => 0.9]]]);
        $drive = $this->trackOf($album, 'Drive', 200, ['bpm' => 130, 'arousal' => 0.8, 'mood_tags' => [['name' => 'aggressive', 'score' => 0.8], ['name' => 'party', 'score' => 0.7]]]);
        $plain = $this->trackOf($album, 'Plain', 200); // not analysed

        $this->play($calm, '2026-11-12 23:30:00');   // evening, late
        $this->play($calm, '2026-11-13 00:30:00');   // night (after midnight), late
        $this->play($drive, '2026-11-13 04:59:00');  // night, still late
        $this->play($drive, '2026-11-13 05:00:00');  // morning, not late
        $this->play($plain, '2026-11-13 08:00:00');  // morning, unanalysed
        $this->play($drive, '2026-11-12 17:59:00');  // afternoon

        $r = $this->getJson('/api/library/insights')->assertOk()->json();
        $parts = collect($r['parts_of_day'])->keyBy('part');

        $this->assertTrue($r['analysis']);
        $this->assertSame('UTC', $r['timezone']);
        $this->assertSame(['night', 'morning', 'afternoon', 'evening'], array_keys($parts->all()));
        $this->assertSame(2, $parts['night']['plays']);
        $this->assertEquals(105, $parts['night']['bpm']);
        $this->assertEquals(0.5, $parts['night']['arousal']);
        $this->assertSame(['slow' => 1, 'steady' => 0, 'fast' => 1], $parts['night']['tempos']);
        $this->assertSame([['name' => 'aggressive', 'plays' => 1], ['name' => 'party', 'plays' => 1], ['name' => 'relaxed', 'plays' => 1]], $parts['night']['moods']);
        $this->assertSame(2, $parts['morning']['plays']);
        $this->assertSame(1, $parts['morning']['analysed_plays']);
        $this->assertEquals(130, $parts['morning']['bpm']);
        $this->assertSame(1, $parts['afternoon']['plays']);
        $this->assertSame(1, $parts['evening']['plays']);

        $this->assertSame(3, $r['late']['plays']);
        $this->assertSame(['from' => 22, 'until' => 5], $r['late']['hours']);
        $this->assertSame('Band', $r['late']['artists'][0]['name']);
        $this->assertSame(3, $r['late']['artists'][0]['plays']);
        $this->assertSame(['Calm', 'Drive'], array_column($r['late']['tracks'], 'name'));
        $this->assertSame([2, 1], array_column($r['late']['tracks'], 'plays'));
        $this->assertSame('relaxed', $r['late']['moods'][0]['name']);
        $this->assertSame(2, $r['late']['moods'][0]['plays']);
    }

    public function test_standard_profile_has_empty_sound_blocks_but_keeps_plays_blocks(): void
    {
        config(['profile.current' => 'standard']);
        $t = $this->trackOf($this->album(), 'T', 200, ['bpm' => 100]);
        $this->play($t, '2026-11-12 23:00:00');

        $r = $this->getJson('/api/library/insights')->assertOk()->json();

        $this->assertFalse($r['analysis']);
        $this->assertCount(4, $r['parts_of_day']);
        $this->assertSame(0, array_sum(array_column($r['parts_of_day'], 'plays')));
        $this->assertSame([], $r['late']['moods']);
        $this->assertSame(1, $r['late']['plays']);
        $this->assertSame(1, array_sum(array_column($r['weekly'], 'new')));
    }

    public function test_control_profile_refuses_it(): void
    {
        config(['profile.current' => 'control']);

        $this->getJson('/api/library/insights')->assertStatus(422)->assertJsonPath('error', 'not_available_in_profile');
    }

    public function test_nothing_played_gives_empty_blocks_not_errors(): void
    {
        $r = $this->getJson('/api/library/insights?days=14')->assertOk()->json();

        $this->assertSame(['from' => '2026-10-31', 'to' => '2026-11-13', 'days' => 14], $r['range']);
        $this->assertNull($r['skips']['rate']);
        $this->assertSame([], $r['skips']['artists']);
        $this->assertSame(0, array_sum(array_column($r['weekly'], 'new')));
        $this->assertCount(4, $r['parts_of_day']);
        $this->assertNull($r['parts_of_day'][0]['bpm']);
        $this->assertSame(0, $r['late']['plays']);
        $this->assertSame([], $r['late']['artists']);
        $this->assertSame([], $r['late']['tracks']);
    }

    public function test_days_is_validated(): void
    {
        $this->getJson('/api/library/insights?days=0')->assertStatus(422);
        $this->getJson('/api/library/insights?days=366')->assertStatus(422);
        $this->getJson('/api/library/insights?days=365')->assertOk();
    }

    public function test_results_are_cached_for_a_few_minutes(): void
    {
        $t = $this->trackOf($this->album(), 'T');
        $this->play($t, '2026-11-12 23:00:00');
        $this->getJson('/api/library/insights')->assertOk();

        $this->play($t, '2026-11-12 23:10:00');
        DB::enableQueryLog();
        $r = $this->getJson('/api/library/insights')->assertOk()->json();
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "plays"') || str_contains($q['query'], 'from `plays`'));
        DB::disableQueryLog();

        $this->assertSame(0, $queries->count());
        $this->assertSame(1, $r['late']['plays']);

        Cache::flush();
        $this->assertSame(2, $this->getJson('/api/library/insights')->json('late.plays'));
    }
}
