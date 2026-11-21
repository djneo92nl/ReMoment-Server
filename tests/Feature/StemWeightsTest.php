<?php

namespace Tests\Feature;

use App\Domain\Analysis\DeepAnalysis;
use App\Domain\Analysis\StemWeights;
use App\Domain\Health\ExternalServices;
use App\Livewire\StemWeightsCard;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools as McpTools;
use App\Models\AdminToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The opt-in download of the Demucs weights (docs/api/stems.md, "Weights"): state endpoint, admin trigger, the kinds
 * of GET /api/info that wait for it, the MCP actions and the card on /settings/analysis. The stems service is faked.
 */
class StemWeightsTest extends TestCase
{
    use RefreshDatabase;

    private const MISSING = ['state' => 'missing', 'model' => 'htdemucs', 'file' => '955717e8-8726e21a.th', 'size' => null, 'downloaded' => 0, 'total' => 84141911, 'source' => 'https://dl.fbaipublicfiles.com/demucs/hybrid_transformer/955717e8-8726e21a.th', 'sha256' => '8726e21a993978c7ba086d3872e7608d7d5bfca646ca4aca459ffda844faa8b4', 'error' => null];

    private string $token;

    /** @var array the fake service's answer to GET /weights */
    private array $weights = self::MISSING;

    /** @var array the fake service's answer to /health */
    private array $health = ['ok' => false, 'version' => 1, 'method' => 'htdemucs', 'weights' => 'missing', 'loading' => false, 'error' => null];

    /** @var null|'down'|int the service does not answer (`down`) or answers /weights with this HTTP status */
    private $weightsFail = null;

    /** @var list<string> the service calls made, "METHOD path" */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        Http::fake([
            'stems:8092/health' => fn () => Http::response($this->health),
            'stems:8092/weights' => function (Request $request) {
                $this->calls[] = $request->method().' /weights';
                if ($this->weightsFail === 'down') {
                    throw new \Illuminate\Http\Client\ConnectionException('down');
                }
                if (is_int($this->weightsFail)) {
                    return Http::response(['detail' => 'Not Found'], $this->weightsFail);
                }
                if ($request->method() === 'POST' && $this->weights['state'] === 'missing') {
                    $this->weights = ['state' => 'downloading', 'downloaded' => 0] + $this->weights;
                }

                return Http::response($this->weights, $request->method() === 'POST' && $this->weights['state'] !== 'ready' ? 202 : 200);
            },
        ]);
        \App\Domain\Analysis\Tools::forget();
        \Illuminate\Support\Facades\Process::fake(['*' => \Illuminate\Support\Facades\Process::result("ffmpeg version 7\n")]);
        config(['analysis.enabled' => true, 'analysis.stems_url' => 'http://stems:8092', 'analysis.structure_url' => null, 'analysis.essentia_url' => null]);

        User::factory()->create();
        [, $this->token] = AdminToken::issue('test');
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function ready(): void
    {
        $this->weights = ['state' => 'ready', 'size' => 84141911, 'downloaded' => 84141911] + self::MISSING;
        $this->health = ['ok' => true, 'version' => 1, 'method' => 'htdemucs', 'weights' => 'ready', 'loading' => false, 'error' => null];
    }

    // --- GET /api/stems/weights (open) -------------------------------------------------

    public function test_the_state_is_open_and_says_missing_with_the_pinned_source_and_the_licence_note(): void
    {
        $this->getJson('/api/stems/weights')
            ->assertOk()
            ->assertJsonPath('state', 'missing')
            ->assertJsonPath('size', null)
            ->assertJsonPath('percent', null)
            ->assertJsonPath('total', 84141911)
            ->assertJsonPath('sha256', self::MISSING['sha256'])
            ->assertJsonPath('source', self::MISSING['source'])
            ->assertJsonPath('licence.url', config('analysis.stems_weights_licence_url'))
            ->assertJsonPath('licence.note', config('analysis.stems_weights_licence_note'));
    }

    public function test_downloading_reports_progress_as_a_percentage(): void
    {
        $this->weights = ['state' => 'downloading', 'downloaded' => 42070956] + self::MISSING;

        $this->getJson('/api/stems/weights')->assertOk()->assertJsonPath('state', 'downloading')->assertJsonPath('downloaded', 42070956)->assertJsonPath('percent', 50);
    }

