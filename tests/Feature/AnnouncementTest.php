<?php

namespace Tests\Feature;

use App\Domain\Device\Cache\Volume;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Device\VolumeLimits;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Radio;
use App\Domain\Media\TrackData;
use App\Domain\Playback\AnnouncementClips;
use App\Domain\Playback\Announcer;
use App\Jobs\RestoreAfterAnnouncement;
use App\Models\AdminToken;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\RadioStation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAnnounceDriver;
use Tests\Support\FakePlayerDriver;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use MakesLibraryTracks, RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        Queue::fake();
        FakePlayerDriver::reset();
        FakeAnnounceDriver::$played = [];
        config(['playback.move_wait_seconds' => 0]);
        $this->device = Device::factory()->driver(FakeAnnounceDriver::class)->inState(State::Playing)->create(['device_name' => 'Kitchen']);
    }

    /** The device plays a library track at 42 seconds; returns the track. */
    private function playingLibraryTrack()
    {
        $album = Album::create(['artist_id' => Artist::create(['name' => 'Artist'])->id, 'name' => 'Album']);
        $track = $this->makeLibraryTrack($album, 'Song');
        DeviceCache::updateNowPlaying($this->device->id, new NowPlaying(
            track: new TrackData(id: (string) $track->id, name: 'Song', source: 'dlna', duration: 200, artist: new ArtistData(name: 'Artist')),
            album: new AlbumData(name: 'Album'),
            state: 'playing',
            position: 42,
        ));

        return $track;
    }

    private function announce(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/announcements', $body + ['clip' => 'chime', 'device_ids' => [$this->device->id]]);
    }

    /** Runs the restore the announcement queued. */
    private function runRestore(): void
    {
        $job = Queue::pushed(RestoreAfterAnnouncement::class)->last();
        $job->handle(app(Announcer::class));
    }

    private function calls(string $method): array
    {
        return array_values(array_filter(FakePlayerDriver::$calls, fn ($call) => $call[0] === $method));
    }

    public function test_the_clip_plays_and_the_restore_is_queued_after_its_length(): void
    {
        $this->announce()->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('devices.0.status', 'ok');

        $this->assertCount(1, FakeAnnounceDriver::$played);
        $this->assertStringEndsWith('/announce/chime.mp3', FakeAnnounceDriver::$played[0][0]);
        Queue::assertPushed(RestoreAfterAnnouncement::class, fn ($job) => $job->deviceId === $this->device->id && $job->delay !== null);
    }

    public function test_a_library_track_comes_back_at_its_position(): void
    {
        DeviceCache::updateState($this->device->id, State::Playing);
        $track = $this->playingLibraryTrack();

        $this->announce()->assertOk();
        $this->assertSame([], $this->calls('playLibraryTracks'));

        $this->runRestore();

        $this->assertSame([['playLibraryTracks', [$track->id]]], $this->calls('playLibraryTracks'));
        $this->assertSame([['seek', 42]], $this->calls('seek'));
    }

    public function test_a_radio_station_comes_back(): void
    {
        $station = RadioStation::create(['name' => 'Radio 1']);
        DeviceCache::updateNowPlaying($this->device->id, new NowPlaying(radio: new Radio(name: 'Radio 1'), state: 'playing'));

        $this->announce()->assertOk();
        $this->runRestore();

        $this->assertSame([['playRadioStation', $station->id]], $this->calls('playRadioStation'));
    }

    public function test_a_silent_device_is_stopped_again_and_the_volume_comes_back(): void
    {
        DeviceCache::updateState($this->device->id, State::Standby);
        Volume::updateVolume($this->device->id, 20);

        $this->announce(['volume' => 55])->assertOk();
        $this->assertSame([['setVolume', 55]], $this->calls('setVolume'));

        $this->runRestore();

        $this->assertSame([['setVolume', 55], ['setVolume', 20]], $this->calls('setVolume'));
        $this->assertCount(1, $this->calls('stop'));
    }

    public function test_the_announcement_volume_respects_the_volume_limit(): void
    {
        VolumeLimits::set($this->device, ['max' => 30]);

        $this->announce(['volume' => 80])->assertOk();

        $this->assertSame([['setVolume', 30]], $this->calls('setVolume'));
    }

    public function test_a_second_announcement_keeps_the_first_memory_and_only_the_last_restore_acts(): void
    {
        $track = $this->playingLibraryTrack();

        $this->announce()->assertOk();
        // What the device reports during the clip must not become "what was playing".
        DeviceCache::updateNowPlaying($this->device->id, new NowPlaying(track: new TrackData(id: 'clip', name: 'Chime', source: 'stream'), state: 'playing'));
        $this->announce()->assertOk();

        $jobs = Queue::pushed(RestoreAfterAnnouncement::class);
        $jobs->first()->handle(app(Announcer::class));
        $this->assertSame([], $this->calls('playLibraryTracks'));

        $jobs->last()->handle(app(Announcer::class));
        $this->assertSame([['playLibraryTracks', [$track->id]]], $this->calls('playLibraryTracks'));
    }

    public function test_each_device_answers_for_itself(): void
    {
        $plain = Device::factory()->inState(State::Playing)->create(['device_name' => 'Plain']);
        $off = Device::factory()->driver(FakeAnnounceDriver::class)->inState(State::Unreachable)->create();

        $this->announce(['device_ids' => [$this->device->id, $plain->id, $off->id]])
            ->assertOk()->assertJsonPath('status', 'partial')
            ->assertJsonPath('devices.1.error', 'unsupported')->assertJsonPath('devices.2.error', 'unreachable');

        $this->announce(['device_ids' => [$plain->id]])->assertStatus(502)->assertJsonPath('status', 'failed');
        $this->announce(['clip' => 'nope'])->assertStatus(422);
    }

    public function test_the_chime_is_served_as_mp3_and_an_unknown_clip_is_not(): void
    {
        if (!\App\Domain\Analysis\Tools::available(\App\Domain\Analysis\Tools::ffmpeg())) {
            $this->markTestSkipped('ffmpeg is not installed.');
        }

        $this->get('/announce/chime.mp3')->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        $this->get('/announce/nope.mp3')->assertNotFound();
    }

    public function test_clips_are_listed_for_everyone_and_managed_by_the_admin(): void
    {
        $this->getJson('/api/announcements/clips')->assertOk()->assertJsonPath('data.0.name', 'chime')->assertJsonPath('data.0.builtin', true);
        $this->postJson('/api/admin/announcements/clips')->assertUnauthorized();
        $this->deleteJson('/api/admin/announcements/clips/chime')->assertUnauthorized();

        User::factory()->create();
        [, $token] = AdminToken::issue('test');
        $auth = ['Authorization' => 'Bearer '.$token];
        $this->deleteJson('/api/admin/announcements/clips/chime', [], $auth)->assertNotFound();

        if (!\App\Domain\Analysis\Tools::available(\App\Domain\Analysis\Tools::ffmpeg())) {
            $this->markTestSkipped('ffmpeg is not installed.');
        }

        // The chime doubles as a real sound file to upload.
        $upload = new UploadedFile(AnnouncementClips::file('chime'), 'Door Bell.mp3', 'audio/mpeg', null, true);
        $this->post('/api/admin/announcements/clips', ['file' => $upload, 'label' => 'Door bell'], $auth + ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.name', 'door-bell')->assertJsonPath('data.builtin', false);

        $clip = AnnouncementClips::find('door-bell');
        $this->assertGreaterThan(1500, $clip['duration_ms']);
        $this->assertLessThan(3500, $clip['duration_ms']);

        $this->post('/api/admin/announcements/clips', ['file' => UploadedFile::fake()->createWithContent('x.mp3', 'not audio')], $auth + ['Accept' => 'application/json'])->assertStatus(422);

        $this->deleteJson('/api/admin/announcements/clips/door-bell', [], $auth)->assertOk();
        $this->assertNull(AnnouncementClips::find('door-bell'));
    }
}
