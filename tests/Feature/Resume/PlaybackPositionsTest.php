<?php

namespace Tests\Feature\Resume;

use App\Domain\Device\DeviceCache;
use App\Domain\Library\LibraryIdentity;
use App\Domain\Library\LibraryPlayback;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\TrackData;
use App\Domain\Resume\PlaybackPositions;
use App\Domain\Resume\ResumeOnPlay;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\ProgressUpdated;
use App\Jobs\ResumePlayback;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Models\PlaybackPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeLibraryPlaybackDriver;
use Tests\Support\FakeSeekingLibraryDriver;
use Tests\TestCase;

/** Resume position (workstream 8.3, docs/api/playback-positions.md). */
class PlaybackPositionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeSeekingLibraryDriver::reset();
        Cache::flush();
    }

    /** @return array{0: Track, 1: Album} a library track with a stream URL */
    private function track(string $name = 'Chapter 3', int $duration = 3600, string $album = 'The Hobbit', ?string $artist = 'Rob Inglis'): array
    {
        $artistModel = LibraryIdentity::artist($artist, 'files');
        $albumModel = LibraryIdentity::album($artistModel, $album, 'files');
        $track = LibraryIdentity::track($artistModel, $albumModel, $name, uniqid('local:file:'), 'files', ['duration' => $duration]);
        Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $track->id, 'key' => 'dlna_url', 'value' => "http://nas.test/{$track->id}.mp3", 'type' => 'url', 'source' => 'files']);

        return [$track, $albumModel];
    }

    private function device(string $driver = FakeSeekingLibraryDriver::class): Device
    {
        return Device::factory()->driver($driver)->create();
    }

    private function nowPlaying(Device $device, Track $track): void
    {
        DeviceCache::updateNowPlaying($device->id, new NowPlaying(
            track: new TrackData(name: $track->name, artist: new ArtistData(name: $track->artist->name), duration: $track->duration),
            album: new AlbumData(name: $track->album->name),
            state: 'playing',
        ));
    }

    public function test_long_tracks_and_audiobook_genres_are_eligible_short_music_is_not(): void
    {
        [$long] = $this->track('Mix', 90 * 60);
        [$short] = $this->track('Song', 200, 'Album', 'Band');
        [$book] = $this->track('Part 1', 600, 'Book', 'Reader');
        $book->genreRelation()->attach(\App\Models\Media\Genre::forKey('audiobook', 'Audiobook'), ['position' => 0, 'source' => 'test']);

        $this->assertTrue(PlaybackPositions::eligible($long));
        $this->assertFalse(PlaybackPositions::eligible($short));
        $this->assertTrue(PlaybackPositions::eligible($book->fresh()));
    }

    public function test_progress_of_a_long_track_is_kept_throttled_and_the_first_seconds_are_not(): void
    {
        [$track] = $this->track();
        $device = $this->device();
        $this->nowPlaying($device, $track);

        event(new ProgressUpdated((string) $device->id, 4));   // too early: nothing is kept
        $this->assertDatabaseCount('playback_positions', 0);

        event(new ProgressUpdated((string) $device->id, 125));
        $this->assertSame(125, PlaybackPositions::secondsFor('track', $track->id));

        event(new ProgressUpdated((string) $device->id, 130));  // inside the save interval: not written yet
        $this->assertSame(125, PlaybackPositions::secondsFor('track', $track->id));
    }

    public function test_a_short_song_never_gets_a_bookmark(): void
    {
        [$song] = $this->track('Song', 200, 'Album', 'Band');
        $device = $this->device();
        $this->nowPlaying($device, $song);

        event(new ProgressUpdated((string) $device->id, 100));

        $this->assertDatabaseCount('playback_positions', 0);
    }

    public function test_finishing_the_track_clears_the_bookmark(): void
    {
        [$track] = $this->track(duration: 1800);
        $device = $this->device();
        $this->nowPlaying($device, $track);
        PlaybackPositions::set('track', $track->id, 300);

        event(new ProgressUpdated((string) $device->id, 1780));   // within the 30 s tail

        $this->assertDatabaseCount('playback_positions', 0);
    }

    public function test_stopping_in_the_tail_clears_but_stopping_in_the_middle_keeps(): void
    {
        [$track] = $this->track(duration: 1800);
        $device = $this->device();
        $this->nowPlaying($device, $track);

        event(new ProgressUpdated((string) $device->id, 200));
        event(new NowPlayingEnded((string) $device->id));
        $this->assertSame(200, PlaybackPositions::secondsFor('track', $track->id));

        Cache::flush();
        $this->nowPlaying($device, $track);
        event(new ProgressUpdated((string) $device->id, 1790));
        event(new NowPlayingEnded((string) $device->id));
        $this->assertDatabaseCount('playback_positions', 0);
    }

    public function test_playing_a_bookmarked_track_schedules_the_seek(): void
    {
        Queue::fake();
        [$track] = $this->track();
        PlaybackPositions::set('track', $track->id, 900);
        $device = $this->device();

        app(LibraryPlayback::class)->playTrack($device, $track, withAlbum: false);

        Queue::assertPushed(ResumePlayback::class, fn (ResumePlayback $job) => $job->position === 900 && $job->itemId === $track->id && $job->deviceId === $device->id);
        $this->assertTrue(ResumeOnPlay::pending($device->id));
    }

    public function test_resume_false_a_missing_bookmark_or_a_device_that_cannot_seek_schedule_nothing(): void
    {
        Queue::fake();
        [$track] = $this->track();
        $device = $this->device();

        app(LibraryPlayback::class)->playTrack($device, $track, withAlbum: false);   // no bookmark
        PlaybackPositions::set('track', $track->id, 900);

        $this->app->instance('request', \Illuminate\Http\Request::create('/x', 'POST', ['resume' => 'false']));
        app(LibraryPlayback::class)->playTrack($device, $track, withAlbum: false);
        $this->app->instance('request', \Illuminate\Http\Request::create('/x', 'POST'));

        app(LibraryPlayback::class)->playTrack($this->device(FakeLibraryPlaybackDriver::class), $track, withAlbum: false);

        Queue::assertNothingPushed();
    }

    public function test_an_album_plays_on_from_the_bookmarked_chapter(): void
    {
        Queue::fake();
        [$one, $album] = $this->track('Chapter 1');
        $two = Track::create(['album_id' => $album->id, 'artist_id' => $album->artist_id, 'external_id' => 'local:file:b', 'name' => 'Chapter 2', 'duration' => 3600, 'source' => 'files']);
        Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $two->id, 'key' => 'dlna_url', 'value' => 'http://nas.test/2.mp3', 'type' => 'url', 'source' => 'files']);
        Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $two->id, 'key' => 'track_number', 'value' => '2', 'type' => 'int', 'source' => 'local']);
        Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $one->id, 'key' => 'track_number', 'value' => '1', 'type' => 'int', 'source' => 'local']);
        PlaybackPositions::set('track', $two->id, 1200);
        $device = $this->device();

        app(LibraryPlayback::class)->playAlbum($device, $album);

        $played = FakeSeekingLibraryDriver::$calls[0]['tracks']->pluck('id')->all();
        $this->assertSame($two->id, $played[0]);
        Queue::assertPushed(ResumePlayback::class, fn ($job) => $job->itemId === $two->id && $job->position === 1200);
    }

    public function test_the_job_seeks_once_the_device_reports_the_item_and_waits_before(): void
    {
        [$track] = $this->track();
        $device = $this->device();

        // The device still shows nothing: it looks again later and does not seek.
        $job = new ResumePlayback($device->id, 'track', $track->id, $track->name, 900);
        $job->withFakeQueueInteractions();
        $job->handle();
        $job->assertReleased(ResumeOnPlay::DELAY);
        $this->assertSame([], FakeSeekingLibraryDriver::$seeks);

        $this->nowPlaying($device, $track);
        ResumeOnPlay::done($device->id);
        $job = new ResumePlayback($device->id, 'track', $track->id, $track->name, 900);
        $job->withFakeQueueInteractions();
        $job->handle();

        $this->assertSame([900], FakeSeekingLibraryDriver::$seeks);
        $this->assertFalse(ResumeOnPlay::pending($device->id));
    }

    public function test_progress_is_ignored_while_the_seek_is_pending(): void
    {
        [$track] = $this->track();
        $device = $this->device();
        $this->nowPlaying($device, $track);
        PlaybackPositions::set('track', $track->id, 900);
        Cache::put("resume:pending:{$device->id}", true, 30);

        event(new ProgressUpdated((string) $device->id, 12));

        $this->assertSame(900, PlaybackPositions::secondsFor('track', $track->id));
    }

    public function test_the_api_lists_sets_and_clears_positions(): void
    {
        [$track] = $this->track();

        $this->putJson("/api/library/positions/track/{$track->id}", ['position' => 450])
            ->assertOk()->assertJson(['position' => 450, 'cleared' => false]);

        $this->getJson('/api/library/positions')->assertOk()
            ->assertJsonPath('data.0.id', $track->id)->assertJsonPath('data.0.kind', 'track')
            ->assertJsonPath('data.0.position', 450)->assertJsonPath('data.0.percent', 13)
            ->assertJsonPath('data.0.title', 'Chapter 3')->assertJsonPath('data.0.subtitle', 'Rob Inglis · The Hobbit');

        $this->putJson("/api/library/positions/track/{$track->id}", ['position' => 3590])->assertOk()->assertJson(['cleared' => true]);
        $this->assertDatabaseCount('playback_positions', 0);

        PlaybackPositions::set('track', $track->id, 450);
        $this->deleteJson("/api/library/positions/track/{$track->id}")->assertOk();
        $this->assertDatabaseCount('playback_positions', 0);
    }

    public function test_the_api_refuses_unknown_items_and_bad_positions(): void
    {
        $this->putJson('/api/library/positions/track/999', ['position' => 5])->assertNotFound();
        $this->putJson('/api/library/positions/album/1', ['position' => 5])->assertNotFound();
        [$track] = $this->track();
        $this->putJson("/api/library/positions/track/{$track->id}", ['position' => -1])->assertUnprocessable();
    }

    public function test_the_mcp_tool_lists_sets_and_clears(): void
    {
        [$track] = $this->track();

        ReMomentServer::tool(Tools\ContinueListening::class, ['action' => 'set', 'kind' => 'track', 'id' => $track->id, 'position' => 300])->assertOk();
        ReMomentServer::tool(Tools\ContinueListening::class, ['action' => 'list'])->assertOk()->assertSee('Chapter 3');
        ReMomentServer::tool(Tools\ContinueListening::class, ['action' => 'clear', 'kind' => 'track', 'id' => $track->id])->assertOk();
        $this->assertSame(0, PlaybackPosition::count());
    }
}
