<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Play;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** GET /api/library/stats and the plays / last_played_at fields (docs/api/stats.md). */
class ListeningStatsApiTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 12:00:00');
        \App\Models\Setting::set(\App\Domain\Library\LeadingSource::SETTING, 'all');
    }

    private function play(Device $device, ?\App\Models\Media\Track $track, string $at, ?int $seconds = 100, array $extra = []): Play
    {
        $at = Carbon::parse($at);

        return Play::create($extra + [
            'device_id' => $device->id, 'track_id' => $track?->id, 'source_type' => 'dlna', 'played_at' => $at,
            'ended_at' => $seconds !== null ? $at->copy()->addSeconds($seconds) : null,
        ]);
    }

    public function test_it_aggregates_plays_in_the_range(): void
    {
        $device = Device::factory()->create(['device_name' => 'Kitchen']);
        $album = Album::create(['artist_id' => Artist::create(['name' => 'Band', 'source' => 'files'])->id, 'name' => 'LP', 'source' => 'files']);
        $a = $this->makeLibraryTrack($album, 'A');
        $b = $this->makeLibraryTrack($album, 'B');

        $this->play($device, $a, '2026-11-12 20:15:00', 200);                      // Thursday
        $this->play($device, $a, '2026-11-12 20:45:00', 20, ['skipped' => true]);
        $this->play($device, $b, '2026-11-01 08:00:00', null);                       // unfinished: no seconds
        $this->play($device, null, '2026-11-02 08:00:00', 60, ['source_type' => 'radio', 'track_name' => null]);
        $this->play($device, $a, '2026-09-01 08:00:00', 60);                         // out of range

        $r = $this->getJson('/api/library/stats')->assertOk()->json();

        $this->assertSame(['from' => '2026-10-15', 'to' => '2026-11-13'], $r['range']);
        $this->assertSame(['plays' => 4, 'seconds' => 280, 'skipped' => 1, 'artists' => 1, 'tracks' => 2], $r['totals']);
        $this->assertCount(24, $r['by_hour']);
        $this->assertSame(2, $r['by_hour'][20]);
        $this->assertSame(2, $r['by_hour'][8]); // two plays at 08:00
        $this->assertCount(7, $r['by_weekday']);
        $this->assertSame(2, $r['by_weekday'][4]);
        $this->assertCount(30, $r['by_day']);
        $this->assertSame(['day' => '2026-11-12', 'plays' => 2, 'seconds' => 220], collect($r['by_day'])->firstWhere('day', '2026-11-12'));
        $this->assertSame([['source_type' => 'dlna', 'plays' => 3], ['source_type' => 'radio', 'plays' => 1]], $r['by_source']);
        $this->assertSame($device->id, $r['by_device'][0]['device_id']);
        $this->assertSame('Kitchen', $r['by_device'][0]['name']);
        $this->assertSame(4, $r['by_device'][0]['plays']);
        $this->assertCount(24, $r['by_device'][0]['by_hour']);
        $this->assertSame(['id' => $a->id, 'plays' => 2], ['id' => $r['top']['tracks'][0]['id'], 'plays' => $r['top']['tracks'][0]['plays']]);
        $this->assertSame(3, $r['top']['artists'][0]['plays']);
        $this->assertSame(3, $r['top']['albums'][0]['plays']);
        $this->assertArrayHasKey('artwork', $r['top']['tracks'][0]);
    }

    public function test_range_parameters_and_validation(): void
    {
        $this->getJson('/api/library/stats?from=2026-11-01&to=2026-11-03')->assertOk()
            ->assertJsonPath('range', ['from' => '2026-11-01', 'to' => '2026-11-03'])
            ->assertJsonCount(3, 'by_day')
            ->assertJsonPath('totals', ['plays' => 0, 'seconds' => 0, 'skipped' => 0, 'artists' => 0, 'tracks' => 0]);
        $this->getJson('/api/library/stats?from=2026-11-05&to=2026-11-03')->assertStatus(422);
        $this->getJson('/api/library/stats?from=2020-01-01')->assertStatus(422);
        $this->getJson('/api/library/stats?from=nope')->assertStatus(422);
    }

    public function test_plays_and_last_played_at_on_album_artist_and_tracks(): void
    {
        $device = Device::factory()->create();
        $artist = Artist::create(['name' => 'Band', 'source' => 'files']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'LP', 'source' => 'files']);
        $a = $this->makeLibraryTrack($album, 'A');
        $b = $this->makeLibraryTrack($album, 'B');
        $this->play($device, $a, '2026-11-10 10:00:00');
        $this->play($device, $a, '2026-11-11 10:00:00');

        $album = $this->getJson("/api/library/albums/{$album->id}")->assertOk()->json();
        $this->assertSame(2, $album['plays']);
        $this->assertSame(Carbon::parse('2026-11-11 10:00:00')->toIso8601String(), $album['last_played_at']);
        $this->assertSame([2, 0], array_column($album['tracks'], 'plays'));
        $this->assertNull($album['tracks'][1]['last_played_at']);

        $this->getJson("/api/library/artists/{$artist->id}")->assertJsonPath('plays', 2);
    }

    public function test_stats_work_in_the_control_profile(): void
    {
        config(['profile.current' => 'control']);

        $this->getJson('/api/library/stats')->assertOk()->assertJsonPath('totals.plays', 0);
    }
}
