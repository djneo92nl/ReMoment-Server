<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Play;
use App\Models\RadioStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** GET /api/library/history, /devices/{id}/history, /albums/{id}/plays, /artists/{id}/plays (docs/api/history.md). */
class PlayHistoryApiTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    private Device $kitchen;

    private Device $den;

    private Artist $artist;

    private Album $album;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 12:00:00');
        \App\Models\Setting::set(\App\Domain\Library\LeadingSource::SETTING, 'all');
        $this->kitchen = Device::factory()->create(['device_name' => 'Kitchen']);
        $this->den = Device::factory()->create(['device_name' => 'Den']);
        $this->artist = Artist::create(['name' => 'Band', 'source' => 'files']);
        $this->album = Album::create(['artist_id' => $this->artist->id, 'name' => 'LP', 'source' => 'files']);
    }

    private function play(Device $device, string $at, array $extra = []): Play
    {
        return Play::create($extra + ['device_id' => $device->id, 'source_type' => 'dlna', 'played_at' => Carbon::parse($at)]);
    }

    public function test_lists_track_radio_and_text_plays_newest_first(): void
    {
        $track = $this->makeLibraryTrack($this->album, 'Song');
        $station = RadioStation::create(['name' => 'Radio 1']);
        $this->play($this->kitchen, '2026-11-13 09:00:00', ['track_id' => $track->id, 'ended_at' => Carbon::parse('2026-11-13 09:03:00')]);
        $this->play($this->den, '2026-11-13 10:00:00', ['source_type' => 'radio', 'radio_name' => 'Radio 1', 'radio_station_id' => $station->id, 'track_name' => 'On Air', 'artist_name' => 'DJ']);
        $this->play($this->den, '2026-11-13 11:00:00', ['track_name' => 'Unknown', 'artist_name' => 'Someone', 'skipped' => true, 'ended_at' => Carbon::parse('2026-11-13 11:00:10')]);

        $r = $this->getJson('/api/library/history')->assertOk()->json();

        $this->assertNull($r['next_cursor']);
        $this->assertSame(['track', 'radio', 'track'], array_column($r['data'], 'type'));
        [$text, $radio, $own] = $r['data'];

        $this->assertSame('Unknown', $text['name']);
        $this->assertNull($text['track_id']);
        $this->assertSame('Someone', $text['artist_name']);
        $this->assertFalse($text['completed']);
        $this->assertSame(10, $text['listened_seconds']);

        $this->assertSame('Radio 1', $radio['name']);
        $this->assertSame(['id' => $station->id, 'name' => 'Radio 1'], $radio['station']);
        $this->assertNull($radio['ended_at']);
        $this->assertNull($radio['listened_seconds']);

        $this->assertSame($track->id, $own['track_id']);
        $this->assertSame($this->album->id, $own['album_id']);
        $this->assertSame($this->artist->id, $own['artist_id']);
        $this->assertSame('Song', $own['name']);
        $this->assertSame('Band', $own['artist_name']);
        $this->assertSame(['id' => $this->kitchen->id, 'name' => 'Kitchen'], $own['device']);
        $this->assertSame('files', $own['library']);
        $this->assertSame(180, $own['listened_seconds']);
        $this->assertTrue($own['completed']);
        $this->assertSame(Carbon::parse('2026-11-13 09:00:00')->toIso8601String(), $own['started_at']);
        $this->assertSame(200, $own['duration']);
    }

    public function test_filters_and_cursor_paging(): void
    {
        $track = $this->makeLibraryTrack($this->album, 'Findable');
        foreach (range(1, 5) as $i) {
            $this->play($this->kitchen, "2026-11-1{$i} 10:00:00", ['track_id' => $track->id]);
        }
        $this->play($this->den, '2026-11-13 10:00:00', ['track_name' => 'Other', 'artist_name' => 'X']);

        $first = $this->getJson('/api/library/history?limit=4')->assertOk()->json();
        $this->assertCount(4, $first['data']);
        $this->assertNotNull($first['next_cursor']);
        $second = $this->getJson('/api/library/history?limit=4&cursor='.$first['next_cursor'])->json();
        $this->assertCount(2, $second['data']);
        $this->assertNull($second['next_cursor']);
        $this->assertSame(6, count(array_unique(array_merge(array_column($first['data'], 'id'), array_column($second['data'], 'id')))));

        $this->assertCount(5, $this->getJson("/api/library/history?device_id={$this->kitchen->id}")->json('data'));
        $this->assertCount(1, $this->getJson('/api/library/history?q=Other')->json('data'));
        $this->assertCount(5, $this->getJson('/api/library/history?q=Findable')->json('data'));
        $this->assertCount(3, $this->getJson('/api/library/history?from=2026-11-12&to=2026-11-13')->json('data'));
        $this->getJson('/api/library/history?cursor=bogus')->assertStatus(422);
        $this->getJson('/api/library/history?device_id=999')->assertStatus(422);
    }

    public function test_device_album_and_artist_variants(): void
    {
        $a = $this->makeLibraryTrack($this->album, 'A');
        $other = Album::create(['artist_id' => Artist::create(['name' => 'Else', 'source' => 'files'])->id, 'name' => 'Z', 'source' => 'files']);
        $b = $this->makeLibraryTrack($other, 'B');
        $this->play($this->kitchen, '2026-11-13 09:00:00', ['track_id' => $a->id]);
        $this->play($this->den, '2026-11-13 10:00:00', ['track_id' => $a->id]);
        $this->play($this->den, '2026-11-13 11:00:00', ['track_id' => $b->id]);

        $this->assertCount(2, $this->getJson("/api/devices/{$this->den->id}/history")->assertOk()->json('data'));
        $this->getJson('/api/devices/999/history')->assertNotFound();
        $this->assertCount(2, $this->getJson("/api/library/albums/{$this->album->id}/plays")->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson("/api/library/albums/{$this->album->id}/plays?device_id={$this->kitchen->id}")->json('data'));
        $this->assertCount(2, $this->getJson("/api/library/artists/{$this->artist->id}/plays")->json('data'));
        $this->assertSame($b->id, $this->getJson("/api/library/artists/{$other->artist_id}/plays")->json('data.0.track_id'));
    }

    public function test_history_works_in_the_control_profile(): void
    {
        config(['profile.current' => 'control']);
        $this->play($this->kitchen, '2026-11-13 09:00:00', ['track_name' => 'T', 'artist_name' => 'A']);

        $this->getJson('/api/library/history')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/devices/{$this->kitchen->id}/history")->assertOk();
    }
}
