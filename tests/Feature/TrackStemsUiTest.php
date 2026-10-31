<?php

namespace Tests\Feature;

use App\Domain\Analysis\DeepAnalysis;
use App\Jobs\AnalyzeTrackDeep;
use App\Livewire\TrackStems;
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

/** The stem lanes (x-stem-lanes) and the track-details block with the admin's "Analyse stems" request. */
class TrackStemsUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['analysis.stems_url' => 'http://stems:8092', 'analysis.structure_url' => null]);
        Http::fake(['stems:8092/health' => Http::response(['ok' => true])]);
    }

    private function stored(): array
    {
        $loud = array_merge(array_fill(0, 100, -20), array_fill(0, 100, -80));

        return [
            'method' => 'htdemucs', 'service_version' => 1, 'rate_hz' => 2, 'vocal_ratio' => 0.5,
            'instrumental' => [['start' => 50.0, 'end' => 100.0]],
            'lanes' => ['vocals' => $loud, 'drums' => array_fill(0, 200, -25), 'bass' => array_fill(0, 200, -30), 'other' => array_fill(0, 200, -80)],
        ];
    }

    /** @return array{0: File, 1: Track} */
    private function track(bool $withStems = false, ?array $sections = null): array
    {
        $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => sys_get_temp_dir()], 'role' => 'library']);
        $artist = Artist::create(['name' => 'Band', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'LP', 'source' => 'dlna']);
        $file = File::create(['storage_location_id' => $location->id, 'path' => 'a.flac', 'hash' => 'h1', 'size' => 1, 'mtime' => 1, 'status' => File::PRESENT, 'extension' => 'flac']);
        $track = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => 'Song', 'duration' => 100, 'source' => 'dlna', 'external_id' => "local:file:{$file->id}"]);
        TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        if ($withStems || $sections) {
            FileAnalysis::create(['file_id' => $file->id, 'content_hash' => 'h1', 'stems' => $withStems ? $this->stored() : null, 'sections' => $sections]);
        }

        return [$file, $track];
    }

    public function test_the_lanes_draw_each_stem_with_the_instrumental_ranges_and_the_vocal_share(): void
    {
        $html = Blade::render('<x-stem-lanes :stems="$s" :instrumental="$i" :vocal-ratio="0.5" />', [
            's' => ['rate_hz' => 2] + $this->stored()['lanes'],
            'i' => [['start' => 50.0, 'end' => 100.0]],
        ]);

        foreach (['vocals', 'drums', 'bass', 'other'] as $lane) {
            $this->assertStringContainsString('data-lane="'.$lane.'"', $html);
        }
        $this->assertSame(4, substr_count($html, '<path d='));
        $this->assertSame(4, substr_count($html, 'data-instrumental'), 'the tint crosses every lane');
        $this->assertStringContainsString('left:50%;width:50%', $html);
        $this->assertStringContainsString('Vocals in 50 % of the track', $html);
        $this->assertStringContainsString('0:50 – 1:40', $html);
        $this->assertStringNotContainsString('data-test="section-strip"', $html, 'no strip without sections');
    }

    public function test_sections_sit_on_top_of_the_lanes_when_they_exist(): void
    {
        $html = Blade::render('<x-stem-lanes :stems="$s" :duration="100" :sections="$sec" />', [
            's' => ['rate_hz' => 2] + $this->stored()['lanes'],
            'sec' => [['start' => 0, 'end' => 40, 'label' => 'A', 'confidence' => 0.8], ['start' => 40, 'end' => 100, 'label' => null, 'confidence' => 0]],
        ]);

        $this->assertStringContainsString('data-test="section-strip"', $html);
        $this->assertLessThan(strpos($html, 'data-lane="vocals"'), strpos($html, 'data-test="section-strip"'));
        $this->assertStringContainsString('No instrumental section found', $html);
    }

    public function test_stored_stems_show_to_a_guest_without_a_button(): void
    {
        [, $track] = $this->track(withStems: true);

        Livewire::test(TrackStems::class, ['trackId' => $track->id])
            ->assertSeeHtml('data-test="stem-lanes"')->assertDontSee('Analyse stems')->assertDontSee('Remove');
    }

    public function test_the_admin_can_remove_stored_stems(): void
    {
        [$file, $track] = $this->track(withStems: true);
        $file->analysis()->first()->update(['stages' => ['stems' => ['version' => '1', 'at' => now()->toIso8601String()]]]);
        $this->actingAs(User::factory()->create());

        Livewire::test(TrackStems::class, ['trackId' => $track->id])->assertSee('Remove')->call('remove');

        $this->assertNull($track->fresh()->stems());
    }

    public function test_the_lanes_line_up_under_stored_sections(): void
    {
        [, $track] = $this->track(withStems: true, sections: ['items' => [['start' => 0, 'end' => 100, 'label' => null, 'confidence' => 0]], 'reason' => null, 'method' => 'novelty-v1']);

        Livewire::test(TrackStems::class, ['trackId' => $track->id])->assertSeeHtml('data-test="section-strip"')->assertSeeHtml('data-lane="vocals"');
    }

    public function test_a_guest_sees_no_button_and_cannot_request(): void
    {
        Queue::fake();
        [, $track] = $this->track();

        Livewire::test(TrackStems::class, ['trackId' => $track->id])->assertDontSee('Analyse stems')->assertSee('Not analysed')->call('analyse')->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_the_admin_requests_stems_with_estimate_and_ram_and_sees_the_states(): void
    {
        Queue::fake();
        [$file, $track] = $this->track();
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(TrackStems::class, ['trackId' => $track->id])
            ->assertSee('Analyse stems (estimate: about 55 s, needs about 1.5 GB RAM)')
            ->call('analyse')
            ->assertSeeHtml('data-test="stems-queued"')->assertSeeHtml('wire:poll.3s');
        Queue::assertPushedOn('stems', AnalyzeTrackDeep::class, fn ($job) => $job->kind === 'stems');

        Cache::forget(DeepAnalysis::marker('queued', $file, 'stems'));
        Cache::put(DeepAnalysis::marker('running', $file, 'stems'), true, 60);
        $component->call('$refresh')->assertSeeHtml('data-test="stems-running"');
    }

    public function test_a_long_estimate_is_shown_in_minutes(): void
    {
        [, $track] = $this->track();
        $track->update(['duration' => 600]);
        $this->actingAs(User::factory()->create());

        Livewire::test(TrackStems::class, ['trackId' => $track->id])->assertSee('about 5 min');
    }

    public function test_a_failed_run_shows_why_and_offers_a_retry(): void
    {
        [$file, $track] = $this->track();
        $this->actingAs(User::factory()->create());
        FileAnalysis::create(['file_id' => $file->id, 'content_hash' => 'h1', 'errors' => ['stems' => ['version' => '1', 'message' => 'Too long for the stems service', 'at' => now()->toIso8601String()]]]);

        Livewire::test(TrackStems::class, ['trackId' => $track->id])->assertSee('Too long for the stems service')->assertSee('Try again');
    }

    public function test_the_admin_gets_a_hint_while_the_service_is_not_set_up_and_a_guest_does_not(): void
    {
        [, $track] = $this->track();
        config(['analysis.stems_url' => null]);

        Livewire::test(TrackStems::class, ['trackId' => $track->id])->assertDontSee('STEMS_URL')->assertSee('Not analysed');

        $this->actingAs(User::factory()->create());
        Livewire::test(TrackStems::class, ['trackId' => $track->id])->assertSeeHtml('data-test="stems-hint"')->assertSee('STEMS_URL')->assertDontSee('Analyse stems');
    }

    public function test_the_admin_hint_also_shows_when_the_service_is_unhealthy(): void
    {
        [, $track] = $this->track();
        config(['analysis.stems_url' => 'http://stems-down:8092']);
        Http::fake(['stems-down:8092/health' => Http::response('', 503)]);
        $this->actingAs(User::factory()->create());

        Livewire::test(TrackStems::class, ['trackId' => $track->id])->assertSeeHtml('data-test="stems-hint"');
    }

    public function test_the_album_page_has_the_stems_block_in_the_track_details(): void
    {
        \App\Models\Setting::set(\App\Domain\Library\LeadingSource::SETTING, 'all');
        [$file, $track] = $this->track(withStems: true);
        $track->update(['source' => 'files']);
        $file->analysis()->first()->update(['waveform' => [10, 50, 100, 50, 10]]);

        $this->get(route('albums.show', $track->album_id))->assertOk()
            ->assertSee('Stems')->assertSee('track-stems', false)->assertSee('track-sections', false);
    }
}
