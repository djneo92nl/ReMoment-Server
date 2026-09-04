<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\LastfmSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LastfmSessionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lastfm.api_key' => 'test-key', 'lastfm.shared_secret' => 'test-secret']);
    }

    public function test_signed_request_returns_decoded_json_on_success(): void
    {
        Http::fake([
            'ws.audioscrobbler.com/*' => Http::response(['results' => ['ok' => true]], 200),
        ]);

        $data = (new LastfmSessionService)->signedRequest(['method' => 'track.scrobble']);

        $this->assertSame(['results' => ['ok' => true]], $data);
    }

    public function test_signed_request_throws_on_http_failure(): void
    {
        Http::fake([
            'ws.audioscrobbler.com/*' => Http::response('Service Unavailable', 503),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 503');

        (new LastfmSessionService)->signedRequest(['method' => 'track.scrobble']);
    }

    public function test_signed_request_throws_on_lastfm_api_error_despite_http_200(): void
    {
        Http::fake([
            'ws.audioscrobbler.com/*' => Http::response(['error' => 9, 'message' => 'Invalid session key'], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid session key');

        (new LastfmSessionService)->signedRequest(['method' => 'track.scrobble']);
    }

    public function test_handle_callback_stores_session_key_and_username(): void
    {
        Http::fake([
            'ws.audioscrobbler.com/*' => Http::response([
                'session' => ['key' => 'abc123', 'name' => 'testuser'],
            ], 200),
        ]);

        (new LastfmSessionService)->handleCallback('some-token');

        $this->assertSame('abc123', Setting::get('lastfm_session_key'));
        $this->assertSame('testuser', Setting::get('lastfm_username'));
    }

    public function test_handle_callback_throws_when_lastfm_api_call_fails(): void
    {
        Http::fake([
            'ws.audioscrobbler.com/*' => Http::response(['error' => 4, 'message' => 'Authentication failed'], 200),
        ]);

        $this->expectException(\RuntimeException::class);

        (new LastfmSessionService)->handleCallback('some-token');
    }
}
