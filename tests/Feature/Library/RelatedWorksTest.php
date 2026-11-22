<?php

namespace Tests\Feature\Library;

use App\Domain\Library\LeadingSource;
use App\Domain\Library\RelatedWorks;
use App\Jobs\EnrichTrackMusicBrainz;
use App\Livewire\TrackRelated;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** Related works from MusicBrainz relationships (roadmap 12.7). The fixture is a real recording lookup (Gary Jules, Mad World). */
class RelatedWorksTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    private const WORK = '879ba403-a134-3b8d-85e2-06bc4a22d339';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set(LeadingSource::SETTING, 'all');
    }

    private function recording(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/musicbrainz/recording-mad-world-rels.json')), true);
    }

    private function track(string $name, string $artist = 'Gary Jules'): Track
    {
        $album = Album::create(['artist_id' => Artist::firstOrCreate(['name' => $artist], ['source' => 'files'])->id, 'name' => $name.' LP', 'source' => 'files']);

        return $this->makeLibraryTrack($album, $name);
    }

    private function meta(Track $track, string $key, string $value, string $source = 'musicbrainz'): void
    {
        Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $track->id, 'key' => $key, 'value' => $value, 'type' => 'string', 'source' => $source]);
    }

    public function test_a_real_recording_response_gives_the_cover_and_the_work_relations(): void
    {
        $parsed = RelatedWorks::parse($this->recording());

        $this->assertSame(self::WORK, $parsed['work_mbid']);
        $byRelation = collect($parsed['items'])->keyBy('relation');

        $this->assertSame(['cover_of', 'arrangement', 'musical_quotation', 'other_version'], $byRelation->keys()->all());
        // The real recording lookup carries no writer relations for the work, so there is no "written by" to show.
        $this->assertSame(['Cover of', 'work', 'Mad World', null], [$byRelation['cover_of']['label'], $byRelation['cover_of']['target'], $byRelation['cover_of']['title'], $byRelation['cover_of']['artist']]);
        $this->assertSame(['Has an arrangement', 'arr. McIntosh'], [$byRelation['arrangement']['label'], $byRelation['arrangement']['detail']]);
        $this->assertSame('Is quoted in', $byRelation['musical_quotation']['label']);
        $this->assertSame(['Other version', 'Mad Mares'], [$byRelation['other_version']['label'], $byRelation['other_version']['title']]);
    }

    public function test_a_cover_names_the_writers_when_the_lookup_carries_them(): void
    {
        $parsed = RelatedWorks::parse(['relations' => [['type' => 'performance', 'direction' => 'forward', 'target-type' => 'work', 'attributes' => ['cover', 'live'],
            'work' => ['id' => 'w1', 'title' => 'Song', 'relations' => [
                ['type' => 'composer', 'target-type' => 'artist', 'artist' => ['name' => 'A']],
                ['type' => 'lyricist', 'target-type' => 'artist', 'artist' => ['name' => 'B']],
                ['type' => 'composer', 'target-type' => 'artist', 'artist' => ['name' => 'A']],
            ]]]]]);

        $this->assertSame('A, B', $parsed['items'][0]['artist']);
    }

    public function test_recording_to_recording_relations_follow_their_direction(): void
    {
        $parsed = RelatedWorks::parse(['relations' => [
            ['type' => 'remix', 'direction' => 'forward', 'target-type' => 'recording', 'recording' => ['id' => 'r1', 'title' => 'Original', 'disambiguation' => 'album version']],
            ['type' => 'samples material', 'direction' => 'backward', 'target-type' => 'recording', 'recording' => ['id' => 'r2', 'title' => 'Sampler']],
            ['type' => 'producer', 'direction' => 'backward', 'target-type' => 'artist', 'artist' => ['id' => 'a', 'name' => 'Someone']],
        ]]);

        $this->assertNull($parsed['work_mbid']);
        $this->assertSame(['Remix of', 'Is sampled by'], array_column($parsed['items'], 'label'));
        $this->assertSame('album version', $parsed['items'][0]['detail']);
    }

    public function test_the_job_stores_them_and_the_recording_lookup_asks_for_recording_relations(): void
    {
        Queue::fake();
        Http::fake(['musicbrainz.org/ws/2/recording/*' => Http::response($this->recording())]);
        $track = $this->track('Mad World');
        $this->meta($track, 'mbid', $this->recording()['id'], 'acoustid');

        (new EnrichTrackMusicBrainz($track))->handle();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'recording-rels'));
        $this->assertSame(self::WORK, $track->metaValue('work_mbid'));
        $this->assertCount(4, json_decode($track->metadata()->where('key', 'related_works')->value('value'), true));
    }

    public function test_the_api_links_related_recordings_and_other_versions_in_the_library(): void
    {
        $cover = $this->track('Mad World');
        $original = $this->track('Mad World', 'Tears for Fears');
        $remix = $this->track('Mad World (Remix)', 'Someone');
        $this->meta($cover, 'work_mbid', self::WORK);
        $this->meta($cover, 'related_works', json_encode([
            ['relation' => 'remix_of', 'label' => 'Remix of', 'target' => 'recording', 'mbid' => 'rec-remix', 'title' => 'Mad World (Remix)', 'artist' => null, 'detail' => null],
            ['relation' => 'cover_of', 'label' => 'Cover of', 'target' => 'work', 'mbid' => self::WORK, 'title' => 'Mad World', 'artist' => 'Roland Orzabal', 'detail' => null],
            ['relation' => 'other_version', 'label' => 'Other version', 'target' => 'work', 'mbid' => 'work-unowned', 'title' => 'Mad Mares', 'artist' => null, 'detail' => null],
        ]), 'musicbrainz');
        $this->meta($original, 'work_mbid', self::WORK);
        $this->meta($remix, 'mbid', 'rec-remix');

        $r = $this->getJson("/api/library/tracks/{$cover->id}/related")->assertOk()->json();

        $this->assertSame($cover->id, $r['canonical_id']);
        $this->assertSame([$remix->id, $original->id, null], array_map(fn ($i) => $i['track']['id'] ?? null, $r['data']));
        $this->assertSame('Remix of', $r['data'][0]['label']);
        $this->assertSame([$original->id], array_column($r['versions'], 'id'));
        $this->assertSame('Tears for Fears', $r['versions'][0]['artist_name']);
    }

    public function test_a_track_without_data_answers_empty_and_the_gates_apply(): void
    {
        $t = $this->track('Plain');

        $this->getJson("/api/library/tracks/{$t->id}/related")->assertOk()->assertExactJson(['canonical_id' => $t->id, 'data' => [], 'versions' => []]);
        $this->getJson('/api/library/tracks/9999/related')->assertNotFound();

        config(['profile.current' => 'control']);
        $this->getJson("/api/library/tracks/{$t->id}/related")->assertStatus(422)->assertJsonPath('error', 'not_available_in_profile');
    }

    public function test_the_component_and_the_mcp_kind(): void
    {
        $cover = $this->track('Mad World');
        $this->meta($cover, 'related_works', json_encode([['relation' => 'cover_of', 'label' => 'Cover of', 'target' => 'work', 'mbid' => self::WORK, 'title' => 'Mad World', 'artist' => 'Roland Orzabal', 'detail' => null]]), 'musicbrainz');

        Livewire::test(TrackRelated::class, ['trackId' => $cover->id])->assertSee('Cover of')->assertSee('Roland Orzabal');
        Livewire::test(TrackRelated::class, ['trackId' => $this->track('Plain')->id])->assertSee('Nothing related found');

        ReMomentServer::tool(Tools\BrowseLibrary::class, ['kind' => 'related', 'id' => (string) $cover->id])->assertOk()->assertSee('Cover of');
    }
}
