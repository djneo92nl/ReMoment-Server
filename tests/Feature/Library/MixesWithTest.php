<?php

namespace Tests\Feature\Library;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Library\LeadingSource;
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

/** GET /api/library/tracks/{id}/mixes-with: tempo (also half/double), Camelot key, sorting, scope, device, paging. */
class MixesWithTest extends TestCase
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

    private function track(float $bpm, string $key, string $mode, ?string $name = null, string $streamSource = 'files', array $extra = [], ?Album $album = null): Track
    {
        $name ??= 'Track '.++$this->n;
        $track = $this->makeLibraryTrack($album ?? $this->album, $name, streamSource: $streamSource);
        $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => '/tmp'], 'role' => 'library']);
        $file = File::create(['storage_location_id' => $location->id, 'path' => "{$track->id}-{$name}.flac", 'hash' => "{$track->id}", 'size' => 1, 'mtime' => 1, 'status' => File::PRESENT]);
        TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        FileAnalysis::create(['file_id' => $file->id, 'content_hash' => $name, 'bpm' => $bpm, 'key' => $key, 'mode' => $mode] + $extra);

        return $track;
    }

    /** @return list<string> */
    private function names(Track $track, string $query = ''): array
    {
        return collect($this->getJson("/api/library/tracks/{$track->id}/mixes-with{$query}")->assertOk()->json('data'))->pluck('name')->all();
    }

    public function test_lists_compatible_tracks_sorted_by_key_relation_then_tempo(): void
    {
        $source = $this->track(120, 'A', 'minor', 'Source'); // 8A

        $this->track(125, 'A', 'minor', 'Same key, +4.2 %');
        $this->track(121, 'A', 'minor', 'Same key, +0.8 %');
        $this->track(120, 'C', 'major', 'Relative 8B');
        $this->track(119, 'E', 'minor', 'Adjacent 9A');
        $this->track(120, 'D', 'minor', 'Adjacent 7A');
        $this->track(60, 'A', 'minor', 'Half time');
        $this->track(241, 'A', 'minor', 'Double time, +0.4 %');
        $this->track(140, 'A', 'minor', 'Too fast');
        $this->track(120, 'B', 'minor', 'Wrong key 10A');
        $this->track(120, 'A', 'major', 'Wrong key 11B');
        $this->track(120, 'G', 'major', 'Wrong key 9B');

        $this->assertSame([
            'Half time', // same key first; 0 % off half time
            'Double time, +0.4 %',
            'Same key, +0.8 %',
            'Same key, +4.2 %',
            'Relative 8B', // then the relative key
            'Adjacent 7A', // then one step on the wheel, nearest tempo first
            'Adjacent 9A',
        ], $this->names($source));
    }

    public function test_the_response_says_why_each_track_matches(): void
    {
        $source = $this->track(120, 'A', 'minor', 'Source');
        $this->track(61, 'E', 'minor', 'Match');

        $this->getJson("/api/library/tracks/{$source->id}/mixes-with")->assertOk()
            ->assertJsonPath('canonical_id', $source->id)
            ->assertJsonPath('source', ['bpm' => 120, 'camelot' => '8A'])
            ->assertJsonPath('data.0.camelot', '9A')
            ->assertJsonPath('data.0.key_relation', 'adjacent')
            ->assertJsonPath('data.0.tempo_relation', 'half')
            ->assertJsonPath('data.0.tempo_diff_percent', 1.7)
            ->assertJsonPath('data.0.bpm', 61)
            ->assertJsonPath('next_cursor', null);
    }

    public function test_never_the_same_recording(): void
    {
        $source = $this->track(120, 'A', 'minor', 'Song', extra: ['acoustid_recording_mbid' => 'rec-1']);
        $otherAlbum = Album::create(['artist_id' => $this->album->artist_id, 'name' => 'Greatest hits', 'source' => 'dlna']);

        $this->track(120, 'A', 'minor', 'Song', album: $otherAlbum); // same artist and title on another album
        $this->track(120, 'A', 'minor', 'Different title', extra: ['acoustid_recording_mbid' => 'rec-1']); // same AcoustID recording
        $this->track(120, 'A', 'minor', 'Other song', extra: ['acoustid_recording_mbid' => 'rec-2']);
        $this->track(120, 'A', 'minor', 'Unfingerprinted');

        $this->assertSame(['Other song', 'Unfingerprinted'], $this->names($source));
    }

    public function test_tracks_without_tempo_or_key_are_not_listed_and_an_unanalysed_source_is_422(): void
    {
        $source = $this->track(120, 'A', 'minor', 'Source');
        $this->track(120, 'A', 'minor', 'Fine');
        $this->track(0, 'A', 'minor', 'No tempo');
        $this->track(120, '', 'minor', 'No key');
        $this->assertSame(['Fine'], $this->names($source));

        $plain = $this->makeLibraryTrack($this->album, 'Not analysed');
        $this->getJson("/api/library/tracks/{$plain->id}/mixes-with")->assertStatus(422)->assertJsonPath('error', 'not_analysed');
        $this->getJson('/api/library/tracks/99999/mixes-with')->assertNotFound();
    }

    public function test_scope_hides_other_libraries(): void
    {
        $source = $this->track(120, 'A', 'minor', 'Source');
        $this->track(120, 'A', 'minor', 'Own file');
        $this->track(120, 'A', 'minor', 'On the NAS', streamSource: 'dlna:1');

        $this->assertEqualsCanonicalizing(['Own file', 'On the NAS'], $this->names($source, '?scope=all'));
        $this->assertSame(['Own file'], $this->names($source, '?scope=local'));
    }

    public function test_device_id_keeps_to_tracks_the_device_can_play(): void
    {
        $source = $this->track(120, 'A', 'minor', 'Source');
        $this->track(120, 'A', 'minor', 'Playable');
        $silent = $this->track(120, 'A', 'minor', 'No stream');
        $silent->metadata()->delete();

        $device = Device::factory()->create(['ip_address' => '10.0.0.1', 'device_driver' => FakeLibraryPlaybackDriver::class]);
        DeviceCache::updateState($device->id, State::Standby);

        $this->assertEqualsCanonicalizing(['Playable', 'No stream'], $this->names($source));
        $this->assertSame(['Playable'], $this->names($source, "?device_id={$device->id}"));
        $this->getJson("/api/library/tracks/{$source->id}/mixes-with?device_id=9999")->assertStatus(422);
    }

    public function test_pages_with_a_cursor(): void
    {
        $source = $this->track(120, 'A', 'minor', 'Source');
        foreach (range(1, 52) as $i) {
            $this->track(120, 'A', 'minor', sprintf('Match %02d', $i));
        }

        $first = $this->getJson("/api/library/tracks/{$source->id}/mixes-with")->assertOk();
        $this->assertCount(50, $first->json('data'));
        $second = $this->getJson("/api/library/tracks/{$source->id}/mixes-with?cursor=".$first->json('next_cursor'))->assertOk();
        $this->assertSame(['Match 51', 'Match 52'], array_column($second->json('data'), 'name'));
        $this->assertNull($second->json('next_cursor'));
    }
}