    public function test_ready_has_the_size_and_failed_the_reason(): void
    {
        $this->ready();
        $this->getJson('/api/stems/weights')->assertOk()->assertJsonPath('state', 'ready')->assertJsonPath('size', 84141911)->assertJsonPath('percent', 100)->assertJsonPath('error', null);

        $this->weights = ['state' => 'failed', 'error' => 'the downloaded file does not match the pinned SHA-256 and was thrown away'] + self::MISSING;
        $this->getJson('/api/stems/weights')->assertOk()->assertJsonPath('state', 'failed')->assertJsonPath('error', 'the downloaded file does not match the pinned SHA-256 and was thrown away');
    }

    public function test_without_a_stems_service_it_is_409_and_an_unreachable_one_503(): void
    {
        config(['analysis.stems_url' => null]);
        $this->getJson('/api/stems/weights')->assertStatus(409)->assertJsonPath('error', 'not_available');

        config(['analysis.stems_url' => 'http://stems:8092']);
        $this->weightsFail = 'down';
        $this->getJson('/api/stems/weights')->assertStatus(503)->assertJsonPath('error', 'unreachable');
    }

    public function test_an_older_service_image_without_the_endpoint_is_502_with_a_hint(): void
    {
        $this->weightsFail = 404;

        $this->getJson('/api/stems/weights')->assertStatus(502)->assertJsonPath('error', 'driver_error')->assertJsonPath('message', fn ($m) => str_contains($m, 'rebuild'));
    }

    // --- POST /api/admin/stems/weights (admin) -----------------------------------------

    public function test_starting_the_download_needs_the_admin_token(): void
    {
        $this->postJson('/api/admin/stems/weights')->assertStatus(401);

        $this->assertSame([], $this->calls);
    }

    public function test_the_admin_starts_the_download_and_the_service_is_asked_once(): void
    {
        $this->postJson('/api/admin/stems/weights', [], $this->auth())
            ->assertStatus(202)
            ->assertJsonPath('state', 'downloading')
            ->assertJsonPath('licence.url', config('analysis.stems_weights_licence_url'));

        $this->assertSame(['POST /weights'], $this->calls);
        // Again while it runs: still 202, the service decides it is a no-op.
        $this->postJson('/api/admin/stems/weights', [], $this->auth())->assertStatus(202)->assertJsonPath('state', 'downloading');
    }

    public function test_asking_when_ready_is_200(): void
    {
        $this->ready();

        $this->postJson('/api/admin/stems/weights', [], $this->auth())->assertOk()->assertJsonPath('state', 'ready')->assertJsonPath('percent', 100);
    }

    public function test_the_routes_belong_to_the_full_profile(): void
    {
        config(['profile.current' => 'standard']);

        $this->getJson('/api/stems/weights')->assertStatus(422)->assertJsonPath('error', 'not_available_in_profile');
        $this->postJson('/api/admin/stems/weights', [], $this->auth())->assertStatus(422)->assertJsonPath('error', 'not_available_in_profile');
        $this->assertSame([], $this->calls);
    }

    // --- the kinds of GET /api/info ------------------------------------------------------

    public function test_stems_is_listed_only_when_the_weights_are_ready(): void
    {
        // The service is up, the weights are not there: it is not ok and nothing is offered.
        $this->getJson('/api/info')->assertJsonPath('features.deep_analysis', false)->assertJsonMissingPath('features.deep_analysis_kinds')
            ->assertJsonPath('features.stems_weights', 'missing');
        $this->assertFalse(DeepAnalysis::available('stems'));

        $this->ready();
        // The health was remembered for a minute; asking for the download forgets it, so the kinds show up at once.
        $this->postJson('/api/admin/stems/weights', [], $this->auth())->assertOk();

        $this->getJson('/api/info')->assertJsonPath('features.deep_analysis', true)->assertJsonPath('features.deep_analysis_kinds', ['stems', 'stem_audio'])
            ->assertJsonPath('features.stems_weights', 'ready');
    }

    public function test_a_service_that_says_ok_with_weights_not_ready_is_not_available(): void
    {
        $this->health = ['ok' => true, 'weights' => 'downloading'];

        $this->assertFalse(DeepAnalysis::available('stems'));
        $this->assertFalse(DeepAnalysis::available('stem_audio'));
    }

    public function test_a_service_without_a_weights_field_still_counts_as_ready(): void
    {
        $this->health = ['ok' => true, 'version' => 1, 'method' => 'htdemucs'];

        $this->assertTrue(DeepAnalysis::available('stems'));
    }

    public function test_the_domain_class_knows_when_it_is_ready(): void
    {
        $this->assertFalse(StemWeights::ready());
        $this->ready();
        $this->assertTrue(StemWeights::ready());
    }

