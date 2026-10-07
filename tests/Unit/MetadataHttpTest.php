<?php

namespace Tests\Unit;

use App\Services\MetadataHttp;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetadataHttpTest extends TestCase
{
    public function test_every_request_carries_the_configured_user_agent_and_extra_headers(): void
    {
        config(['metadata.user_agent' => 'Test/1.0 (me@example.test)']);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->assertTrue(MetadataHttp::get('https://example.test/x', ['a' => 1], ['Accept' => 'application/json'])->ok());

        Http::assertSent(fn (Request $r) => $r->hasHeader('User-Agent', 'Test/1.0 (me@example.test)')
            && $r->hasHeader('Accept', 'application/json') && str_contains($r->url(), 'a=1'));
    }

    public function test_a_rate_limit_or_outage_throws_so_the_job_is_retried(): void
    {
        Http::fake(['rate.test/*' => Http::response('', 429), 'down.test/*' => Http::response('', 503)]);

        foreach (['https://rate.test/x', 'https://down.test/x'] as $url) {
            try {
                MetadataHttp::get($url);
                $this->fail("{$url} should have thrown");
            } catch (RequestException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_other_failures_are_returned_for_the_caller_to_read(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->assertSame(404, MetadataHttp::get('https://example.test/x')->status());
    }
}
