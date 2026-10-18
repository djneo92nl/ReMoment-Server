<?php

namespace Tests\Feature\Library;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Library\LeadingSource;
use App\Domain\Library\ListeningModes;
use App\Domain\Library\SmartPlaylist\SmartPlaylistBuilder;
use App\Models\Device;
use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Setting;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeLibraryPlaybackDriver;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** The listening modes (focus, party, …), the Camelot / dynamics smart fields, and the endpoints that serve them. */
class ListeningModesTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    private Album $album;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set(LeadingSource::SETTING, 'all');
        Cache::flush();
        FakeLibraryPlaybackDriver::reset();
        $this->album = Album::create(['artist_id' => Artist::create(['name' => 'Band', 'source' => 'dlna'])->id, 'name' => 'LP', 'source' => 'dlna']);
    }

    private function track(array $analysis, ?string $name = null): Track
    {
        $name ??= 'Track '.++$this->n;
        $track = $this->makeLibraryTrack($this->album, $name);
        $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => '/tmp'], 'role' => 'library']);
        $file = File::create(['storage_location_id' => $location->id, 'path' => "{$name}.flac", 'hash' => $name, 'size' => 1, 'mtime' => 1, 'status' => File::PRESENT]);
        TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        FileAnalysis::create(['file_id' => $file->id, 'content_hash' => $name] + $analysis);

        return $track;
    }

    /** @return list<string> */
    private function names(array $rules): array
    {
        return SmartPlaylistBuilder::tracks(['match' => 'all', 'rules' => $rules, 'sort' => 'name'])->pluck('tracks.name')->all();
    }

    private function party(int $count): void
    {
        foreach (range(1, $count) as $i) {
            $this->track(['bpm' => 120.0, 'danceability' => 0.9, 'arousal' => 0.6, 'valence' => 0.7, 'loudness_lufs' => -8.0]);
        }
    }

    private function device(): Device
    {
        $device = Device::factory()->create(['ip_address' => '10.0.0.1', 'device_driver' => FakeLibraryPlaybackDriver::class]);
        DeviceCache::updateState($device->id, State::Standby);

        return $device;
    }

    // --- Smart fields ---

    public function test_camelot_field_matches_key_and_mode_together(): void
    {
        $this->track(['key' => 'A', 'mode' => 'minor'], 'A minor');
        $this->track(['key' => 'C', 'mode' => 'major'], 'C major');
        $this->track(['key' => 'A', 'mode' => 'major'], 'A major');

        $this->assertSame(['A minor'], $this->names([['field' => 'camelot', 'operator' => 'is', 'value' => '8A']]));
        $this->assertSame(['C major'], $this->names([['field' => 'camelot', 'operator' => 'is', 'value' => '8B']]));
        $this->assertEqualsCanonicalizing(['A major', 'C major'], $this->names([['field' => 'camelot', 'operator' => 'is_not', 'value' => '8A']]));
        // A code that is not on the wheel is not an option, so normalize() drops the rule instead of matching nothing.
        $this->assertCount(3, $this->names([['field' => 'camelot', 'operator' => 'is', 'value' => '99Z']]));
    }

    public function test_plr_and_loudness_range_fields(): void
    {
        $this->track(['loudness_lufs' => -8.0, 'true_peak' => -0.5, 'loudness_range' => 4.0], 'Squashed'); // PLR 7.5
        $this->track(['loudness_lufs' => -20.0, 'true_peak' => -2.0, 'loudness_range' => 14.0], 'Dynamic'); // PLR 18
        $this->track(['loudness_lufs' => -12.0, 'true_peak' => null, 'loudness_range' => 8.0], 'No peak');

        $this->assertSame(['Dynamic'], $this->names([['field' => 'plr', 'operator' => 'gte', 'value' => 14]]));
        $this->assertSame(['Squashed'], $this->names([['field' => 'plr', 'operator' => 'lte', 'value' => 10]]));
        $this->assertSame(['Dynamic'], $this->names([['field' => 'loudness_range', 'operator' => 'gte', 'value' => 10]]));
        $this->assertEqualsCanonicalizing(['Squashed', 'No peak'], $this->names([['field' => 'loudness_range', 'operator' => 'lte', 'value' => 9]]));
    }

    public function test_track_details_carry_camelot_and_plr(): void
    {
        $track = $this->track(['key' => 'F#', 'mode' => 'minor', 'loudness_lufs' => -9.0, 'true_peak' => -1.0]);

        $sound = $track->sound();

        $this->assertSame('11A', $sound['camelot']);
        $this->assertSame(8.0, $sound['plr']);
    }

    // --- Modes ---

    public function test_every_mode_is_a_valid_definition(): void
    {
        foreach (ListeningModes::all() as $key => $mode) {
            $this->assertNotEmpty($mode['definition']['rules'], "{$key} lost a rule in normalize()");
            $this->assertSame('all', $mode['definition']['match']);
        }
    }

    public function test_party_matches_danceable_upbeat_tracks_in_range_only(): void
    {
        $this->track(['bpm' => 120.0, 'danceability' => 0.9, 'arousal' => 0.6, 'valence' => 0.7], 'Party');
        $this->track(['bpm' => 120.0, 'danceability' => 0.4, 'arousal' => 0.6, 'valence' => 0.7], 'Not danceable');
        $this->track(['bpm' => 80.0, 'danceability' => 0.9, 'arousal' => 0.6, 'valence' => 0.7], 'Too slow');
        $this->track(['bpm' => 120.0, 'danceability' => 0.9, 'arousal' => 0.6, 'valence' => 0.3], 'Gloomy');

        $names = ListeningModes::tracks('party')->pluck('tracks.name')->all();

        $this->assertSame(['Party'], $names);
    }

    public function test_sleep_and_focus_and_workout(): void
    {
        $this->track(['bpm' => 70.0, 'danceability' => 0.3, 'arousal' => 0.3, 'mood_tags' => [['name' => 'relaxed', 'score' => 0.9]]], 'Lullaby');
        $this->track(['bpm' => 90.0, 'danceability' => 0.3, 'arousal' => 0.4, 'instrumental' => 0.8], 'Piano');
        $this->track(['bpm' => 150.0, 'danceability' => 0.8, 'arousal' => 0.7, 'loudness_lufs' => -7.0], 'Run');
        $this->track(['bpm' => 150.0, 'danceability' => 0.8, 'arousal' => 0.7, 'loudness_lufs' => -16.0], 'Quiet run');

        $this->assertSame(['Lullaby'], ListeningModes::tracks('sleep')->pluck('tracks.name')->all());
        $this->assertSame(['Piano'], ListeningModes::tracks('focus')->pluck('tracks.name')->all());
        $this->assertSame(['Run'], ListeningModes::tracks('workout')->pluck('tracks.name')->all());
    }

    public function test_a_mode_with_too_few_tracks_is_not_offered(): void
    {
        $this->party(ListeningModes::MIN_TRACKS - 1);
        $this->assertArrayNotHasKey('party', ListeningModes::available());

        Cache::flush();
        $this->party(1);
        $available = ListeningModes::available();

        $this->assertArrayHasKey('party', $available);
        $this->assertSame(ListeningModes::MIN_TRACKS, $available['party']['track_count']);
        $this->assertArrayNotHasKey('sleep', $available);
    }

    public function test_an_unknown_mode_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ListeningModes::tracks('nope');
    }

    // --- API ---

    public function test_api_lists_the_modes_the_library_can_serve(): void
    {
        $this->party(ListeningModes::MIN_TRACKS);

        $this->getJson('/api/library/listening-modes')
            ->assertOk()
            ->assertExactJson(['data' => [[
                'key' => 'party',
                'name' => 'Party',
                'description' => 'Danceable, upbeat and positive, between 100 and 140 BPM.',
                'icon' => 'champagne-glasses',
                'track_count' => ListeningModes::MIN_TRACKS,
            ]]]);
    }

    public function test_api_lists_nothing_without_analysed_tracks(): void
    {
        $this->getJson('/api/library/listening-modes')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_api_plays_a_mode_on_a_device(): void
    {
        $this->party(ListeningModes::MIN_TRACKS + 2);
        $device = $this->device();

        $this->postJson("/api/devices/{$device->id}/library/play-listening-mode", ['mode' => 'party'])
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'mode' => 'party', 'queued' => ListeningModes::MIN_TRACKS + 2]);

        $this->assertCount(ListeningModes::MIN_TRACKS + 2, FakeLibraryPlaybackDriver::$calls[0]['tracks']);
    }

    public function test_api_play_rejects_unknown_modes_and_thin_modes(): void
    {
        $device = $this->device();

        $this->postJson("/api/devices/{$device->id}/library/play-listening-mode", ['mode' => 'nope'])->assertStatus(422);
        $this->postJson("/api/devices/{$device->id}/library/play-listening-mode", [])->assertStatus(422);

        $this->party(ListeningModes::MIN_TRACKS - 1);
        $this->postJson("/api/devices/{$device->id}/library/play-listening-mode", ['mode' => 'party'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'not_playable');
        $this->assertSame([], FakeLibraryPlaybackDriver::$calls);
    }

    // --- Web ---

    public function test_library_page_shows_the_modes_in_the_local_scope_only(): void
    {
        $this->get('/library?scope=local')->assertOk()->assertDontSee('Listening modes');

        Cache::flush();
        $this->party(ListeningModes::MIN_TRACKS);

        $this->get('/library?scope=local')->assertOk()->assertSee('Listening modes')->assertSee('Party');
        $this->get('/library?scope=streaming')->assertOk()->assertDontSee('Listening modes');
        $this->get('/library?scope=all')->assertOk()->assertDontSee('Listening modes');
    }

    public function test_modes_ignore_the_leading_source(): void
    {
        $this->party(ListeningModes::MIN_TRACKS);
        Setting::set(LeadingSource::SETTING, 'spotify');
        Cache::flush();

        $this->assertArrayHasKey('party', ListeningModes::available());
    }

    public function test_web_play_needs_a_pinned_player_and_a_known_mode(): void
    {
        $this->post('/library/listening-modes/nope')->assertNotFound();
        $this->post('/library/listening-modes/party')->assertSessionHas('error');
    }
}
