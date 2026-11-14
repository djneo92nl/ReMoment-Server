<?php

namespace Tests\Feature\Alerts;

use App\Domain\Alerts\AlertChecks;
use App\Domain\Alerts\Alerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class NetworkExposureAlertTest extends TestCase
{
    use RefreshDatabase;

    private function active(): ?\App\Models\Alert
    {
        return Alerts::active()->firstWhere('kind', 'network.exposed');
    }

    public function test_private_request_raises_nothing(): void
    {
        config(['app.url' => 'http://remoment.local']);

        $this->withHeaders(['Host' => '192.168.1.5'])->getJson('/api/info')->assertOk();

        $this->assertNull($this->active());
    }

    public function test_public_request_raises_once_with_docs_pointer_and_never_blocks(): void
    {
        config(['app.url' => 'http://remoment.local']);

        $this->withHeaders(['X-Forwarded-Host' => 'remoment.example.com', 'X-Forwarded-Proto' => 'http'])->getJson('/api/info')->assertOk();
        $this->getJson('/api/info', ['X-Forwarded-Host' => 'remoment.example.com'])->assertOk();

        $this->assertSame(1, Alerts::active()->where('kind', 'network.exposed')->count());
        $alert = $this->active();
        $this->assertSame('warning', $alert->severity);
        $this->assertStringContainsString('docs/user/reverse-proxy.md', $alert->message);
        $this->assertStringContainsString('plain HTTP', $alert->message);
    }

    public function test_scheduled_check_keeps_it_while_seen_then_resolves(): void
    {
        config(['app.url' => 'http://remoment.local']);
        $this->getJson('/api/info', ['X-Forwarded-Host' => 'remoment.example.com'])->assertOk();

        app(AlertChecks::class)->exposure();
        $this->assertNotNull($this->active());

        Cache::flush();   // an hour without a public request
        app(AlertChecks::class)->exposure();
        $this->assertNull($this->active());
    }

    public function test_public_app_url_raises_from_the_scheduled_check(): void
    {
        config(['app.url' => 'http://remoment.example.com']);
        app(AlertChecks::class)->exposure();
        $this->assertNotNull($this->active());

        config(['app.url' => 'http://remoment.lan']);
        app(AlertChecks::class)->exposure();
        $this->assertNull($this->active());
    }
}
