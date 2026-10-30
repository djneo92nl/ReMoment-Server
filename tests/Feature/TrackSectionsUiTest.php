<?php

namespace Tests\Feature;

use App\Domain\Analysis\DeepAnalysis;
use App\Jobs\AnalyzeTrackDeep;
use App\Livewire\TrackSections;
use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** The sections strip (x-section-strip) and the track-details block with the admin's "Analyse sections" request. */
class TrackSectionsUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['analysis.enabled' => true, 'analysis.structure_url' => 'http://structure:8091']);
        Http::fake(['structure:8091/health' => Http::response(['ok' => true])]);
    }

    /** @return array{0: File, 1: Track} */
    private function track(?array $sections = null): array
    {
        $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => sys_get_temp_dir()], 'role' => 'library']);
        $artist = Artist::create(['name' => 'Band', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'LP', 'source' => 'dlna']);
        $file = File::create(['storage_location_id' => $location->id, 'path' => 'a.flac', 'hash' => 'h1', 'size' => 1, 'mtime' => 1, 'status' => File::PRESENT, 'extension' => 'flac']);
        $track = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => 'Song', 'duration' => 200, 'source' => 'dlna', 'external_id' => "local:file:{$file->id}"]);
        TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        if ($sections !== null) {
            FileAnalysis::create(['file_id' => $file->id, 'content_hash' => 'h1', 'sections' => $sections]);
        }

        return [$file, $track];
    }

    private function stored(array $items, ?string $reason = null): array
    {
        return ['items' => $items, 'reason' => $reason, 'method' => 'novelty-v1'];
    }

    public function test_the_strip_shows_letters_only_where_confidence_supports_them(): void
    {
        $html = Blade::render('<x-section-strip :sections="$s" :duration="200" :labels="true" />', ['s' => [
            ['start' => 0, 'end' => 50, 'label' => 'A', 'confidence' => 0.71],
            ['start' => 50, 'end' => 100, 'label' => 'B', 'confidence' => 0.3],
            ['start' => 100, 'end' => 200, 'label' => null, 'confidence' => 0],
        ]]);

        $this->assertSame(3, substr_count($html, 'data-start='));
        $this->assertStringContainsString('>A</span>', $html);
        $this->assertStringNotContainsString('>B</span>', $html);
    }

    public function test_a_seekable_strip_seeks_to_the_section_start(): void
    {
        $html = Blade::render('<x-section-strip :sections="$s" :duration="200" :seek="true" />', ['s' => [['start' => 23.1, 'end' => 200, 'label' => null, 'confidence' => 0]]]);

        $this->assertStringContainsString('seekToSeconds(23.1)', $html);
    }

    public function test_stored_sections_and_the_empty_state_show_to_a_guest_without_a_button(): void
    {
        [, $track] = $this->track($this->stored([['start' => 0, 'end' => 200, 'label' => 'A', 'confidence' => 0.9]]));
        Livewire::test(TrackSections::class, ['trackId' => $track->id])->assertSeeHtml('data-test="section-strip"')->assertDontSee('Analyse sections');
    }

    public function test_a_found_nothing_result_says_so(): void
    {
        [, $track] = $this->track($this->stored([], 'no_structure'));
        Livewire::test(TrackSections::class, ['trackId' => $track->id])->assertSee('No structure found');
    }

    public function test_a_guest_sees_no_button_and_cannot_request(): void
    {
        Queue::fake();
        [, $track] = $this->track();

        Livewire::test(TrackSections::class, ['trackId' => $track->id])->assertDontSee('Analyse sections')->call('analyse')->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_the_admin_requests_sections_and_sees_the_states(): void
    {
        Queue::fake();
        [$file, $track] = $this->track();
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(TrackSections::class, ['trackId' => $track->id])
            ->assertSee('Analyse sections')->assertSee('estimate')
            ->call('analyse')
            ->assertSeeHtml('data-test="sections-queued"')->assertSeeHtml('wire:poll.3s');
        Queue::assertPushed(AnalyzeTrackDeep::class);

        Cache::forget(DeepAnalysis::marker('queued', $file, 'sections'));
        Cache::put(DeepAnalysis::marker('running', $file, 'sections'), true, 60);
        $component->call('$refresh')->assertSeeHtml('data-test="sections-running"');
    }

    public function test_a_failed_run_shows_why_and_offers_a_retry(): void
    {
        [$file, $track] = $this->track();
        $this->actingAs(User::factory()->create());
        $stage = DeepAnalysis::stage('sections');
        FileAnalysis::create(['file_id' => $file->id, 'content_hash' => 'h1', 'errors' => [$stage->key() => ['version' => $stage->version(), 'message' => 'The structure service is not reachable', 'at' => now()->toIso8601String()]]]);

        Livewire::test(TrackSections::class, ['trackId' => $track->id])->assertSee('The structure service is not reachable')->assertSee('Try again');
    }

    public function test_no_button_while_the_service_is_unavailable(): void
    {
        [, $track] = $this->track();
        $this->actingAs(User::factory()->create());
        config(['analysis.structure_url' => null]);

        Livewire::test(TrackSections::class, ['trackId' => $track->id])->assertDontSee('Analyse sections')->assertSee('Not analysed');
    }

    public function test_the_admin_can_remove_stored_sections(): void
    {
        [$file, $track] = $this->track($this->stored([['start' => 0, 'end' => 200, 'label' => null, 'confidence' => 0]]));
        $file->analysis()->first()->update(['stages' => [DeepAnalysis::stage('sections')->key() => ['version' => DeepAnalysis::stage('sections')->version(), 'at' => now()->toIso8601String()]]]);
        $this->actingAs(User::factory()->create());

        Livewire::test(TrackSections::class, ['trackId' => $track->id])->call('remove');

        $this->assertNull($track->fresh()->sections());
    }
}
