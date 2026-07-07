<?php

namespace Djneo92nl\BeoMozart\Tests\Api;

use Djneo92nl\BeoMozart\Api\PlaybackApi;
use Djneo92nl\BeoMozart\Enums\PlaybackCommand;
use Djneo92nl\BeoMozart\Tests\FakeHttpTransport;
use PHPUnit\Framework\TestCase;

class PlaybackApiTest extends TestCase
{
    public function test_send_command_hits_command_endpoint(): void
    {
        $transport = new FakeHttpTransport;
        (new PlaybackApi($transport))->sendCommand(PlaybackCommand::Skip);

        $this->assertSame([
            'method' => 'POST',
            'path' => '/api/v1/playback/command/skip',
            'body' => [],
            'query' => [],
        ], $transport->lastCall());
    }

    public function test_seek_uses_query_param_and_no_body(): void
    {
        $transport = new FakeHttpTransport;
        (new PlaybackApi($transport))->seek(1500);

        $this->assertSame([
            'method' => 'PUT',
            'path' => '/api/v1/playback/seek',
            'body' => [],
            'query' => ['position_ms' => 1500],
        ], $transport->lastCall());
    }

    public function test_play_uri_posts_location(): void
    {
        $transport = new FakeHttpTransport;
        (new PlaybackApi($transport))->playUri('http://example.test/track.mp3');

        $this->assertSame([
            'method' => 'POST',
            'path' => '/api/v1/playback/uri',
            'body' => ['location' => 'http://example.test/track.mp3'],
            'query' => [],
        ], $transport->lastCall());
    }

    public function test_enqueue_builds_nested_provider_body(): void
    {
        $transport = new FakeHttpTransport;
        (new PlaybackApi($transport))->enqueue('track', 'radio', '12345', startNowFromPosition: 0);

        $this->assertSame([
            'method' => 'POST',
            'path' => '/api/v1/playback/queue',
            'body' => [
                'type' => 'track',
                'provider' => ['value' => 'radio'],
                'uri' => '12345',
                'startNowFromPosition' => 0,
            ],
            'query' => [],
        ], $transport->lastCall());
    }

    public function test_enqueue_omits_start_now_from_position_when_null(): void
    {
        $transport = new FakeHttpTransport;
        (new PlaybackApi($transport))->enqueue('track', 'dlna', 'http://example.test/track.mp3');

        $this->assertArrayNotHasKey('startNowFromPosition', $transport->lastCall()['body']);
    }

    public function test_get_state_hits_playback_state(): void
    {
        $transport = new FakeHttpTransport;
        $transport->response = ['state' => ['value' => 'started']];

        $state = (new PlaybackApi($transport))->getState();

        $this->assertSame('GET', $transport->lastCall()['method']);
        $this->assertSame('/api/v1/playback/state', $transport->lastCall()['path']);
        $this->assertSame(['state' => ['value' => 'started']], $state);
    }
}
