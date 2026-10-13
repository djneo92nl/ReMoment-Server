<?php

namespace Tests\Feature;

use App\Domain\Analysis\Tools;
use App\Domain\Health\ExternalServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** The "Analysis" group of the external services health page. */
class AnalysisHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Tools::forget();
        Process::fake(fn (PendingProcess $process) => Process::result("tool version 1.0\n"));
    }

    protected function tearDown(): void
    {
        Tools::forget();

        parent::tearDown();
    }

    public function test_health_probes_are_disabled_while_analysis_is_off(): void
    {
        Cache::flush();
        config(['analysis.enabled' => false]);
        Http::fake(['*' => Http::response([], 200)]);

        $results = (new ExternalServices)->refresh();

        $this->assertSame('disabled', $results['ffmpeg']['status']);
        $this->assertSame('disabled', $results['acoustid']['status']);
        $this->assertSame('disabled', $results['essentia']['status']);
    }

    public function test_health_probes_report_tools_keys_and_the_sidecar(): void
    {
        Cache::flush();
        config(['analysis.enabled' => true, 'analysis.acoustid_key' => 'key', 'analysis.essentia_url' => 'http://analysis:8090', 'analysis.essentia_ml' => true]);
        Http::fake([
            'api.acoustid.org/*' => Http::response(['status' => 'error', 'error' => ['code' => 4, 'message' => 'invalid API key']]),
            'analysis:8090/health' => Http::response(['ok' => true, 'essentia' => '2.1', 'ml' => false]),
            '*' => Http::response([], 200),
        ]);

        $results = (new ExternalServices)->refresh();

        $this->assertSame('ok', $results['ffmpeg']['status']);
        $this->assertSame('ok', $results['fpcalc']['status']);
        $this->assertSame('error', $results['acoustid']['status']);
        $this->assertSame('warning', $results['essentia']['status'], 'the sidecar has no ML models but the app expects them');
    }
}
