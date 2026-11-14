<?php

namespace Tests\Feature\Mcp;

use App\Domain\Library\LeadingSource;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** MCP tools for the analytics reads: overview, health, stats and the sound filters of browse_library (docs/api/mcp.md). They reuse the REST API through ApiBridge. */
class AnalyticsMcpTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set(LeadingSource::SETTING, 'all');
        Cache::flush();
    }

    private function tracks(): void
    {
        $album = Album::create(['artist_id' => Artist::create(['name' => 'Band', 'source' => 'files'])->id, 'name' => 'LP', 'source' => 'files']);
        $this->makeAnalysedTrack($album, 'Calm', ['bpm' => 80, 'key' => 'A', 'mode' => 'minor', 'arousal' => 0.35]);
        $this->makeAnalysedTrack($album, 'Drive', ['bpm' => 120, 'key' => 'C', 'mode' => 'major', 'arousal' => 0.65]);
    }

    public function test_the_analytics_tools_are_open_and_not_admin_only(): void
    {
        foreach ([Tools\LibraryOverview::class, Tools\LibraryHealth::class, Tools\ListeningStats::class] as $tool) {
            ReMomentServer::tool($tool, [])->assertOk();
        }
    }

    public function test_overview_returns_the_rest_payload(): void
    {
        $this->tracks();

        $rest = $this->getJson('/api/library/overview')->assertOk()->json();
        $tool = ReMomentServer::tool(Tools\LibraryOverview::class, []);

        $tool->assertOk()->assertSee('"tracks":'.$rest['totals']['tracks']);
    }

    public function test_stats_returns_the_rest_payload_and_validates_dates(): void
    {
        $rest = $this->getJson('/api/library/stats?limit=3')->assertOk()->json();
        $this->assertArrayHasKey('totals', $rest);

        ReMomentServer::tool(Tools\ListeningStats::class, ['limit' => 3])->assertOk()->assertSee('totals');
        ReMomentServer::tool(Tools\ListeningStats::class, ['from' => '2026-02-01', 'to' => '2026-01-01'])->assertHasErrors(['422']);
        ReMomentServer::tool(Tools\ListeningStats::class, ['from' => 'nonsense'])->assertHasErrors();
    }

    public function test_health_lists_counts_and_one_report(): void
    {
        config(['profile.current' => 'full']);

        ReMomentServer::tool(Tools\LibraryHealth::class, [])->assertOk()->assertSee(['transcodes', 'duplicates']);
        ReMomentServer::tool(Tools\LibraryHealth::class, ['report' => 'transcodes'])->assertOk()->assertSee(['"key":"transcodes"', '"data":[]']);
        ReMomentServer::tool(Tools\LibraryHealth::class, ['report' => 'nope'])->assertHasErrors();
    }

    public function test_profile_gates_come_through_the_bridge(): void
    {
        config(['profile.current' => 'control']);
        ReMomentServer::tool(Tools\LibraryOverview::class, [])->assertHasErrors(['not_available_in_profile']);
        ReMomentServer::tool(Tools\LibraryHealth::class, ['report' => 'transcodes'])->assertHasErrors(['not_available_in_profile']);
        ReMomentServer::tool(Tools\ListeningStats::class, [])->assertOk();

        config(['profile.current' => 'standard']);
        ReMomentServer::tool(Tools\LibraryOverview::class, [])->assertOk();
        ReMomentServer::tool(Tools\LibraryHealth::class, ['report' => 'transcodes'])->assertHasErrors(['not_available_in_profile']);
    }

    public function test_browse_tracks_takes_the_sound_filters(): void
    {
        $this->tracks();

        ReMomentServer::tool(Tools\BrowseLibrary::class, ['kind' => 'tracks', 'bpm_min' => 100])->assertOk()->assertSee('Drive')->assertDontSee('Calm');
        ReMomentServer::tool(Tools\BrowseLibrary::class, ['kind' => 'tracks', 'camelot' => '8A'])->assertOk()->assertSee('Calm')->assertDontSee('Drive');
        ReMomentServer::tool(Tools\BrowseLibrary::class, ['kind' => 'tracks', 'count_only' => true, 'arousal_max' => 0.5])->assertOk()->assertSee('"total":1');
        ReMomentServer::tool(Tools\BrowseLibrary::class, ['kind' => 'tracks', 'include' => 'analysis', 'sort' => 'bpm'])->assertOk()->assertSee('"analysis"');
        ReMomentServer::tool(Tools\BrowseLibrary::class, ['kind' => 'tracks', 'arousal_max' => 2])->assertHasErrors();
    }
}
