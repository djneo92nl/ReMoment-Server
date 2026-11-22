<?php

namespace Tests\Feature\Library;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Library\LeadingSource;
use App\Domain\Similar\Embeddings;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\Device;
use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Play;
use App\Models\Setting;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeLibraryPlaybackDriver;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** GET /api/library/journey, GET /api/library/artists/{id}/tour and their play endpoints (docs/api/journey.md, docs/api/artist-tour.md). */
class JourneyAndTourTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        config(['profile.current' => 'full']);
        Setting::set(LeadingSource::SETTING, 'all');
        Cache::flush();
        FakeLibraryPlaybackDriver::reset();
        $this->device = Device::factory()->create(['ip_address' => '10.0.0.1', 'device_name' => 'Kitchen', 'device_driver' => FakeLibraryPlaybackDriver::class]);
        DeviceCache::updateState($this->device->id, State::Standby);
    }

    private function album(string $name, string $artist = 'Band', ?string $released = null): Album
    {
        $a = Artist::firstOrCreate(['name' => $artist], ['source' => 'dlna']);

        return Album::firstOrCreate(['artist_id' => $a->id, 'name' => $name, 'source' => 'dlna'], ['released_at' => $released]);
    }

    /** A unit vector at an angle (degrees) in the first two dimensions. */
    private static function at(float $degrees): array
    {
        return array_pad([cos(deg2rad($degrees)), sin(deg2rad($degrees))], Embeddings::DIMENSIONS, 0.0);
    }

    private function track(string $name, ?float $angle, ?Album $album = null): Track
    {
        $track = $this->makeLibraryTrack($album ?? $this->album('LP'), $name);
        if ($angle !== null) {
            $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => '/tmp'], 'role' => 'library']);
            $file = File::create(['storage_location_id' => $location->id, 'path' => "{$track->id}.flac", 'hash' => "h{$track->id}", 'size' => 1, 'mtime' => 1, 'status' => File::PRESENT]);
            TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
            FileAnalysis::create(['file_id' => $file->id, 'content_hash' => "h{$track->id}"]);
            Embeddings::store($file, "h{$track->id}", self::at($angle));
        }

        return $track;
    }

    private function play(Track $track, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            Play::create(['device_id' => $this->device->id, 'track_id' => $track->id, 'source_type' => 'dlna', 'played_at' => now()->subDays($i + 1)]);
        }
    }

    public function test_a_journey_moves_from_the_start_to_the_end_in_order(): void
    {
        $start = $this->track('Start', 0);
        $end = $this->track('End', 90);
        // Ten tracks spread between the two, added in a scrambled order, on different artists so artist alternation does not interfere.
        foreach ([50, 10, 80, 30, 70, 20, 60, 40, 5, 85] as $angle) {
            $this->track("At {$angle}", $angle, $this->album("LP {$angle}", "Band {$angle}"));
        }

        $data = $this->getJson("/api/library/journey?from={$start->id}&to={$end->id}&length=6")->assertOk()->json('data');

        $this->assertCount(6, $data);
        $this->assertSame('Start', $data[0]['name']);
        $this->assertSame('End', $data[5]['name']);
        $angles = array_map(fn ($item) => (int) preg_replace('/\D/', '', $item['name']) ?: null, array_slice($data, 1, 4));
        $sorted = $angles;
        sort($sorted);
        $this->assertSame($sorted, $angles, 'each step is further along than the one before');
        $this->assertSame('Step 2 of 6 from Start to End', $data[1]['reason']);
        $this->assertEquals(0, $data[0]['progress']);
        $this->assertEquals(1, $data[5]['progress']);
    }

    public function test_a_journey_needs_embeddings_on_both_ends_and_enough_tracks(): void
    {
        $start = $this->track('Start', 0);
        $plain = $this->track('Plain', null);
        $end = $this->track('End', 90);

        $this->getJson("/api/library/journey?from={$start->id}&to={$plain->id}")->assertStatus(422)->assertJsonPath('error', 'not_analysed')->assertJsonPath('track', 'to');
        $this->getJson("/api/library/journey?from={$start->id}&to={$end->id}")->assertStatus(422)->assertJsonPath('error', 'not_enough_tracks');
        $this->getJson("/api/library/journey?from={$start->id}&to={$start->id}")->assertStatus(422);
        $this->getJson("/api/library/journey?from={$start->id}&to={$end->id}&length=2")->assertStatus(422);
    }

    public function test_the_journey_routes_need_the_full_profile(): void
    {
        config(['profile.current' => 'standard']);

        $this->getJson('/api/library/journey?from=1&to=2')->assertStatus(422)->assertJsonPath('feature', 'analysis');
        $this->postJson('/api/devices/1/library/play-journey', [])->assertStatus(422)->assertJsonPath('feature', 'analysis');
    }

    public function test_playing_a_journey_queues_the_steps_from_the_start(): void
    {
        $start = $this->track('Start', 0);
        $end = $this->track('End', 90);
        foreach ([20, 40, 60, 80] as $angle) {
            $this->track("At {$angle}", $angle, $this->album("LP {$angle}", "Band {$angle}"));
        }

        $this->postJson("/api/devices/{$this->device->id}/library/play-journey", ['from_track_id' => $start->id, 'to_track_id' => $end->id, 'length' => 6])
            ->assertOk()->assertExactJson(['status' => 'ok', 'from' => $start->id, 'to' => $end->id, 'queued' => 6]);

        $this->assertSame(['Start', 'At 20', 'At 40', 'At 60', 'At 80', 'End'], FakeLibraryPlaybackDriver::$calls[0]['tracks']->pluck('name')->all());
    }

    public function test_an_artist_tour_opens_with_the_most_played_then_one_song_per_album_oldest_first(): void
    {
        $old = $this->album('Early', 'Tourist', '1999-05-05');
        $new = $this->album('Late', 'Tourist', '2015-05-05');
        $hit = $this->track('Hit', null, $new);
        $this->play($hit, 9);
        $oldHit = $this->track('Old Hit', null, $old);
        $this->play($oldHit, 3);
        $oldDeep = $this->track('Old Deep', null, $old);
        $newDeep = $this->track('New Deep', null, $new);
        $this->play($newDeep, 1);
        $this->track('New Deeper', null, $new);

        $artist = $hit->artist_id;
        $data = $this->getJson("/api/library/artists/{$artist}/tour")->assertOk()->json('data');

        $this->assertSame(['Hit', 'Old Hit', 'New Deep', 'Old Deep', 'New Deeper'], array_column($data, 'name'));
        $this->assertSame('One of your most played by Tourist (9 plays)', $data[0]['reason']);
        $this->assertSame('Never played, from Early (1999)', $data[3]['reason']);
    }

    public function test_an_artist_with_few_tracks_has_no_tour(): void
    {
        $track = $this->track('Only', null);

        $this->getJson("/api/library/artists/{$track->artist_id}/tour")->assertStatus(422)->assertJsonPath('error', 'not_enough_tracks');
    }

    public function test_playing_an_artist_tour_works_in_standard(): void
    {
        config(['profile.current' => 'standard']);
        $album = $this->album('LP', 'Tourist');
        foreach (range(1, 6) as $i) {
            $track = $this->track("Song {$i}", null, $album);
        }

        $this->postJson("/api/devices/{$this->device->id}/library/play-artist-tour", ['artist_id' => $track->artist_id, 'length' => 5])
            ->assertOk()->assertJsonPath('queued', 5);
    }

    public function test_mcp_reaches_both_through_the_api(): void
    {
        $start = $this->track('Start', 0);
        $end = $this->track('End', 90);
        foreach ([20, 40, 60, 80] as $angle) {
            $this->track("At {$angle}", $angle, $this->album("LP {$angle}", "Band {$angle}"));
        }

        ReMomentServer::tool(Tools\BrowseLibrary::class, ['kind' => 'journey', 'id' => (string) $start->id, 'to_id' => $end->id, 'length' => 6])->assertOk()->assertSee('Step 2 of 6');
        ReMomentServer::tool(Tools\PlayFromLibrary::class, ['device_id' => $this->device->id, 'type' => 'journey', 'id' => $start->id, 'to_id' => $end->id, 'length' => 6])->assertOk()->assertSee('queued');
        ReMomentServer::tool(Tools\BrowseLibrary::class, ['kind' => 'artist-tour', 'id' => (string) $start->artist_id])->assertHasErrors(['not_enough_tracks (422): This artist has too few tracks for a tour.']);
    }
}
