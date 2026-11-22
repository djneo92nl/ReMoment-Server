<?php

namespace Tests\Feature\Library;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Library\LeadingSource;
use App\Domain\Library\SimilarTracks;
use App\Domain\Similar\Embeddings;
use App\Domain\Similar\SqliteVecIndex;
use App\Domain\Similar\VectorIndex;
use App\Models\Device;
use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\FileEmbedding;
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

/**
 * GET /api/library/tracks/{id}/similar, POST /api/devices/{id}/library/play-similar and features.similar_tracks:
 * ranking by embedding, what is never a result, scope, device, paging, stale vectors, the profile gate and the optional
 * vector index (docs/api/similar-tracks.md, docs/architecture/similar-tracks.md).
 */
class SimilarTracksTest extends TestCase
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

    /** A vector of 1280 numbers that starts with these and is zero after: the cosine of two of them is the one of their heads. */
    private static function vec(float ...$head): array
    {
        return array_pad($head, Embeddings::DIMENSIONS, 0.0);
    }

    /** The unit vector at an angle (degrees) from the seed's [1, 0]. */
    private static function at(float $degrees): array
    {
        return self::vec(cos(deg2rad($degrees)), sin(deg2rad($degrees)));
    }

    /**
     * An own-file track with an analysis row and, unless $vector is null, an embedding.
     *
     * @param  array<string, mixed>  $analysis  extra file_analyses columns
     */
    private function track(string $name, ?array $vector, string $streamSource = 'files', array $analysis = [], ?Album $album = null): Track
    {
        $track = $this->makeLibraryTrack($album ?? $this->album, $name, streamSource: $streamSource);
        $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => '/tmp'], 'role' => 'library']);
        $file = File::create(['storage_location_id' => $location->id, 'path' => "{$track->id}-{$name}.flac", 'hash' => "h{$track->id}", 'size' => 1, 'mtime' => 1, 'status' => File::PRESENT]);
        TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        FileAnalysis::create(['file_id' => $file->id, 'content_hash' => "h{$track->id}"] + $analysis);
        if ($vector !== null) {
            Embeddings::store($file, "h{$track->id}", $vector);
        }
        $this->n++;

        return $track;
    }

    /** @return list<string> */
    private function names(Track $track, string $query = ''): array
    {
        return collect($this->getJson("/api/library/tracks/{$track->id}/similar{$query}")->assertOk()->json('data'))->pluck('name')->all();
    }

    private function device(): Device
    {
        $device = Device::factory()->create(['ip_address' => '10.0.0.1', 'device_name' => 'Kitchen', 'device_driver' => FakeLibraryPlaybackDriver::class]);
        DeviceCache::updateState($device->id, State::Standby);

        return $device;
    }

    public function test_ranks_by_cosine_similarity_with_the_distance_and_why(): void
    {
        $seed = $this->track('Seed', self::at(0), analysis: ['bpm' => 100, 'mood_tags' => [['name' => 'relaxed', 'score' => 0.9], ['name' => 'acoustic', 'score' => 0.6]], 'genre_tags' => [['name' => 'Jazz', 'score' => 0.5]]]);
        $this->track('Far', self::at(80));
        $this->track('Near', self::at(10), analysis: ['bpm' => 103, 'mood_tags' => [['name' => 'relaxed', 'score' => 0.8]], 'genre_tags' => [['name' => 'Jazz', 'score' => 0.4], ['name' => 'Funk', 'score' => 0.2]]]);
        $this->track('Opposite', self::at(180));
        $this->track('Middle', self::at(45));

        $response = $this->getJson("/api/library/tracks/{$seed->id}/similar")->assertOk()
            ->assertJsonPath('canonical_id', $seed->id)
            ->assertJsonPath('source', ['model' => Embeddings::MODEL, 'dimensions' => 1280])
            ->assertJsonPath('next_cursor', null);

        $this->assertSame(['Near', 'Middle', 'Far', 'Opposite'], array_column($response->json('data'), 'name'));
        $this->assertEqualsWithDelta(cos(deg2rad(10)), $response->json('data.0.similarity'), 0.0001);
        $this->assertEqualsWithDelta(1 - cos(deg2rad(10)), $response->json('data.0.distance'), 0.0001);
        $this->assertEqualsWithDelta(-1.0, $response->json('data.3.similarity'), 0.0001);
        $response->assertJsonPath('data.0.reason', ['moods' => ['relaxed'], 'genres' => ['Jazz'], 'tempo_diff_percent' => 3, 'same_artist' => true]);
        $response->assertJsonPath('data.1.reason.tempo_diff_percent', null);
    }

    public function test_items_are_shelf_rows_that_name_the_seed(): void
    {
        $seed = $this->track('Seed', self::at(0));
        $this->track('Near', self::at(10));
        $this->track('Far', self::at(80));

        $items = (new SimilarTracks($seed))->items(1);

        $this->assertCount(1, $items);
        $this->assertSame('Near', $items[0]['name']);
        $this->assertSame('Band', $items[0]['artist_name']);
        $this->assertSame('Sounds like Seed by Band', $items[0]['reason']);
        $this->assertEqualsWithDelta(cos(deg2rad(10)), $items[0]['similarity'], 0.0001);
        $this->assertSame([], (new SimilarTracks($this->track('Plain', null)))->items(5));
    }

    public function test_equal_similarity_falls_back_to_the_track_id(): void
    {
        $seed = $this->track('Seed', self::at(0));
        $first = $this->track('First twin', self::at(20));
        $second = $this->track('Second twin', self::at(20));

        $this->assertSame([$first->name, $second->name], $this->names($seed));
    }

    public function test_never_the_track_itself_nor_the_same_recording(): void
    {
        $seed = $this->track('Song', self::at(0), analysis: ['acoustid_recording_mbid' => 'rec-1']);
        $otherAlbum = Album::create(['artist_id' => $this->album->artist_id, 'name' => 'Greatest hits', 'source' => 'dlna']);

        $this->track('Song', self::at(1), album: $otherAlbum); // same artist and title on another album
        $this->track('Retitled', self::at(2), analysis: ['acoustid_recording_mbid' => 'rec-1']); // same AcoustID recording
        $this->track('Other song', self::at(30), analysis: ['acoustid_recording_mbid' => 'rec-2']);
        $this->track('Unfingerprinted', self::at(40));

        $this->assertSame(['Other song', 'Unfingerprinted'], $this->names($seed));
    }

    public function test_tracks_without_a_current_embedding_are_not_listed_and_such_a_seed_is_422(): void
    {
        $seed = $this->track('Seed', self::at(0));
        $this->track('Fine', self::at(20));
        $this->track('No embedding', null);
        $changed = $this->track('Changed file', self::at(5));
        File::query()->whereKey(TrackFile::where('track_id', $changed->id)->value('file_id'))->update(['hash' => 'new']);
        FileAnalysis::query()->where('content_hash', "h{$changed->id}")->update(['content_hash' => 'new']); // analysed again, embedding of the old content
        $other = $this->track('Other model', self::at(6));
        FileEmbedding::query()->whereKey(TrackFile::where('track_id', $other->id)->value('file_id'))->update(['model' => 'another-net']);

        $this->assertSame(['Fine'], $this->names($seed));

        $plain = $this->track('Plain', null);
        $this->getJson("/api/library/tracks/{$plain->id}/similar")->assertStatus(422)->assertJsonPath('error', 'not_analysed');
        $this->getJson("/api/library/tracks/{$changed->id}/similar")->assertStatus(422)->assertJsonPath('error', 'not_analysed');
        $this->getJson('/api/library/tracks/99999/similar')->assertNotFound();
    }

    public function test_scope_hides_other_libraries(): void
    {
        $seed = $this->track('Seed', self::at(0));
        $this->track('Own file', self::at(10));
        $this->track('On the NAS', self::at(20), streamSource: 'dlna:1');

        $this->assertSame(['Own file', 'On the NAS'], $this->names($seed, '?scope=all'));
        $this->assertSame(['Own file'], $this->names($seed, '?scope=local'));
    }

    public function test_device_id_keeps_to_tracks_the_device_can_play(): void
    {
        $seed = $this->track('Seed', self::at(0));
        $this->track('Playable', self::at(10));
        $silent = $this->track('No stream', self::at(5));
        $silent->metadata()->delete();
        $device = $this->device();

        $this->assertSame(['No stream', 'Playable'], $this->names($seed));
        $this->assertSame(['Playable'], $this->names($seed, "?device_id={$device->id}"));
        $this->getJson("/api/library/tracks/{$seed->id}/similar?device_id=9999")->assertStatus(422);
    }

    public function test_pages_with_a_cursor(): void
    {
        $seed = $this->track('Seed', self::at(0));
        foreach (range(1, 52) as $i) {
            $this->track(sprintf('Match %02d', $i), self::at($i)); // nearer first
        }

        $first = $this->getJson("/api/library/tracks/{$seed->id}/similar")->assertOk();
        $this->assertCount(50, $first->json('data'));
        $this->assertSame('Match 01', $first->json('data.0.name'));
        $second = $this->getJson("/api/library/tracks/{$seed->id}/similar?cursor=".$first->json('next_cursor'))->assertOk();
        $this->assertSame(['Match 51', 'Match 52'], array_column($second->json('data'), 'name'));
        $this->assertNull($second->json('next_cursor'));
    }

    public function test_the_list_is_cut_at_the_configured_depth(): void
    {
        config(['similar.max_results' => 3]);
        $seed = $this->track('Seed', self::at(0));
        foreach (range(1, 5) as $i) {
            $this->track("Match {$i}", self::at($i));
        }

        $this->assertSame(['Match 1', 'Match 2', 'Match 3'], $this->names($seed));
    }

    public function test_a_new_embedding_is_not_hidden_by_the_cache(): void
    {
        $seed = $this->track('Seed', self::at(0));
        $this->track('Far', self::at(80));
        $this->assertSame(['Far'], $this->names($seed));

        $this->track('Near', self::at(5));
        $this->assertSame(['Near', 'Far'], $this->names($seed));
    }

    // --- profile and features ---------------------------------------------------------

    public function test_the_routes_need_the_full_profile(): void
    {
        config(['profile.current' => 'standard']);

        $this->getJson('/api/library/tracks/1/similar')->assertStatus(422)->assertJsonPath('error', 'not_available_in_profile')->assertJsonPath('feature', 'analysis');
        $this->postJson('/api/devices/1/library/play-similar', ['track_id' => 1])->assertStatus(422)->assertJsonPath('feature', 'analysis');

        config(['profile.current' => 'control']);
        $this->getJson('/api/library/tracks/1/similar')->assertStatus(422)->assertJsonPath('error', 'not_available_in_profile');
    }

    public function test_discover_has_a_because_you_played_shelf_from_the_track_played_last(): void
    {
        config(['profile.current' => 'full']);
        $seed = $this->track('Seed', self::at(0));
        $this->track('Near', self::at(10));
        $this->track('Far', self::at(80));

        $this->assertNull(collect(\App\Domain\Library\Discover::sections([], ['similar_tracks']))->first(), 'no play yet, no shelf');

        $device = $this->device();
        \App\Models\Play::create(['device_id' => $device->id, 'track_id' => $seed->id, 'source_type' => 'dlna', 'played_at' => now()->subDay()]);
        Cache::flush();

        $section = \App\Domain\Library\Discover::sections([], ['similar_tracks'])[0];

        $this->assertSame('Because you played Seed', $section['title']);
        $this->assertSame(['Near', 'Far'], array_column($section['items'], 'name'));
        $this->assertSame('Sounds like Seed by Band', $section['items'][0]['reason']);
        $this->assertSame($seed->id, $section['seed']['id']);
    }

    public function test_info_lists_the_feature_only_once_an_embedding_exists(): void
    {
        $this->getJson('/api/info')->assertOk()->assertJsonMissingPath('features.similar_tracks');

        $this->track('Seed', self::at(0));
        $this->getJson('/api/info')->assertJsonPath('features.similar_tracks', true);

        config(['profile.current' => 'standard']);
        $this->getJson('/api/info')->assertJsonMissingPath('features.similar_tracks');
    }

    // --- similar tracks radio ---------------------------------------------------------

    public function test_play_similar_queues_the_seed_then_the_nearest_in_order(): void
    {
        $seed = $this->track('Seed', self::at(0));
        foreach ([60, 10, 40, 20, 30, 50] as $degrees) {
            $this->track("At {$degrees}", self::at($degrees));
        }
        $this->track('Foreign', self::at(5), streamSource: 'dlna:1'); // a library that is not in the scope of the radio
        $device = $this->device();

        $this->postJson("/api/devices/{$device->id}/library/play-similar", ['track_id' => $seed->id, 'scope' => 'local'])
            ->assertOk()->assertExactJson(['status' => 'ok', 'seed' => $seed->id, 'queued' => 7]);

        $this->assertSame(['Seed', 'At 10', 'At 20', 'At 30', 'At 40', 'At 50', 'At 60'], FakeLibraryPlaybackDriver::$calls[0]['tracks']->pluck('name')->all());
    }

    public function test_play_similar_refuses_what_it_cannot_play(): void
    {
        $seed = $this->track('Seed', self::at(0));
        foreach (range(1, 4) as $i) {
            $this->track("Match {$i}", self::at($i * 10));
        }
        $device = $this->device();
        $plain = $this->track('Plain', null);

        $this->postJson("/api/devices/{$device->id}/library/play-similar", ['track_id' => $seed->id])->assertStatus(422)->assertJsonPath('error', 'not_playable');
        $this->postJson("/api/devices/{$device->id}/library/play-similar", ['track_id' => $plain->id])->assertStatus(422)->assertJsonPath('error', 'not_analysed');
        $this->postJson("/api/devices/{$device->id}/library/play-similar", [])->assertStatus(422);
        $this->postJson("/api/devices/{$device->id}/library/play-similar", ['track_id' => 99999])->assertStatus(422);
        $this->assertSame([], FakeLibraryPlaybackDriver::$calls);
    }

    // --- the optional vector index ----------------------------------------------------

    private function fakeIndex(array $hits, ?int $rows = null, bool $available = true): VectorIndex
    {
        return new class($hits, $rows, $available) implements VectorIndex
        {
            public int $asked = 0;

            public function __construct(private array $hits, private ?int $rows, private bool $available) {}

            public function available(): bool
            {
                return $this->available;
            }

            public function rowCount(): int
            {
                return $this->rows ?? Embeddings::count();
            }

            public function nearest(string $packed, int $k): array
            {
                $this->asked++;

                return array_slice($this->hits, 0, $k);
            }
        };
    }

    private function fileId(Track $track): int
    {
        return (int) TrackFile::where('track_id', $track->id)->value('file_id');
    }

    public function test_a_current_index_answers_narrowed_to_the_scope(): void
    {
        $seed = $this->track('Seed', self::at(0));
        $near = $this->track('Near', self::at(10));
        $nas = $this->track('On the NAS', self::at(15), streamSource: 'dlna:1');
        $far = $this->track('Far', self::at(70));

        // The index's order is taken as it is (proof it answered): Far before Near, the NAS track is out of scope.
        $index = $this->fakeIndex([
            ['file_id' => $this->fileId($far), 'similarity' => 0.9],
            ['file_id' => $this->fileId($nas), 'similarity' => 0.8],
            ['file_id' => $this->fileId($near), 'similarity' => 0.7],
            ['file_id' => $this->fileId($seed), 'similarity' => 1.0], // itself: never a result
        ]);

        $matches = (new SimilarTracks($seed, ['dlna'], null, $index))->matches();

        $this->assertSame([$far->id, $near->id], $matches->pluck('track_id')->all());
        $this->assertSame(0.9, $matches[0]['similarity']);
        $this->assertSame(1, $index->asked);
    }

    public function test_an_out_of_date_or_missing_index_falls_back_to_the_scan(): void
    {
        $seed = $this->track('Seed', self::at(0));
        $near = $this->track('Near', self::at(10));
        $far = $this->track('Far', self::at(70));
        $wrongOrder = [['file_id' => $this->fileId($far), 'similarity' => 0.9], ['file_id' => $this->fileId($near), 'similarity' => 0.1]];

        foreach ([$this->fakeIndex($wrongOrder, rows: 1), $this->fakeIndex($wrongOrder, available: false)] as $index) {
            Cache::flush();
            $this->assertSame([$near->id, $far->id], (new SimilarTracks($seed, [], null, $index))->matches()->pluck('track_id')->all());
            $this->assertSame(0, $index->asked);
        }
    }

    public function test_an_index_cut_short_by_the_scope_falls_back_to_the_scan(): void
    {
        config(['similar.max_results' => 2, 'similar.vec_overfetch' => 1]); // asks for 2, gets 2 (full), keeps 1 in scope
        $seed = $this->track('Seed', self::at(0));
        $nas = $this->track('On the NAS', self::at(5), streamSource: 'dlna:1');
        $own = $this->track('Own', self::at(10));
        $far = $this->track('Own far', self::at(30));

        $index = $this->fakeIndex([['file_id' => $this->fileId($nas), 'similarity' => 0.99], ['file_id' => $this->fileId($own), 'similarity' => 0.98], ['file_id' => $this->fileId($far), 'similarity' => 0.5]]);

        $this->assertSame([$own->id, $far->id], (new SimilarTracks($seed, ['dlna'], null, $index))->matches()->pluck('track_id')->all());
        $this->assertSame(1, $index->asked);
    }

    public function test_sqlite_vec_is_unavailable_without_its_library_and_the_command_says_so(): void
    {
        $this->assertFalse((new SqliteVecIndex(null, '/tmp/none.sqlite'))->available());
        $this->assertFalse((new SqliteVecIndex('/no/such/vec0.so', '/tmp/none.sqlite'))->available());
        $this->assertSame(0, (new SqliteVecIndex('/no/such/vec0.so', '/tmp/none.sqlite'))->rowCount());

        config(['similar.engine' => 'sqlite-vec', 'similar.vec_extension' => null]);
        $seed = $this->track('Seed', self::at(0));
        $this->track('Near', self::at(10));
        $this->assertSame(['Near'], $this->names($seed)); // chosen but missing: the scan answers

        $this->artisan('similar:index')->assertFailed();
        $this->artisan('similar:index', ['--status' => true])->assertSuccessful();
    }
}