    // --- Settings > Services & health probe ----------------------------------------------

    public function test_the_health_probe_tells_a_missing_download_from_a_broken_model(): void
    {
        $probe = fn () => (new ExternalServices)->refresh(['stems'])['stems'];

        $result = $probe();
        $this->assertSame('warning', $result['status']);
        $this->assertStringContainsString('not downloaded', $result['message']);

        $this->health['weights'] = 'failed';
        $this->assertSame('error', $probe()['status']);

        $this->health = ['ok' => false, 'weights' => 'ready', 'loading' => false, 'error' => 'out of memory'];
        $this->assertSame('error', $probe()['status']);
    }

    // --- MCP -------------------------------------------------------------------------------

    public function test_the_mcp_tool_shows_the_state_and_starts_the_download_with_the_admin_token(): void
    {
        ReMomentServer::tool(McpTools\DeepAnalysis::class, ['action' => 'weights'])->assertOk()->assertSee('missing')->assertSee('licence');

        // Without the admin token the download is refused as the REST API refuses it.
        ReMomentServer::tool(McpTools\DeepAnalysis::class, ['action' => 'download_weights'])->assertHasErrors();
        $this->assertSame(['GET /weights'], $this->calls);

        request()->headers->set('Authorization', 'Bearer '.$this->token);
        ReMomentServer::tool(McpTools\DeepAnalysis::class, ['action' => 'download_weights'])->assertOk()->assertSee('downloading');
        $this->assertSame(['GET /weights', 'POST /weights'], $this->calls);
    }

    public function test_the_other_mcp_actions_still_need_a_track(): void
    {
        ReMomentServer::tool(McpTools\DeepAnalysis::class, ['action' => 'status'])->assertHasErrors();
    }

    // --- the card on /settings/analysis -------------------------------------------------

    public function test_the_card_shows_the_licence_note_and_a_button_while_missing(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(StemWeightsCard::class)
            ->assertSeeHtml('data-test="weights-licence"')
            ->assertSee(config('analysis.stems_weights_licence_note'))
            ->assertSee('not downloaded')
            ->assertSeeHtml('data-test="weights-download"');
    }

    public function test_the_button_starts_the_download_and_the_card_then_shows_the_progress(): void
    {
        $this->actingAs(User::factory()->create());

        $card = Livewire::test(StemWeightsCard::class)->call('download')->assertSee('The download started.');
        $this->assertSame(1, count(array_keys($this->calls, 'POST /weights', true)));

        $this->weights = ['state' => 'downloading', 'downloaded' => 21035478] + self::MISSING;
        $card->call('$refresh')->assertSeeHtml('data-test="weights-progress"')->assertSee('25%')->assertDontSeeHtml('data-test="weights-download"');
    }

    public function test_a_ready_card_has_no_button(): void
    {
        $this->actingAs(User::factory()->create());
        $this->ready();

        Livewire::test(StemWeightsCard::class)->assertSee('ready')->assertSeeHtml('data-test="weights-size"')->assertDontSeeHtml('data-test="weights-download"');
    }

    public function test_a_failed_card_shows_the_reason_and_offers_to_try_again(): void
    {
        $this->actingAs(User::factory()->create());
        $this->weights = ['state' => 'failed', 'error' => 'the download stopped at 100 of 200 bytes'] + self::MISSING;

        Livewire::test(StemWeightsCard::class)->assertSee('the download stopped at 100 of 200 bytes')->assertSee('Try again');
    }

    public function test_the_download_action_needs_the_admin_login(): void
    {
        Livewire::test(StemWeightsCard::class)->call('download')->assertForbidden();

        $this->assertNotContains('POST /weights', $this->calls);
    }

    public function test_the_card_is_empty_without_a_stems_service_and_says_so_when_it_does_not_answer(): void
    {
        $this->actingAs(User::factory()->create());

        config(['analysis.stems_url' => null]);
        Livewire::test(StemWeightsCard::class)->assertDontSeeHtml('data-test="weights-licence"');

        config(['analysis.stems_url' => 'http://stems:8092']);
        $this->weightsFail = 'down';
        Livewire::test(StemWeightsCard::class)->assertSeeHtml('data-test="weights-problem"')->assertSee('not reachable');
    }

    public function test_the_analysis_page_carries_the_card(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/settings/analysis')->assertOk()->assertSee('Stem separation weights')->assertSee('Download the weights');
    }
}
