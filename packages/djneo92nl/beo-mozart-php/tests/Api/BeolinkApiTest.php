<?php

namespace Djneo92nl\BeoMozart\Tests\Api;

use Djneo92nl\BeoMozart\Api\BeolinkApi;
use Djneo92nl\BeoMozart\Tests\FakeHttpTransport;
use PHPUnit\Framework\TestCase;

class BeolinkApiTest extends TestCase
{
    public function test_join_posts_to_jid_path(): void
    {
        $transport = new FakeHttpTransport;
        (new BeolinkApi($transport))->join('2714.1200304.26451293@products.bang-olufsen.com');

        $this->assertSame([
            'method' => 'POST',
            'path' => '/api/v1/beolink/join/2714.1200304.26451293@products.bang-olufsen.com',
            'body' => [],
            'query' => [],
        ], $transport->lastCall());
    }

    public function test_join_with_source_adds_query_param(): void
    {
        $transport = new FakeHttpTransport;
        (new BeolinkApi($transport))->join('jid-1', 'MUSIC');

        $this->assertSame(['source' => 'MUSIC'], $transport->lastCall()['query']);
    }

    public function test_leave_posts_no_body(): void
    {
        $transport = new FakeHttpTransport;
        (new BeolinkApi($transport))->leave();

        $this->assertSame([
            'method' => 'POST',
            'path' => '/api/v1/beolink/leave',
            'body' => [],
            'query' => [],
        ], $transport->lastCall());
    }

    public function test_listeners_defaults_to_empty_array(): void
    {
        $transport = new FakeHttpTransport;
        $transport->response = null;

        $this->assertSame([], (new BeolinkApi($transport))->listeners());
        $this->assertSame('/api/v1/beolink/listeners', $transport->lastCall()['path']);
    }
}
