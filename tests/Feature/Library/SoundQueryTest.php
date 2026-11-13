<?php

namespace Tests\Feature\Library;

use App\Domain\Library\LeadingSource;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeLibraryPlaybackDriver;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** Sound filters on GET /library/tracks, include=analysis, count_only, sort=plr|bpm|arousal and a custom filter on play-listening-mode (docs/api/sound-query.md). */
class SoundQueryTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    private Album $album;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set(LeadingSource::SETTING, 'all');
        Cache::flush();
        FakeLibraryPlaybackDriver::reset();
        $this->album = Album::create(['artist_id' => Artist::create(['name' => 'Band', 'source' => 'files'])->id, 'name' => 'LP', 'source' => 'files']);
    }

    private function t(string $name, array $analysis): void
    {
        $this->makeAnalysedTrack($this->album, $name, $analysis);
    }

    /** @return list<string> */
    private function names(string $query): array
    {
        return collect($this->getJson("/api/library/tracks?sort=name&{$query}")->assertOk()->json('data'))->pluck('name')->all();
    }

    private function library(): void
    {
        $this->t('Calm', ['bpm' => 80, 'key' => 'A', 'mode' => 'minor', 'arousal' => 0.35, 'valence' => 0.4, 'danceability' => 0.3, 'instrumental' => 0.9, 'mood_tags' => [['name' => 'relaxed', 'score' => 0.9]], 'true_peak' => -0.5, 'loudness_lufs' => -14.5, 'lossless_suspect' => 0.1, 'measured_bit_depth' => 24, 'clipping_suspect' => false]);
        $this->t('Drive', ['bpm' => 120, 'key' => 'C', 'mode' => 'major', 'arousal' => 0.65, 'valence' => 0.7, 'danceability' => 0.8, 'instrumental' => 0.1, 'mood_tags' => [['name' => 'party', 'score' => 0.8], ['name' => 'happy', 'score' => 0.6]], 'true_peak' => -0.1, 'loudness_lufs' => -7.1, 'lossless_suspect' => 0.9, 'measured_bit_depth' => 16, 'clipping_suspect' => true]);
        $this->t('Fast', ['bpm' => 150, 'key' => 'E', 'mode' => 'minor', 'arousal' => 0.6]);
        $this->makeLibraryTrack($this->album, 'Unanalysed');
    }

    public function test_filters_combine_and_unanalysed_tracks_never_match(): void
    {
        $this->library();

        $this->assertSame(['Calm', 'Drive', 'Fast', 'Unanalysed'], $this->names(''));
        $this->assertSame(['Calm', 'Drive'], $this->names('bpm_min=70&bpm_max=125&arousal_max=0.7&valence_min=0.3'));
        $this->assertSame(['Drive'], $this->names('bpm_min=100&bpm_max=125'));
        $this->assertSame(['Calm'], $this->names('arousal_max=0.4'));
        $this->assertSame(['Calm', 'Fast'], $this->names('camelot=8A,9A'));
        $this->assertSame(['Drive'], $this->names('camelot=8B'));
        $this->assertSame(['Calm'], $this->names('instrumental_min=0.5&danceability_min=0.2'));
        $this->assertSame(['Calm'], $this->names('plr_min=13'));          // -0.5 - -14.5 = 14; Drive = 7
        $this->assertSame(['Drive'], $this->names('mood=party'));
        $this->assertSame(['Calm', 'Drive'], $this->names('mood=relaxed,happy'));
        $this->assertSame(['Calm'], $this->names('lossless_suspect_max=0.5'));  // Fast has no value: no match
        $this->assertSame(['Calm'], $this->names('measured_bit_depth_min=24'));
        $this->assertSame(['Drive'], $this->names('clipping=1'));
        $this->assertSame(['Calm'], $this->names('clipping=0'));
    }

    public function test_include_analysis_and_count_only(): void
    {
        $this->library();

        $rows = collect($this->getJson('/api/library/tracks?include=analysis&sort=name')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(['bpm', 'camelot', 'arousal', 'valence', 'danceability', 'instrumental', 'plr', 'loudness'], array_keys($rows['Calm']['analysis']));
        $this->assertEquals(80, $rows['Calm']['analysis']['bpm']);
        $this->assertSame('8A', $rows['Calm']['analysis']['camelot']);
        $this->assertEquals(14, $rows['Calm']['analysis']['plr']);
        $this->assertNull($rows['Fast']['analysis']['valence']);
        $this->assertNull($rows['Unanalysed']['analysis']);
        $this->assertArrayNotHasKey('analysis', $this->getJson('/api/library/tracks')->json('data.0'));

        $this->getJson('/api/library/tracks?count_only=1&bpm_min=100')->assertOk()->assertExactJson(['total' => 2]);
        $this->getJson('/api/library/tracks?count_only=1')->assertExactJson(['total' => 4]);
    }

    public function test_sorting_by_analysis_puts_unknown_last(): void
    {
        $this->library();

        $order = fn (string $sort) => collect($this->getJson("/api/library/tracks?sort={$sort}")->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Fast', 'Drive', 'Calm', 'Unanalysed'], $order('bpm'));
        $this->assertSame(['Drive', 'Fast', 'Calm', 'Unanalysed'], $order('arousal'));
        $this->assertSame(['Calm', 'Drive'], array_slice($order('plr'), 0, 2));
        $this->assertSame('Unanalysed', $order('plr')[3]);
    }

    public function test_albums_sort_by_the_mean_of_their_tracks(): void
    {
        $this->library();
        $other = Album::create(['artist_id' => $this->album->artist_id, 'name' => 'Fast LP', 'source' => 'files']);
        $this->makeAnalysedTrack($other, 'X', ['bpm' => 170]);
        $none = Album::create(['artist_id' => $this->album->artist_id, 'name' => 'Unanalysed LP', 'source' => 'files']);
        $this->makeLibraryTrack($none, 'Y');

        $names = collect($this->getJson('/api/library/albums?sort=bpm')->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Fast LP', 'LP', 'Unanalysed LP'], $names);
    }

    public function test_bad_filter_values_are_422(): void
    {
        $this->getJson('/api/library/tracks?arousal_min=2')->assertStatus(422);
        $this->getJson('/api/library/tracks?camelot=99Z')->assertStatus(422);
        $this->getJson('/api/library/tracks?mood=grumpy')->assertStatus(422);
        $this->getJson('/api/library/tracks?bpm_min=fast')->assertStatus(422);
        $this->getJson('/api/library/tracks?sort=nope')->assertStatus(422);
    }

    public function test_play_listening_mode_takes_a_custom_filter(): void
    {
        foreach (range(1, 6) as $i) {
            $this->t("Slow {$i}", ['bpm' => 70 + $i, 'arousal' => 0.3]);
        }
        $this->t('Quick', ['bpm' => 160, 'arousal' => 0.8]);
        $device = Device::factory()->create(['ip_address' => '10.0.0.1', 'device_name' => 'Kitchen', 'device_driver' => FakeLibraryPlaybackDriver::class]);
        \App\Domain\Device\DeviceCache::updateState($device->id, \App\Domain\Device\State::Standby);

        $this->postJson("/api/devices/{$device->id}/library/play-listening-mode", ['filter' => ['bpm_max' => 100, 'arousal_max' => 0.5]])
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'queued' => 6]);
        $this->assertCount(6, FakeLibraryPlaybackDriver::$calls[0]['tracks']);

        FakeLibraryPlaybackDriver::reset();
        $this->postJson("/api/devices/{$device->id}/library/play-listening-mode", ['filter' => ['bpm_min' => 150]])
            ->assertStatus(422)->assertJsonPath('error', 'not_playable');
        $this->postJson("/api/devices/{$device->id}/library/play-listening-mode", ['filter' => []])->assertStatus(422);
        $this->postJson("/api/devices/{$device->id}/library/play-listening-mode", ['filter' => ['arousal_min' => 5]])->assertStatus(422);
        $this->postJson("/api/devices/{$device->id}/library/play-listening-mode", [])->assertStatus(422);
        $this->assertSame([], FakeLibraryPlaybackDriver::$calls);
    }
}
