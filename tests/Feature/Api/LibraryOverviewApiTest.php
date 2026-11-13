<?php

namespace Tests\Feature\Api;

use App\Domain\Library\LeadingSource;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** GET /api/library/overview (docs/api/overview.md). */
class LibraryOverviewApiTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Setting::set(LeadingSource::SETTING, 'all');
    }

    public function test_it_returns_glance_sound_formats_and_analysis_progress(): void
    {
        config(['analysis.enabled' => true]);
        $artist = Artist::create(['name' => 'Band', 'source' => 'files']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'LP', 'source' => 'files', 'released_at' => '1977-05-01']);
        $this->makeAnalysedTrack($album, 'Slow', ['bpm' => 70, 'mood_tags' => [['name' => 'relaxed', 'score' => 0.9]], 'loudness_lufs' => -10], ['codec' => 'flac', 'bit_depth' => 16, 'sample_rate' => 44100]);
        $this->makeAnalysedTrack($album, 'Fast', ['bpm' => 140], ['codec' => 'flac', 'bit_depth' => 24, 'sample_rate' => 96000]);

        $r = $this->getJson('/api/library/overview')->assertOk()->json();

        $this->assertSame(['artists' => 1, 'albums' => 1, 'tracks' => 2, 'hours' => 0.1], $r['totals']);
        $this->assertSame([['decade' => 1970, 'albums' => 1]], $r['decades']);
        $this->assertSame(['covers', 'genres', 'release_details', 'analysed'], array_column($r['coverage'], 'key'));
        $analysed = collect($r['coverage'])->firstWhere('key', 'analysed');
        $this->assertSame(['have' => 1, 'of' => 2], ['have' => $analysed['have'], 'of' => $analysed['of']]);
        $this->assertSame([['name' => 'relaxed', 'albums' => 1]], $r['sound']['moods']);
        $this->assertSame(['slow' => 1, 'steady' => 0, 'fast' => 1], collect($r['sound']['tempos'])->pluck('albums', 'key')->all());
        $this->assertNull($r['sound']['tempos'][2]['to']);
        $this->assertEqualsCanonicalizing([16, 24], array_column($r['formats'], 'bit_depth'));
        $this->assertSame('flac', $r['formats'][0]['codec']);

        $this->assertTrue($r['analysis']['enabled']);
        $this->assertSame(2, $r['analysis']['files']);
        $loudness = collect($r['analysis']['stages'])->firstWhere('key', 'loudness');
        $this->assertSame(2, $loudness['total']);
        $this->assertSame(['done', 'pending', 'failed', 'gave_up'], array_keys(array_intersect_key($loudness, array_flip(['done', 'pending', 'failed', 'gave_up']))));
    }

    public function test_an_empty_library_gives_zeros_and_standard_has_no_analysis_block(): void
    {
        config(['profile.current' => 'standard']);

        $r = $this->getJson('/api/library/overview')->assertOk()->json();

        $this->assertSame(0, $r['totals']['tracks']);
        $this->assertSame([], $r['formats']);
        $this->assertNull($r['analysis']);
    }

    public function test_control_profile_refuses_it(): void
    {
        config(['profile.current' => 'control']);

        $this->getJson('/api/library/overview')->assertStatus(422)->assertJsonPath('error', 'not_available_in_profile');
    }
}
